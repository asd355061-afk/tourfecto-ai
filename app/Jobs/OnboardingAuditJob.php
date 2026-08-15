<?php
/**
 * Tourfecto - Onboarding Background Audit Job
 * @version 1.0.0
 *
 * Phase 19. بينفّذ خلف الكواليس كامل الـ Setup اللي بتعمله الـ Wizard بعد ما
 * العميل يدوس "ابدأ التحليل": تسجيل المنافسين في نظام Competitor Intelligence
 * (Phase 7) + تشغيل تدقيق SEO حقيقي (Website Optimizer - Phase 5) + محاولة
 * توليد خطة نمو 90 يوم (SEO Strategy Agent - Phase 14).
 *
 * ليه في الخلفية مش في نفس الـ HTTP request؟
 * --------------------------------------------------
 * الـ Audit بيجيب صفحة الموقع كاملة وبيكشف الروابط المكسورة - ممكن ياخد
 * 15-60 ثانية حسب حجم الموقع. في الـ wizard الأصلي (Phase 18) كان بيترشل
 * جوه نفس الـ request، فالعميل كان واقف على شاشة "بنجهّز حسابك" من غير أي
 * تحديث، وأي timeout من الـ webserver كان بيكسر الـ Onboarding بالكامل.
 * الموديول ده بيحط الشغل كله في job على الطابور (نفس نظام الـ Queue
 * المستخدم في Monitoring / GBP / Social) فالطلب بيرجع فورًا والواجهة بتعمل
 * polling على /api/onboarding/status لحد ما النتيجة تخلّص.
 *
 * Idempotent بأمان: المنافسين بيتسجلوا بس لو الدومين مش موجود أصلًا لنفس
 * الموقع (مفيش تكرار لو الـ job اتعاد محاولته)، وكل تشغيل بيعمل wo_audit
 * جديد طبيعي (زي ما أي تدقيق يدوي بيعمل).
 */
class OnboardingAuditJob implements QueueJobInterface {

    /**
     * أمان دفاعي: نفس نمط $optionalNewClassFiles في public_html/index.php.
     * الـ cron bootstrap بيلود app/Jobs/*.php تلقائيًا، بس الكلاسات دي ممكن
     * تكون مش مسجّلة في classmap بتاع composer (السيرفر مش بيعمل
     * dump-autoload)، فلازم تتحمّل هنا عشان الـ worker يقدر يناديها.
     * الفول تلقائي لو ملف مش موجود (class_exists بيحرس التنفيذ في runSetup).
     */
    private function loadDependencies(): void {
        $deps = [
            '/Controllers/OnboardingController.php',
            '/Controllers/WebsiteOptimizerController.php',
            '/Controllers/SeoStrategyController.php',
            '/Services/CompetitorIntelligence/CompetitorAnalysisService.php',
        ];
        foreach ($deps as $rel) {
            $file = APP_PATH . $rel;
            if (file_exists($file)) {
                require_once $file;
            }
        }
    }

    public function handle(array $payload): void {
        $this->loadDependencies();

        $userId = (int) ($payload['user_id'] ?? 0);
        $websiteId = (int) ($payload['website_id'] ?? 0);

        if ($userId <= 0 || $websiteId <= 0) {
            throw new InvalidArgumentException('OnboardingAuditJob: missing user_id/website_id');
        }

        $db = Database::getInstance();
        $userRows = $db->query("SELECT * FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (empty($userRows)) {
            // المستخدم اتحذف بعد ما اتجدولت المهمة - مش خطأ يستاهل Retry
            return;
        }

        // نفس الآلية اللي بيستخدمها AuthMiddleware: أي Controller بيتنشأ بعد
        // السطر ده هيلاقي مستخدم "مصادق" من $_SERVER['auth_user'] فعلًا.
        $_SERVER['auth_user_id'] = $userId;
        $_SERVER['auth_user'] = $userRows[0];

        $controller = new OnboardingController();
        $result = $controller->runSetup([
            'user_id' => $userId,
            'website_id' => $websiteId,
            'competitors' => $payload['competitors'] ?? [],
        ]);

        if (class_exists('Logger')) {
            Logger::info('Onboarding background setup finished', [
                'website_id' => $websiteId,
                'audit_success' => (bool) ($result['audit'] ?? null),
                'competitors_added' => count($result['competitors_added'] ?? []),
                'growth_plan_ready' => (bool) ($result['growth_plan'] ?? null),
            ]);
        }
    }
}
