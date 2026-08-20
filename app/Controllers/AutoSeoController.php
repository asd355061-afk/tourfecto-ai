<?php

/**
 * Tourfecto - Auto SEO Controller
 * ربط المواقع الخارجية بالمنصة + تشغيل التنفيذ التلقائي (Auto-Pilot) عليها
 * + سجل التغييرات والـRollback + تقديم embed.js العام.
 *
 * بيكمّل على WebsiteOptimizerController: الأخير بيعمل التدقيق ويطلّع
 * wo_audit_findings + wo_fixes، وده بياخد النتايج دي وينفّذها فعليًا.
 *
 * @version 1.0.0
 */
class AutoSeoController extends Controller
{
    /** @var AutoSeoEmbedService */
    private $embed;

    /** @var SubscriptionValidator */
    private $subscription;

    public function __construct()
    {
        parent::__construct();
        $this->embed = new AutoSeoEmbedService($this->db);
        $this->subscription = new SubscriptionValidator();
    }

    /** POST /api/auto-seo/connect  { website_id, method } */
    public function connect(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $method = (string) $this->get('method', 'script');

        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }
        if (!in_array($method, ['script', 'api', 'wordpress', 'shopify'], true)) {
            return $this->error('طريقة ربط غير مدعومة', 422);
        }
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $result = $this->embed->connectWebsite((int) $this->user['id'], $websiteId, $method);
        $this->log('Auto SEO Website Connected', ['website_id' => $websiteId, 'method' => $method]);

        return $this->success([
            'embed_token'   => $result['embed_token'],
            'api_key'       => $result['api_key'],
            'embed_code'    => $result['embed_code'],
            'install_steps' => [
                'انسخ كود الـEmbed',
                'حطّه قبل </head> في كل صفحات موقعك (أو في القالب الرئيسي)',
                'لو WordPress: استخدم wp_head hook أو إضافة Insert Headers',
                'شغّل تدقيق من Website Optimizer - الإصلاحات هتتطبق تلقائيًا',
            ],
        ], 'تم ربط الموقع بنجاح');
    }

    /** DELETE /api/auto-seo/connect?website_id=X */
    public function disconnect(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $this->embed->disconnectWebsite((int) $this->user['id'], $websiteId);
        $this->log('Auto SEO Website Disconnected', ['website_id' => $websiteId]);

        return $this->success(['website_id' => $websiteId], 'تم فصل الموقع وإيقاف الحقن');
    }

    /** POST /api/auto-seo/mode  { website_id, mode } */
    public function setMode(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $mode = (string) $this->get('mode', 'conservative');

        if (!in_array($mode, ['off', 'conservative', 'balanced', 'aggressive'], true)) {
            return $this->error('وضع غير صالح', 422);
        }
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $this->db->exec(
            "UPDATE websites SET auto_pilot_mode = ?, auto_fix_enabled = ?, last_sync_at = NOW() WHERE id = ?",
            [$mode, $mode === 'off' ? 0 : 1, $websiteId]
        );

        return $this->success(['website_id' => $websiteId, 'mode' => $mode], 'تم تحديث وضع Auto-Pilot');
    }

    /**
     * POST /api/auto-seo/apply  { website_id, finding_id? }
     * من غير finding_id بيطبّق كل الإصلاحات المؤهلة من آخر تدقيق.
     */
    public function apply(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $findingId = (int) $this->get('finding_id', 0);

        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $site = $this->db->query("SELECT * FROM websites WHERE id = ? LIMIT 1", [$websiteId]);
        if (empty($site) || (int) $site[0]['is_connected'] !== 1) {
            return $this->error('الموقع غير مربوط - اربطه الأول عشان التنفيذ التلقائي يشتغل', 422);
        }

        $mode = $site[0]['auto_pilot_mode'] ?? 'conservative';

        $findings = $findingId
            ? $this->db->query(
                "SELECT f.*, a.id AS audit_id FROM wo_audit_findings f
                 INNER JOIN wo_audits a ON a.id = f.audit_id
                 WHERE f.id = ? AND a.website_id = ? LIMIT 1",
                [$findingId, $websiteId]
            )
            : $this->db->query(
                "SELECT f.*, a.id AS audit_id FROM wo_audit_findings f
                 INNER JOIN wo_audits a ON a.id = f.audit_id
                 WHERE a.website_id = ? AND a.status = 'completed'
                   AND f.status IN ('fail','warn')
                 ORDER BY a.completed_at DESC, FIELD(f.severity,'critical','high','medium','low')
                 LIMIT 30",
                [$websiteId]
            );

        if (empty($findings)) {
            return $this->error('مفيش نتائج تدقيق قابلة للتطبيق - شغّل تدقيق الأول', 404);
        }

        $applied = [];
        foreach ($findings as $finding) {
            // الضغط اليدوي بيتجاوز شرط الوضع، التلقائي لأ
            $isManual = $findingId > 0;
            if (!$isManual && !$this->embed->shouldAutoApply($finding, $mode)) {
                continue;
            }

            $res = $this->embed->applyFix(
                (int) $this->user['id'],
                $websiteId,
                $finding,
                $isManual ? 'manual_click' : 'audit_auto_pilot',
                $mode
            );

            if ($res['success']) {
                $applied[] = ['finding_id' => $finding['id'], 'field' => $finding['field_name'] ?? null, 'log_id' => $res['log_id']];
            }
        }

        $this->log('Auto SEO Fixes Applied', ['website_id' => $websiteId, 'count' => count($applied)]);

        return $this->success([
            'applied_count' => count($applied),
            'applied'       => $applied,
        ], count($applied) . ' إصلاحات اتطبقت فعليًا على موقعك');
    }

    /** GET /api/auto-seo/logs?website_id=X */
    public function logs(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $logs = $this->db->query(
            "SELECT * FROM auto_seo_change_log WHERE website_id = ? ORDER BY id DESC LIMIT 50",
            [$websiteId]
        );

        return $this->success(['logs' => $logs]);
    }

    /** POST /api/auto-seo/rollback/{id} */
    public function rollback(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $logId = (int) ($params['id'] ?? 0);
        if (!$logId) {
            return $this->error('معرف السجل مطلوب', 422);
        }

        $res = $this->embed->rollback((int) $this->user['id'], $logId);
        if (!$res['success']) {
            return $this->error($res['error'], 422);
        }

        $this->log('Auto SEO Rollback', ['log_id' => $logId]);

        return $this->success(['log_id' => $logId], 'تم التراجع - الحقن اتوقف فورًا');
    }

    /**
     * GET /embed.js?token=xxx  (عام - من غير AuthMiddleware)
     * ده اللي بيتحمّل على موقع العميل فعليًا.
     */
    public function embedScript(array $params = []): void
    {
        $token = (string) ($_GET['token'] ?? '');

        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        header('Access-Control-Allow-Origin: *');

        if ($token === '' || !preg_match('/^emb_[a-f0-9]{24}$/', $token)) {
            echo "// Tourfecto: invalid token\n";
            exit;
        }

        echo $this->embed->buildEmbedJavaScript($token);
        exit;
    }

    private function ownsWebsite(int $websiteId): bool
    {
        $rows = $this->db->query(
            "SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1",
            [$websiteId, $this->user['id']]
        );
        return !empty($rows);
    }
}
