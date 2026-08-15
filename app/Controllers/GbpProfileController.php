<?php

/**
 * Tourfecto - GBP Module Controller (Setup Wizard / Connection Center /
 * Profile / Photos / Insights / Analytics / AI Insights / Recommendations)
 * @version 1.0.0
 * @since 2026-08-09 (GBP Module Upgrade)
 *
 * ملحوظة معمارية: الاتصال الأساسي (OAuth connect/disconnect/callback) وإدارة
 * المراجعات لسه في ReputationController + GoogleReviewSyncService زي ما هم -
 * الكنترولر ده بيوسّع الوظائف الجديدة بس (Setup Wizard/Sync/Profile
 * Editing/Photos/Insights/AI) من غير ما يكرر أو يعيد بناء أي حاجة موجودة.
 * منشورات GBP (Posts) لسه في GoogleBusinessContentController زي ما هي.
 */
class GbpProfileController extends Controller
{
    /** @var GbpSetupStatusService */
    private $setupStatus;
    /** @var GbpSyncService */
    private $syncService;
    /** @var GbpProfileService */
    private $profileService;
    /** @var GbpPhotoService */
    private $photoService;
    /** @var GbpInsightsService */
    private $insightsService;
    /** @var GbpAIInsightsService */
    private $aiInsightsService;

    public function __construct()
    {
        parent::__construct();
        $this->setupStatus = new GbpSetupStatusService();
        $this->syncService = new GbpSyncService();
        $this->profileService = new GbpProfileService();
        $this->photoService = new GbpPhotoService();
        $this->insightsService = new GbpInsightsService();
        $this->aiInsightsService = new GbpAIInsightsService();
        $this->analyticsService = new GbpReputationAnalyticsService();
    }

    // ============================================
    // Setup Wizard + Connection Center
    // ============================================

    /** GET /api/gbp/status - حالة النظام (Maps/OAuth/Permissions) + اتصالات المستخدم */
    public function status(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            return $this->success([
                'system' => $this->setupStatus->systemStatus(),
                'connections' => $this->setupStatus->connectionsForUser((int) $this->user['id']),
                'websites' => $this->setupStatus->websitesWithConnectionState((int) $this->user['id']),
            ]);
        } catch (Throwable $e) {
            Logger::error('GBP status error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب حالة الإعداد', 500);
        }
    }

    /**
     * GET /api/gbp/health - فحص صحة الموديول (بند AP/AQ بالسبيك)
     * @since 2026-08-14 (Round 8: Professional Finalization)
     */
    public function health(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $service = new GbpHealthCheckService();
            return $this->success($service->check());
        } catch (Throwable $e) {
            Logger::error('GBP health check error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تنفيذ فحص الصحة', 500);
        }
    }

    /**
     * GET /api/gbp/competitors - مقارنة تنافسية مع المنافسين القريبين على
     * Google Maps (تقييم/عدد مراجعات/معدل رد) - بناءً على التحليل التنافسي
     * مع Chatmeter/Birdeye/Semrush Local.
     * @since 2026-08-15
     */
    public function competitors(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) ($params['website_id'] ?? $this->get('website_id', 0));
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        try {
            $service = new GbpCompetitorBenchmarkService();
            return $this->success($service->benchmark($websiteId, (int) $this->user['id']));
        } catch (Throwable $e) {
            Logger::error('GBP competitor benchmark error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تنفيذ المقارنة التنافسية', 500);
        }
    }

    /**
     * GET /api/gbp/analytics - لوحة Reputation Intelligence:
     * KPIs (Response Rate/First Response Time/Review Velocity) + اتجاهات
     * 90 يوم + توزيع التقييمات + مزيج المشاعر. على مستوى Birdeye/Chatmeter.
     * @since 2026-08-15
     */
    public function analytics(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $days = (int) $this->get('days', 90);
        $result = $this->analyticsService->getAnalytics($websiteId, (int) $this->user['id'], $days);
        if (!$result['success']) {
            return $this->error($result['error'], 502);
        }
        return $this->success($result);
    }

    /**
     * GET /api/gbp/risk-signals - مراقبة المخاطر (PulseAi-style):
     * هبوط تقييم، قفزة مراجعات، قفزة سلبية، نمط مشبوه.
     * @since 2026-08-15
     */
    public function riskSignals(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $result = $this->analyticsService->getRiskSignals($websiteId, (int) $this->user['id']);
        if (!$result['success']) {
            return $this->error($result['error'], 502);
        }
        return $this->success($result);
    }

    /**
     * GET /api/gbp/share-of-voice - حصة الظهور المحلية مقارنة بالمنافسين
     * في Google Places (review share + ranks).
     * @since 2026-08-15
     */
    public function shareOfVoice(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $result = $this->analyticsService->getShareOfVoice($websiteId, (int) $this->user['id']);
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'تعذر حساب حصة الظهور', 502);
        }
        return $this->success($result);
    }

    /** POST /api/gbp/sync/{website_id} - مزامنة يدوية فورية */
    public function sync(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) ($params['website_id'] ?? 0);
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $result = $this->syncService->syncWebsite($websiteId, (int) $this->user['id']);
        if (!$result['success']) {
            return $this->error($result['error'], 502);
        }

        return $this->success($result, 'تمت المزامنة بنجاح');
    }

    // ============================================
    // Business Profile Management
    // ============================================

    /** GET /api/gbp/profile?website_id= */
    public function getProfile(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $result = $this->profileService->getProfile($websiteId, (int) $this->user['id']);
        if (!$result['success']) {
            return $this->error($result['error'], 502);
        }

        return $this->success($result);
    }

    /** POST /api/gbp/profile */
    public function updateProfile(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['website_id' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }

        $fields = array_intersect_key($this->all(), array_flip(['description', 'phone', 'website', 'regular_hours']));

        $result = $this->profileService->updateProfile((int) $this->get('website_id'), (int) $this->user['id'], $fields);
        if (!$result['success']) {
            return $this->error($result['error'], 502);
        }

        return $this->success($result, 'تم تحديث البروفايل بنجاح');
    }

    /** GET /api/gbp/attributes?website_id= */
    public function getAttributes(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $result = $this->profileService->getAttributes($websiteId, (int) $this->user['id']);
        if (!$result['success']) {
            return $this->error($result['error'], 502);
        }

        return $this->success($result);
    }

    /** POST /api/gbp/attributes {website_id, changes: {attribute_id: bool}} */
    public function updateAttributes(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $changes = (array) $this->get('changes', []);
        if (!$websiteId || empty($changes)) {
            return $this->error('بيانات ناقصة', 422);
        }

        $result = $this->profileService->updateAttributes($websiteId, (int) $this->user['id'], $changes);
        if (!$result['success']) {
            return $this->error($result['error'], 502);
        }

        return $this->success([], 'تم تحديث الخصائص بنجاح');
    }

    // ============================================
    // Photos / Media
    // ============================================

    /** GET /api/gbp/photos?website_id=&page=&limit= */
    public function listPhotos(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $page = max(1, (int) $this->get('page', 1));
        $limit = min(60, max(1, (int) $this->get('limit', 24)));

        $result = $this->photoService->listPhotos($websiteId, (int) $this->user['id'], $page, $limit);
        if (!$result['success']) {
            return $this->error($result['error'], 500);
        }

        return $this->success($result);
    }

    /** POST /api/gbp/photos (multipart: photo, website_id, category) */
    public function uploadPhoto(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) ($_POST['website_id'] ?? 0);
        $category = (string) ($_POST['category'] ?? 'ADDITIONAL');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }
        if (empty($_FILES['photo'])) {
            return $this->error('لم يتم اختيار أي صورة', 422);
        }

        $uploader = new GbpMediaUploadHandler();
        $validation = $this->photoService->validateUpload($_FILES['photo']);
        if (!$validation['valid']) {
            return $this->error($validation['error'], 422);
        }

        $uploadResult = $uploader->upload($_FILES['photo'], (int) $this->user['id']);
        if (!$uploadResult['success']) {
            return $this->error($uploadResult['error'], 422);
        }

        // Round 6 (2026-08-11): الرفع لجوجل نفسه بقى Async عبر الطابور -
        // بنرجّع فورًا status='uploading' بدل ما نخلي الـ request يستنى
        // رد Google API.
        $result = $this->photoService->queueUpload($websiteId, (int) $this->user['id'], $uploadResult['public_url'], $category);
        if (!$result['success']) {
            return $this->error($result['error'], 502);
        }

        return $this->success($result, 'جارِ رفع الصورة على Google Business Profile في الخلفية...', 202);
    }

    /** DELETE /api/gbp/photos/{id}?website_id= */
    public function deletePhoto(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $photoId = (int) ($params['id'] ?? 0);
        $websiteId = (int) $this->get('website_id');
        if (!$photoId || !$websiteId) {
            return $this->error('بيانات ناقصة', 422);
        }

        $result = $this->photoService->deletePhoto($websiteId, (int) $this->user['id'], $photoId);
        if (!$result['success']) {
            return $this->error($result['error'], 502);
        }

        return $this->success([], 'تم حذف الصورة');
    }

    /** POST /api/gbp/photos/{id}/primary - "رئيسية" محلي في لوحة Tourfecto فقط، مش تغيير فعلي في Google (موثّق في CHANGELOG) */
    public function setPrimaryPhoto(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $photoId = (int) ($params['id'] ?? 0);
        $websiteId = (int) $this->get('website_id');
        if (!$photoId || !$websiteId) {
            return $this->error('بيانات ناقصة', 422);
        }

        $result = $this->photoService->setPrimary($websiteId, (int) $this->user['id'], $photoId);
        if (!$result['success']) {
            return $this->error($result['error'], 422);
        }

        return $this->success([], 'تم التحديد كصورة رئيسية في لوحة Tourfecto');
    }

    // ============================================
    // Insights / Analytics
    // ============================================

    /** GET /api/gbp/insights?website_id=&days=30 */
    public function insights(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $days = (int) $this->get('days', 30);
        if (!in_array($days, [7, 30, 90], true)) {
            $days = max(1, min(365, $days)); // مدى مخصص (Custom Range) بحد أقصى سنة
        }
        $dateFrom = $this->get('date_from');
        $dateTo = $this->get('date_to');

        $result = $this->insightsService->getInsights($websiteId, (int) $this->user['id'], $days, true, $dateFrom ?: null, $dateTo ?: null);
        if (!$result['success']) {
            return $this->error($result['error'], 502);
        }

        return $this->success($result);
    }

    /** GET /api/gbp/ai-insights?website_id= */
    public function aiInsights(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $result = $this->aiInsightsService->generateInsights($websiteId, (int) $this->user['id']);
        if (!$result['success']) {
            return $this->error($result['error'], 502);
        }

        return $this->success($result);
    }

    /** GET /api/gbp/recommendations?website_id= */
    public function recommendations(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $result = $this->aiInsightsService->generateRecommendations($websiteId, (int) $this->user['id']);
        if (!$result['success']) {
            return $this->error($result['error'], 502);
        }

        return $this->success($result);
    }
}
