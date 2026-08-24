<?php

/**
 * Tourfecto - Integrations Controller
 * إدارة التكاملات الخارجية (Slack, Zapier, HubSpot, Algolia, Calendly,
 * Zoom, Mixpanel, OneSignal...) من لوحة التحكم.
 *
 * الوصول: admin / super_admin فقط (المفاتيح أسرار حساسة). يوفر:
 *   1) GET  /api/integrations           - قائمة كل التكاملات + حالتها
 *   2) GET  /api/integrations/{key}     - حالة تكامل محدد (مفاتيح مقنّعة)
 *   3) POST /api/integrations/{key}/save  - حفظ مفاتيح تكامل معيّن
 *   4) POST /api/integrations/{key}/test  - اختبار اتصال فعلي بالخدمة
 *
 * الأمان: التحقق من الدور أولاً (401 للمستخدمين العاديين)، ولا تُرجَع
 * أي قيمة حساسة كاملة للواجهة أبدًا - المفاتيح بتتقنّع (mask) زي ما
 * SystemSettingsService بتعمل مع باقي إعدادات الأدمن.
 *
 * @version 1.0.0
 * @date 2026-08-24
 */
class IntegrationsController extends Controller
{
    /** التحقق من صلاحية الأدمن (admin/super_admin) */
    private function requireAdmin(): bool
    {
        if (!$this->isAuthenticated()) {
            return false;
        }
        if (!in_array($this->user['role'] ?? 'user', ['admin', 'super_admin'], true)) {
            return false;
        }
        return true;
    }

    /**
     * GET /api/integrations
     * كل التكاملات المسجّلة مع حالة كل واحد (configured / missing keys).
     * ممكن التصفية بـ ?category=ai|google|meta|payments|notifications|...
     */
    public function index(array $params = []): array
    {
        if (!$this->requireAdmin()) {
            return $this->error('غير مصرّح لك بالوصول', 403);
        }

        try {
            $registry = IntegrationManager::all();
            $category = $this->get('category', '');

            $integrations = [];
            foreach ($registry as $key => $meta) {
                if ($category !== '' && ($meta['category'] ?? '') !== $category) {
                    continue;
                }

                $configured = false;
                try {
                    $configured = IntegrationManager::isConfigured($key);
                } catch (Throwable $e) {
                    // تكامل فيه خطأ إعداد - نعرضه كـ not configured مع الملاحظة
                }

                $integrations[] = [
                    'key' => $key,
                    'label' => $meta['label'] ?? $key,
                    'category' => $meta['category'] ?? 'general',
                    'auth_type' => $meta['auth_type'] ?? 'api_key',
                    'configured' => $configured,
                    'env_keys' => array_map(fn ($k) => $this->maskKeyName($k), $meta['env_keys'] ?? []),
                ];
            }

            return $this->success([
                'integrations' => $integrations,
                'categories' => $this->categories($registry),
                'configured_count' => count(array_filter($integrations, fn ($i) => $i['configured'])),
                'total_count' => count($integrations),
            ]);
        } catch (Exception $e) {
            Logger::error('IntegrationsController::index failed', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحميل التكاملات', 500);
        }
    }

    /**
     * GET /api/integrations/{key}
     * حالة تكامل محدد + المفاتيح المطلوبة (أسماء فقط، بلا قيم).
     */
    public function status(array $params = []): array
    {
        if (!$this->requireAdmin()) {
            return $this->error('غير مصرّح لك بالوصول', 403);
        }

        $key = (string) ($params['key'] ?? '');
        $meta = IntegrationManager::meta($key);
        if ($meta === null) {
            return $this->error('التكامل غير موجود', 404);
        }

        $configured = false;
        try {
            $configured = IntegrationManager::isConfigured($key);
        } catch (Throwable $e) {
        }

        return $this->success([
            'key' => $key,
            'label' => $meta['label'] ?? $key,
            'category' => $meta['category'] ?? 'general',
            'auth_type' => $meta['auth_type'] ?? 'api_key',
            'configured' => $configured,
            'env_keys' => array_map(fn ($k) => $this->maskKeyName($k), $meta['env_keys'] ?? []),
        ]);
    }

    /**
     * POST /api/integrations/{key}/save
     * حفظ مفاتيح تكامل معيّن في system_settings (قيم حساسة بتتشفّر).
     * body: { "values": { "SLACK_BOT_TOKEN": "...", ... } }
     */
    public function save(array $params = []): array
    {
        if (!$this->requireAdmin()) {
            return $this->error('غير مصرّح لك بالوصول', 403);
        }

        $key = (string) ($params['key'] ?? '');
        $meta = IntegrationManager::meta($key);
        if ($meta === null) {
            return $this->error('التكامل غير موجود', 404);
        }

        $values = (array) $this->get('values', []);
        if (empty($values)) {
            return $this->error('مفيش مفاتيح للتحديث', 422);
        }

        $allowed = $meta['env_keys'] ?? [];
        $settings = new SystemSettingsService();
        $saved = 0;

        foreach ($values as $envKey => $value) {
            // نسمح فقط بالمفاتيح المصرّح بها لهذا التكامل
            if (!in_array($envKey, $allowed, true)) {
                continue;
            }
            $value = trim((string) $value);
            // سطر فارغ = محذوف (مش تخزين قيمة فاضية)
            if ($value === '') {
                continue;
            }

            $settingKey = $this->envToSettingKey($envKey);
            $settings->set($settingKey, $value);
            $saved++;
        }

        if ($saved === 0) {
            return $this->error('مافيش مفاتيح صالحة اتحفظت', 422);
        }

        return $this->success([
            'saved' => $saved,
            'configured' => IntegrationManager::isConfigured($key),
        ], 'تم حفظ إعدادات التكامل');
    }

    /**
     * POST /api/integrations/{key}/test
     * اختبار اتصال فعلي بالتكامل (بيروح للـ API الحقيقي ويشوف إيه الرد).
     */
    public function test(array $params = []): array
    {
        if (!$this->requireAdmin()) {
            return $this->error('غير مصرّح لك بالوصول', 403);
        }

        $key = (string) ($params['key'] ?? '');
        $meta = IntegrationManager::meta($key);
        if ($meta === null) {
            return $this->error('التكامل غير موجود', 404);
        }

        if (!IntegrationManager::isConfigured($key)) {
            return $this->error('التكامل مش مكتمل الإعداد - املأ المفاتيح الأول', 422);
        }

        try {
            $integration = IntegrationManager::get($key);
            $action = $this->get('action', 'test');
            $result = $integration->request($action, [], ['test' => true]);

            if (!empty($result['success'])) {
                return $this->success([
                    'ok' => true,
                    'http_code' => $result['http_code'] ?? null,
                ], 'الاتصال بالتكامل ناجح');
            }

            return $this->error('فشل الاتصال: ' . ($result['error'] ?? 'خطأ غير معروف'), 502);
        } catch (Throwable $e) {
            Logger::error('IntegrationsController::test failed', ['integration' => $key, 'message' => $e->getMessage()]);
            return $this->error('فشل الاتصال: ' . $e->getMessage(), 502);
        }
    }

    /** تجميع الفئات المتاحة للعرض */
    private function categories(array $registry): array
    {
        $cats = [];
        foreach ($registry as $meta) {
            $cat = $meta['category'] ?? 'general';
            if (!isset($cats[$cat])) {
                $cats[$cat] = ['key' => $cat, 'count' => 0];
            }
            $cats[$cat]['count']++;
        }
        return array_values($cats);
    }

    /** إخفاء آخر أحرف اسم مفتاح الـ env عشان ما يتعرضش كامل للواجهة */
    private function maskKeyName(string $envKey): string
    {
        $len = strlen($envKey);
        if ($len <= 4) {
            return $envKey;
        }
        return substr($envKey, 0, 3) . str_repeat('*', $len - 3);
    }

    /** تحويل اسم متغير env لاسم مفتاح setting (lowercase underscore) */
    private function envToSettingKey(string $envKey): string
    {
        return 'integration_' . strtolower($envKey);
    }
}
