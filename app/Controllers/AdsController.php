<?php

/**
 * Tourfecto - Ads Controller (إدارة الإعلانات)
 * @version 1.0.0
 */
class AdsController extends Controller
{
    /** @var AdCampaignService */
    private $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new AdCampaignService();
    }

    /** GET /ads */
    public function index(array $params = []): array
    {
        $objectiveOptionsHtml = '';
        foreach (AdCopyGenerationService::OBJECTIVES as $key => $label) {
            $keyEsc = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
            $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $objectiveOptionsHtml .= "<option value=\"{$keyEsc}\">{$labelEsc}</option>";
        }

        $ctasJson = htmlspecialchars(
            json_encode(AdCopyGenerationService::allowedCtas(), JSON_UNESCAPED_UNICODE),
            ENT_QUOTES,
            'UTF-8'
        );

        $body = $this->renderView('ads/index', ['adsActive' => 'dashboard', 'objectiveOptionsHtml' => $objectiveOptionsHtml, 'ctasJson' => $ctasJson]);
        $script = '<script src="' . asset_v('/assets/js/ads/index.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');

        echo $this->renderPanelPage('ads', 'إدارة الإعلانات', 'حملاتك الإعلانية عبر كل المنصات المربوطة', $body, $script);
        exit;
    }
    /** GET /api/ads/campaigns (?owner_id= لعرض حساب فريق تانٍ إنت عضو فيه) */
    public function list(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $campaigns = $this->service->listForUser($access['owner_id']);
        return $this->success(['campaigns' => array_map(fn ($c) => $c->toArray(), $campaigns), 'your_role' => $access['role']]);
    }

    /**
     * GET /api/ads/campaigns/search?q=&status=&sort=&dir=&page=&per_page=&owner_id=
     * نسخة Server-side مع بحث/فلترة/ترتيب/Pagination حقيقي - endpoint
     * منفصل عن list() القديمة عشان أي استدعاء موجود ليها يفضل شغال بالظبط
     * زي ما هو، مفيش أي Breaking change.
     */
    public function searchCampaigns(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $result = $this->service->listForUserPaginated($access['owner_id'], [
            'search' => $this->get('q', ''),
            'status' => $this->get('status', ''),
            'sort' => $this->get('sort', 'created_at'),
            'dir' => $this->get('dir', 'desc'),
            'page' => (int) $this->get('page', 1),
            'per_page' => (int) $this->get('per_page', 20),
        ]);
        $result['your_role'] = $access['role'];

        return $this->success($result);
    }

    /** GET /api/ads/campaigns/{id} - تفاصيل حملة واحدة + الجمهور المرتبط بيها */
    public function getCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $access = $this->resolveCampaignAccess($campaign, 'viewer');
        if (!$access) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $audiences = (new AdAudience())->where(['campaign_id' => (int) $campaign->getAttribute('id')], [], 1);
        $audience = !empty($audiences) ? $audiences[0]->toArray() : null;
        if ($audience) {
            $audience['locations'] = json_decode((string) ($audience['locations_json'] ?? 'null'), true);
            $audience['interests'] = json_decode((string) ($audience['interests_json'] ?? 'null'), true);
        }

        $data = $campaign->toArray();
        $data['landing_page_last_analysis'] = $data['landing_page_last_analysis'] ? json_decode((string) $data['landing_page_last_analysis'], true) : null;

        return $this->success(['campaign' => $data, 'audience' => $audience, 'your_role' => $access['role']]);
    }

    /**
     * POST /api/ads/campaigns
     * بيقبل إنشاء يدوي بسيط (اسم + ميزانية بس، زي الأول)، أو إنشاء كامل
     * من ويزارد الذكاء الاصطناعي (لما يبعت objective/product_or_service/
     * target_audience_brief/audience/budget_recommendation/copies بعد
     * ما العميل يراجع معاينة /api/ads/campaigns/ai-generate ويأكّدها).
     */
    public function create(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }
        if (!$this->validate(['name' => 'required'])) {
            return $this->error('اسم الحملة مطلوب', 422);
        }

        try {
            $campaign = $this->service->create($access['owner_id'], [
                'name' => $this->get('name'),
                'objective' => $this->get('objective'),
                'platform' => $this->get('platform'),
                'product_or_service' => $this->get('product_or_service'),
                'target_audience_brief' => $this->get('target_audience_brief'),
                'target_countries_json' => $this->get('target_countries_json'),
                'landing_page_url' => $this->get('landing_page_url'),
                'daily_budget' => $this->get('daily_budget'),
                'budget_total' => $this->get('budget_total'),
                'currency' => $this->get('currency'),
                'status' => $this->get('status'),
                'start_date' => $this->get('start_date'),
                'end_date' => $this->get('end_date'),
                'ai_generated' => $this->get('ai_generated'),
                'website_id' => $this->get('website_id'),
                'audience' => $this->get('audience'),
                'budget_recommendation' => $this->get('budget_recommendation'),
                'copies' => $this->get('copies'),
                'keywords' => $this->get('keywords'),
            ]);
            return $this->success(['campaign' => $campaign->toArray()], 'تم إنشاء الحملة كمسودة', 201);
        } catch (Exception $e) {
            Logger::error('createCampaign Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنشاء الحملة', 500);
        }
    }

    /**
     * POST /api/ads/campaigns/ai-generate
     * ويزارد الحملة الاحترافي: من وصف بسيط لعرض العميل، الذكاء الاصطناعي
     * بيجهّز حزمة حملة كاملة (اسم + جمهور مستهدف + توصية ميزانية + 3
     * نصوص إعلانية مطابقة لحدود المنصات فعليًا). دي "معاينة" فقط - محفظتش
     * حاجة في قاعدة البيانات لحد ما العميل يراجعها ويأكّد الإنشاء عبر
     * POST /api/ads/campaigns العادي.
     */
    public function aiGenerateCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }
        if (!$this->validate(['goal_description' => 'required', 'objective' => 'required'])) {
            return $this->error('اكتب وصف مختصر لعرضك واختار هدف الحملة', 422);
        }

        $objective = (string) $this->get('objective');
        if (!array_key_exists($objective, AdCopyGenerationService::OBJECTIVES)) {
            return $this->error('هدف الحملة غير معروف', 422);
        }

        $walletService = new WalletService();
        $priceCheck = $walletService->canAffordUsage($access['owner_id'], 'ai_ad_campaign_generation');
        if (!$priceCheck['can_afford']) {
            return $this->error('رصيدك في المحفظة مش كافي لتوليد حملة بالذكاء الاصطناعي', 402, [
                'shortfall' => $priceCheck['shortfall'] ?? null,
            ]);
        }

        try {
            $goalDescription = (string) $this->get('goal_description');
            $dailyBudget = $this->get('daily_budget');

            $service = new AdCopyGenerationService();
            $brief = $service->generateCampaignBrief($goalDescription, $objective, $dailyBudget !== null && $dailyBudget !== '' ? (float) $dailyBudget : null);

            $walletService->chargeForUsage($access['owner_id'], 'ai_ad_campaign_generation', 'توليد حملة إعلانية بالذكاء الاصطناعي');

            return $this->success([
                'brief' => $brief,
                'new_balance' => $walletService->getBalance($access['owner_id']),
            ]);
        } catch (Exception $e) {
            Logger::error('aiGenerateCampaign Error', ['message' => $e->getMessage()]);
            return $this->error($e->getMessage(), 502);
        }
    }

    /** GET /api/ads/campaigns/{id}/copies */
    public function listCopies(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'viewer')) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $items = (new AdCopy())->where(['campaign_id' => (int) $campaign->getAttribute('id')], ['created_at' => 'DESC']);
        return $this->success(['copies' => array_map(fn ($c) => $c->toArray(), $items)]);
    }

    /**
     * توليد نصوص إعلانية بالذكاء الاصطناعي لحملة موجودة.
     * POST /api/ads/campaigns/{id}/generate-copies
     *
     * ملحوظة: كانت دي الـ endpoint اللي زرار "توليد ✨" في صفحة الإعلانات
     * بينده عليها من غير ما تكون موجودة أصلاً - يعني كل ضغطة على الزرار
     * كانت بتسبب خطأ فادح فوري. الـ Service الحقيقي (AdCopyGenerationService)
     * كان مبني ومفعّل وحقيقي (Gemini فعلي) من زمان، بس مربوطش بأي controller
     * method أو route.
     */
    public function generateCopies(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('الحملة غير موجودة', 404);
        }

        try {
            $service = new AdCopyGenerationService();
            $copies = $service->generateCopies($campaign, 3);
            return $this->success(['copies' => array_map(fn ($c) => $c->toArray(), $copies)], 'تم توليد النصوص الإعلانية', 201);
        } catch (Exception $e) {
            Logger::error('generateCopies Error', ['campaign_id' => $params['id'] ?? null, 'message' => $e->getMessage()]);
            return $this->error($e->getMessage(), 502);
        }
    }

    /** PATCH /api/ads/copies/{id}/approve - اعتماد نسخة إعلانية معيّنة كالنسخة المستخدمة فعليًا */
    public function approveCopy(array $params = []): array
    {
        return $this->updateCopyStatus($params, 'approved');
    }

    /** PATCH /api/ads/copies/{id}/reject - استبعاد نسخة إعلانية */
    public function rejectCopy(array $params = []): array
    {
        return $this->updateCopyStatus($params, 'rejected');
    }

    private function updateCopyStatus(array $params, string $status): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $copy = (new AdCopy())->find((int) ($params['id'] ?? 0));
        if (!$copy) {
            return $this->error('النسخة الإعلانية غير موجودة', 404);
        }

        $campaign = (new AdCampaign())->find((int) $copy->getAttribute('campaign_id'));
        if (!$campaign || !$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('غير مصرح', 403);
        }

        try {
            $copy->fill(['status' => $status]);
            $copy->save();
            return $this->success(['copy' => $copy->toArray()], $status === 'approved' ? 'تم اعتماد النسخة' : 'تم استبعاد النسخة');
        } catch (Exception $e) {
            Logger::error('updateCopyStatus Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحديث حالة النسخة', 500);
        }
    }

    // ============================================
    // Meta Ads OAuth - ربط ومزامنة حقيقية مع Meta Marketing API
    // ============================================

    /** GET /ads/connect/meta */
    public function connectMeta(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads'));
            exit;
        }

        $oauth = new MetaOAuthClient();
        if (!$oauth->isConfigured()) {
            $this->renderAdsOAuthError('ربط Meta Ads لسه مش مفعّل من إدارة النظام (بيانات META_APP_ID/META_APP_SECRET ناقصة في إعدادات السيرفر).');
            exit;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['meta_oauth_nonce'] = $nonce;

        $state = base64_encode(json_encode(['nonce' => $nonce], JSON_UNESCAPED_UNICODE));
        header('Location: ' . $oauth->buildAuthUrl($state));
                exit;
    }
    /** GET /ads/connect/meta/callback */
    public function metaOAuthCallback(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $error = $this->get('error');
        if ($error) {
            $this->renderAdsOAuthError('العميل رفض الموافقة أو حصل خطأ من Meta: ' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $code = $this->get('code');
        $state = $this->get('state');
        if (!$code || !$state) {
            $this->renderAdsOAuthError('رد غير مكتمل من Meta');
            exit;
        }

        $decodedState = json_decode(base64_decode((string) $state), true);
        $expectedNonce = $_SESSION['meta_oauth_nonce'] ?? null;

        if (!$decodedState || !$expectedNonce || !hash_equals($expectedNonce, $decodedState['nonce'] ?? '')) {
            $this->renderAdsOAuthError('انتهت صلاحية الجلسة أو محاولة غير موثوقة، جرّب تربط الحساب تاني');
            exit;
        }

        $oauth = new MetaOAuthClient();
        $tokenResult = $oauth->exchangeCodeForTokens((string) $code);

        if (!$tokenResult['success']) {
            $this->renderAdsOAuthError('فشل تبادل التوكن مع Meta: ' . htmlspecialchars($tokenResult['error'] ?? '', ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $_SESSION['meta_oauth_temp'] = [
            'access_token' => $tokenResult['access_token'],
            'expires_in' => $tokenResult['expires_in'],
        ];
        unset($_SESSION['meta_oauth_nonce']);

        header('Location: /ads/connect/meta/choose');
                exit;
    }
    /** GET /ads/connect/meta/choose - يختار العميل حساب الإعلانات بتاعه */
    public function showMetaAdAccountPicker(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $temp = $_SESSION['meta_oauth_temp'] ?? null;
        if (!$temp) {
            header('Location: /ads');
            exit;
        }

        $api = new MetaAdsAPI($temp['access_token']);
        $accountsResult = $api->listAdAccounts();

        if (!$accountsResult['success'] || empty($accountsResult['accounts'])) {
            $this->renderAdsOAuthError('مفيش حسابات إعلانات Meta مرتبطة بالحساب ده. تأكد إنك مسجّل دخول بنفس حساب Facebook اللي عليه صلاحية على حساب الإعلانات في Business Manager.<br><br>تفاصيل تقنية: ' . htmlspecialchars($accountsResult['error'] ?? '', ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $optionsHtml = '';
        foreach ($accountsResult['accounts'] as $acc) {
            $id = htmlspecialchars($acc['id'], ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars($acc['name'], ENT_QUOTES, 'UTF-8');
            $currency = htmlspecialchars($acc['currency'], ENT_QUOTES, 'UTF-8');
            $optionsHtml .= "<button class=\"p-btn outline\" style=\"width:100%;text-align:start;margin-bottom:8px;\" onclick=\"chooseAccount('{$id}')\">{$name} <span class=\"p-cell-muted\">({$currency})</span></button>";
        }

        $body = $this->renderView('ads/account_picker', [
            'pickerTitle' => 'اختار حساب الإعلانات',
            'pickerSubtitle' => 'هنربط حملاتك الحقيقية من الحساب ده',
            'pickerOptions' => $optionsHtml,
        ]);
        $script = '<script src="' . asset_v('/assets/js/ads/meta_picker.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');

        echo $this->renderPanelPage('ads', 'اختيار حساب Meta Ads', '', $body, $script);
        exit;
    }
    /** POST /api/ads/meta/choose-account */
    public function chooseMetaAdAccount(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $temp = $_SESSION['meta_oauth_temp'] ?? null;
        if (!$temp) {
            return $this->error('انتهت الجلسة، ابدأ الربط تاني', 400);
        }

        $accountId = $this->get('account_id');
        if (!$accountId) {
            return $this->error('account_id مطلوب', 422);
        }

        try {
            $website = $this->firstWebsiteForUser((int) $this->user['id']);
            if (!$website) {
                return $this->error('لازم يكون عندك موقع مضاف الأول من صفحة "المواقع"', 422);
            }

            $encryption = new Encryption();
            // تصحيح أمان: التوكن كان بيتخزن كنص صريح في قاعدة البيانات من غير
            // تشفير، خلاف كل باقي التكاملات (Google, TripAdvisor...) اللي
            // بتشفّر التوكن دايمًا. اتصلح هنا ليتطابق مع باقي النظام.
            $encryptedToken = $encryption->encrypt($temp['access_token']);
            $expiresAt = date('Y-m-d H:i:s', time() + (int) $temp['expires_in']);

            $existing = $this->db->query(
                "SELECT id FROM platform_connections WHERE website_id = ? AND platform = 'meta_ads' LIMIT 1",
                [$website['id']]
            );

            if (!empty($existing)) {
                $this->db->exec(
                    "UPDATE platform_connections SET access_token = ?, token_expires_at = ?, external_account_id = ?, status = 'connected', last_error = NULL, connected_at = NOW() WHERE id = ?",
                    [$encryptedToken, $expiresAt, $accountId, $existing[0]['id']]
                );
            } else {
                $this->db->exec(
                    "INSERT INTO platform_connections (website_id, user_id, platform, access_token, token_expires_at, external_account_id, status)
                     VALUES (?, ?, 'meta_ads', ?, ?, ?, 'connected')",
                    [$website['id'], $this->user['id'], $encryptedToken, $expiresAt, $accountId]
                );
            }

            // ربط تلقائي لأي صفحات فيسبوك (وحسابات انستجرام بيزنس المرتبطة
            // بيها) متاحة لنفس حساب Meta ده، عشان تبقى جاهزة للنشر عليها
            // فورًا من "السوشيال ميديا" من غير خطوة ربط منفصلة تانية.
            $this->autoConnectMetaSocialPages($website['id'], (int) $this->user['id'], $temp['access_token'], $encryption);

            unset($_SESSION['meta_oauth_temp']);
            return $this->success([], 'تم ربط حساب Meta Ads والصفحات المتاحة');
        } catch (Exception $e) {
            Logger::error('chooseMetaAdAccount Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حفظ الربط', 500);
        }
    }

    /**
     * POST /api/ads/campaigns/{id}/publish
     * النشر الفعلي: بياخد الحملة المحفوظة محليًا (مسودة) والنصوص
     * المعتمدة، ويبعتها فعليًا لـ Meta Ads أو Google Ads عشان تتعمل
     * كحملة حقيقية هناك - دايمًا بحالة متوقفة (Paused) كإجراء أمان،
     * العميل لازم يراجعها ويفعّلها بنفسه من داخل حساب المنصة الرسمي.
     */
    public function publishCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        $access = $this->resolveCampaignAccess($campaign, 'manager');
        if (!$access) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $platform = $campaign->getAttribute('platform');
        if (!in_array($platform, ['meta_ads', 'google_ads'], true)) {
            return $this->error('الحملة دي يدوية (تتبع فقط) - مفيش منصة إعلانات مرتبطة بيها للنشر عليها', 422);
        }

        if (!empty($campaign->getAttribute('external_campaign_id'))) {
            return $this->error('الحملة دي منشورة بالفعل على المنصة', 422);
        }

        $approvedCopies = (new AdCopy())->where(['campaign_id' => (int) $campaign->getAttribute('id'), 'status' => 'approved']);
        if (empty($approvedCopies)) {
            return $this->error('لازم تعتمد نسخة إعلانية واحدة على الأقل قبل النشر (زرار "اعتماد" تحت النصوص)', 422);
        }
        $copiesData = array_map(fn ($c) => $c->toArray(), $approvedCopies);

        $website = (new Website())->find((int) $campaign->getAttribute('website_id'));
        $destinationUrl = $website ? trim((string) $website->getAttribute('main_url')) : '';
        if ($destinationUrl === '') {
            return $this->error('محتاج رابط موقع صحيح مربوط بحسابك عشان الإعلان يوصّل الزوار له', 422);
        }
        if (!preg_match('#^https?://#i', $destinationUrl)) {
            $destinationUrl = 'https://' . $destinationUrl;
        }

        try {
            $connection = $this->db->query(
                "SELECT * FROM platform_connections WHERE user_id = ? AND platform = ? AND status = 'connected' LIMIT 1",
                [(int) $campaign->getAttribute('user_id'), $platform]
            );
            if (empty($connection)) {
                return $this->error('لازم تربط حساب ' . ($platform === 'meta_ads' ? 'Meta Ads' : 'Google Ads') . ' الأول من أعلى الصفحة', 422);
            }
            $conn = $connection[0];
            $encryption = new Encryption();
            $accessToken = $encryption->decrypt($conn['access_token']);

            $campaignPayload = [
                'name' => $campaign->getAttribute('name'),
                'objective' => $campaign->getAttribute('objective') ?: 'traffic',
                'daily_budget' => $campaign->getAttribute('daily_budget') ?: 10,
                'start_date' => $campaign->getAttribute('start_date'),
                'end_date' => $campaign->getAttribute('end_date'),
            ];

            if ($platform === 'meta_ads') {
                $pages = $this->db->query(
                    "SELECT external_location_id, external_location_name FROM platform_connections
                     WHERE website_id = ? AND platform = 'facebook' AND status = 'connected'",
                    [$conn['website_id']]
                );
                if (empty($pages)) {
                    return $this->error('محتاج صفحة فيسبوك مربوطة عشان تظهر عليها الإعلانات - اتأكد إن عندك صفحة فيسبوك أدمن عليها وأعد ربط Meta Ads من جديد', 422);
                }

                $pageId = $this->get('page_id');
                if (!$pageId) {
                    if (count($pages) === 1) {
                        $pageId = $pages[0]['external_location_id'];
                    } else {
                        // أكتر من صفحة - محتاجين العميل يختار، بنرجّع القائمة عشان الواجهة تعرضها
                        return $this->error('عندك أكتر من صفحة فيسبوك - اختار واحدة للنشر عليها', 409, [
                            'pages' => array_map(fn ($p) => ['id' => $p['external_location_id'], 'name' => $p['external_location_name']], $pages),
                        ]);
                    }
                }

                $audienceRows = (new AdAudience())->where(['campaign_id' => (int) $campaign->getAttribute('id')]);
                $audienceRow = !empty($audienceRows) ? $audienceRows[0]->toArray() : [];
                $audience = [
                    'age_min' => $audienceRow['age_min'] ?? 18,
                    'age_max' => $audienceRow['age_max'] ?? 65,
                    'genders' => $audienceRow['genders'] ?? 'all',
                    'locations' => !empty($audienceRow['locations_json']) ? (json_decode($audienceRow['locations_json'], true) ?: []) : [],
                ];

                $api = new MetaAdsAPI($accessToken);
                $imageUrl = $api->fetchOgImageFromWebsite($destinationUrl); // best-effort - ممكن ترجع null وده مقبول
                $result = $api->createCampaign($conn['external_account_id'], $pageId, $campaignPayload, $audience, $copiesData, $destinationUrl, $imageUrl);
            } else {
                $keywordRows = (new AdKeyword())->where(['campaign_id' => (int) $campaign->getAttribute('id')]);
                $keywords = array_map(fn ($k) => ['keyword' => $k->getAttribute('keyword'), 'match_type' => $k->getAttribute('match_type')], $keywordRows);

                $budgetRecRows = (new AdBudgetRecommendation())->where(['campaign_id' => (int) $campaign->getAttribute('id')]);
                $bidStrategyHint = !empty($budgetRecRows) ? (string) $budgetRecRows[0]->getAttribute('bid_strategy') : '';

                $api = new GoogleAdsAPI($accessToken);
                $result = $api->createSearchCampaign($conn['external_account_id'], $campaignPayload, $copiesData, $keywords, $destinationUrl, $bidStrategyHint);
            }

            if (!($result['success'] ?? false)) {
                // لو اتعمل جزء من الحملة على المنصة (external_campaign_id راجع) بنسجّله برضه، عشان العميل يلاقيها ويكمّلها يدويًا بدل ما تتوه
                if (!empty($result['external_campaign_id'])) {
                    $campaign->fill([
                        'external_campaign_id' => $result['external_campaign_id'],
                        'external_adset_id' => $result['external_adset_id'] ?? null,
                        'external_budget_resource' => $result['external_budget_resource'] ?? null,
                        'platform_connection_id' => $conn['id'],
                    ]);
                    $campaign->save();
                }
                return $this->error($result['error'] ?? 'فشل النشر على المنصة', 502);
            }

            $campaign->fill([
                'external_campaign_id' => $result['external_campaign_id'],
                'external_adset_id' => $result['external_adset_id'] ?? null,
                'external_budget_resource' => $result['external_budget_resource'] ?? null,
                'platform_connection_id' => $conn['id'],
                'status' => 'paused',
                'published_at' => date('Y-m-d H:i:s'),
            ]);
            $campaign->save();

            ActivityLog::record('ads', 'ad_campaign.published', [
                'subject_type' => 'ad_campaigns', 'subject_id' => (int) $campaign->getAttribute('id'),
                'meta' => ['platform' => $platform, 'external_campaign_id' => $result['external_campaign_id']],
            ]);

            return $this->success([
                'campaign' => $campaign->toArray(),
            ], 'تم إنشاء الحملة فعليًا على ' . ($platform === 'meta_ads' ? 'Meta Ads' : 'Google Ads') . ' بحالة متوقفة - راجعها وفعّلها من حسابك الرسمي هناك');
        } catch (Exception $e) {
            Logger::error('publishCampaign Error', ['campaign_id' => $params['id'] ?? null, 'message' => $e->getMessage()]);
            return $this->error('تعذر النشر: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/ads/campaigns/{id}/toggle-status
     * تشغيل/إيقاف حملة منشورة فعليًا على المنصة (Meta أو Google) - بيغيّر
     * الحالة هناك مباشرة، مش بس محليًا، عشان الإنفاق الفعلي يتأثر فورًا.
     */
    public function toggleCampaignStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        [$campaign, $conn, $err] = $this->loadPublishedCampaignForManagement((int) ($params['id'] ?? 0));
        if ($err) {
            return $err;
        }

        $newStatus = $campaign->getAttribute('status') === 'active' ? 'paused' : 'active';

        try {
            $encryption = new Encryption();
            $accessToken = $encryption->decrypt($conn['access_token']);

            if ($campaign->getAttribute('platform') === 'meta_ads') {
                $api = new MetaAdsAPI($accessToken);
                $result = $api->updateCampaignStatus((string) $campaign->getAttribute('external_campaign_id'), $newStatus === 'active' ? 'ACTIVE' : 'PAUSED');
            } else {
                $api = new GoogleAdsAPI($accessToken);
                $result = $api->updateCampaignStatus($conn['external_account_id'], (string) $campaign->getAttribute('external_campaign_id'), $newStatus === 'active' ? 'ENABLED' : 'PAUSED');
            }

            if (!($result['success'] ?? false)) {
                return $this->error($result['error'] ?? 'فشل تعديل حالة الحملة على المنصة', 502);
            }

            $campaign->fill(['status' => $newStatus]);
            $campaign->save();

            return $this->success(['campaign' => $campaign->toArray()], $newStatus === 'active' ? 'تم تشغيل الحملة' : 'تم إيقاف الحملة');
        } catch (Exception $e) {
            Logger::error('toggleCampaignStatus Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تعديل الحالة', 500);
        }
    }

    /**
     * POST /api/ads/campaigns/{id}/cancel
     * إلغاء حملة منشورة نهائيًا على المنصة (أرشفة على Meta، أو status=REMOVED على Google).
     */
    public function cancelCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        [$campaign, $conn, $err] = $this->loadPublishedCampaignForManagement((int) ($params['id'] ?? 0));
        if ($err) {
            return $err;
        }

        try {
            $encryption = new Encryption();
            $accessToken = $encryption->decrypt($conn['access_token']);

            if ($campaign->getAttribute('platform') === 'meta_ads') {
                $api = new MetaAdsAPI($accessToken);
                $result = $api->deleteCampaign((string) $campaign->getAttribute('external_campaign_id'));
            } else {
                $api = new GoogleAdsAPI($accessToken);
                $result = $api->deleteCampaign($conn['external_account_id'], (string) $campaign->getAttribute('external_campaign_id'));
            }

            if (!($result['success'] ?? false)) {
                return $this->error($result['error'] ?? 'فشل إلغاء الحملة على المنصة', 502);
            }

            $campaign->fill(['status' => 'removed']);
            $campaign->save();

            return $this->success(['campaign' => $campaign->toArray()], 'تم إلغاء الحملة');
        } catch (Exception $e) {
            Logger::error('cancelCampaign Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إلغاء الحملة', 500);
        }
    }

    /**
     * POST /api/ads/campaigns/{id}/update-budget
     * تعديل الميزانية اليومية لحملة منشورة بالفعل - محتاج البيانات المحفوظة
     * وقت النشر (external_adset_id لـ Meta، external_budget_resource لـ Google).
     */
    public function updateCampaignBudget(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        [$campaign, $conn, $err] = $this->loadPublishedCampaignForManagement((int) ($params['id'] ?? 0));
        if ($err) {
            return $err;
        }

        $newBudget = (float) $this->get('daily_budget');
        if ($newBudget <= 0) {
            return $this->error('الميزانية لازم تكون أكبر من صفر', 422);
        }

        try {
            $encryption = new Encryption();
            $accessToken = $encryption->decrypt($conn['access_token']);

            if ($campaign->getAttribute('platform') === 'meta_ads') {
                if (empty($campaign->getAttribute('external_adset_id'))) {
                    return $this->error('مفيش معرّف مجموعة إعلانية محفوظ لهذه الحملة - راجعها يدويًا من Meta Ads Manager', 422);
                }
                $api = new MetaAdsAPI($accessToken);
                $result = $api->updateAdSetBudget((string) $campaign->getAttribute('external_adset_id'), $newBudget);
            } else {
                if (empty($campaign->getAttribute('external_budget_resource'))) {
                    return $this->error('مفيش معرّف ميزانية محفوظ لهذه الحملة - راجعها يدويًا من Google Ads', 422);
                }
                $api = new GoogleAdsAPI($accessToken);
                $result = $api->updateBudget((string) $campaign->getAttribute('external_budget_resource'), $newBudget);
            }

            if (!($result['success'] ?? false)) {
                return $this->error($result['error'] ?? 'فشل تعديل الميزانية على المنصة', 502);
            }

            $campaign->fill(['daily_budget' => $newBudget]);
            $campaign->save();

            return $this->success(['campaign' => $campaign->toArray()], 'تم تعديل الميزانية اليومية');
        } catch (Exception $e) {
            Logger::error('updateCampaignBudget Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تعديل الميزانية', 500);
        }
    }

    /**
     * يحمّل حملة منشورة فعليًا مع بيانات ربطها للتعامل الإداري (إيقاف/تشغيل/إلغاء/تعديل ميزانية).
     * @return array{0: ?AdCampaign, 1: ?array, 2: ?array} [الحملة, صف الربط, رد خطأ لو فيه مشكلة]
     */
    private function loadPublishedCampaignForManagement(int $campaignId): array
    {
        $campaign = (new AdCampaign())->find($campaignId);
        if (!$campaign) {
            return [null, null, $this->error('الحملة غير موجودة', 404)];
        }
        $access = $this->resolveCampaignAccess($campaign, 'manager');
        if (!$access) {
            return [null, null, $this->error('الحملة غير موجودة', 404)];
        }

        if (empty($campaign->getAttribute('external_campaign_id'))) {
            return [null, null, $this->error('الحملة دي لسه مسودة محلية - مش منشورة على أي منصة', 422)];
        }

        $connRows = $this->db->query(
            "SELECT * FROM platform_connections WHERE id = ? AND status = 'connected' LIMIT 1",
            [$campaign->getAttribute('platform_connection_id')]
        );
        if (empty($connRows)) {
            return [null, null, $this->error('الربط بالمنصة اتفصل - أعد الربط الأول', 422)];
        }

        return [$campaign, $connRows[0], null];
    }

    /**
     * يجيب كل صفحات فيسبوك (وانستجرام المرتبط بيها) المتاحة لتوكن
     * المستخدم، ويحفظهم كاتصالات منصة جاهزة للنشر (platform='facebook'
     * لكل صفحة، وplatform='instagram' لو فيها حساب بيزنس مرتبط).
     */
    private function autoConnectMetaSocialPages(int $websiteId, int $userId, string $userAccessToken, Encryption $encryption): void
    {
        try {
            $api = new MetaSocialAPI($userAccessToken);
            $pagesResult = $api->listPages();

            if (!$pagesResult['success']) {
                Logger::warning('Auto-connect Meta pages skipped', ['error' => $pagesResult['error'] ?? '']);
                return;
            }

            foreach ($pagesResult['pages'] as $page) {
                if (empty($page['access_token'])) {
                    continue;
                }
                $encryptedPageToken = $encryption->encrypt($page['access_token']);

                // صفحة الفيسبوك نفسها
                $this->upsertSocialConnection($websiteId, $userId, 'facebook', $page['id'], $page['name'], $encryptedPageToken);

                // حساب انستجرام بيزنس المرتبط بالصفحة (لو موجود)
                if (!empty($page['instagram_id'])) {
                    $this->upsertSocialConnection(
                        $websiteId,
                        $userId,
                        'instagram',
                        $page['instagram_id'],
                        $page['instagram_username'] ?? $page['name'],
                        $encryptedPageToken
                    );
                }
            }
        } catch (Exception $e) {
            Logger::error('autoConnectMetaSocialPages Error', ['message' => $e->getMessage()]);
        }
    }

    private function upsertSocialConnection(int $websiteId, int $userId, string $platform, string $externalId, string $name, string $encryptedToken): void
    {
        $existing = $this->db->query(
            "SELECT id FROM platform_connections WHERE website_id = ? AND platform = ? AND external_location_id = ? LIMIT 1",
            [$websiteId, $platform, $externalId]
        );

        if (!empty($existing)) {
            $this->db->exec(
                "UPDATE platform_connections SET access_token = ?, external_location_name = ?, status = 'connected', last_error = NULL, connected_at = NOW() WHERE id = ?",
                [$encryptedToken, $name, $existing[0]['id']]
            );
        } else {
            $this->db->exec(
                "INSERT INTO platform_connections (website_id, user_id, platform, access_token, external_location_id, external_location_name, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'connected')",
                [$websiteId, $userId, $platform, $encryptedToken, $externalId, $name]
            );
        }
    }

    /** GET /api/ads/meta/status */
    public function getMetaConnectionStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $oauth = new MetaOAuthClient();
        if (!$oauth->isConfigured()) {
            return $this->success(['configured' => false, 'connected' => false]);
        }

        try {
            $row = $this->db->query(
                "SELECT external_account_id FROM platform_connections
                 WHERE user_id = ? AND platform = 'meta_ads' AND status = 'connected' LIMIT 1",
                [$access['owner_id']]
            );

            if (empty($row)) {
                return $this->success(['configured' => true, 'connected' => false]);
            }

            return $this->success([
                'configured' => true,
                'connected' => true,
                'external_account_id' => $row[0]['external_account_id'],
            ]);
        } catch (Exception $e) {
            Logger::error('getMetaConnectionStatus Error', ['message' => $e->getMessage()]);
            return $this->success(['configured' => true, 'connected' => false]);
        }
    }

    /** POST /api/ads/meta/sync - سحب حملات حقيقية من Meta وتحديث ad_campaigns */
    public function syncMetaCampaigns(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        try {
            $connection = $this->db->query(
                "SELECT id, website_id, access_token, external_account_id FROM platform_connections
                 WHERE user_id = ? AND platform = 'meta_ads' AND status = 'connected' LIMIT 1",
                [$access['owner_id']]
            );

            if (empty($connection)) {
                return $this->error('مفيش حساب Meta Ads مربوط', 400);
            }

            $conn = $connection[0];
            $decryptedToken = (new Encryption())->decrypt($conn['access_token']);
            $api = new MetaAdsAPI($decryptedToken);
            $result = $api->listCampaignsWithInsights($conn['external_account_id']);

            if (!$result['success']) {
                $this->db->exec(
                    "UPDATE platform_connections SET status = 'error', last_error = ? WHERE id = ?",
                    [$result['error'] ?? 'unknown error', $conn['id']]
                );
                return $this->error('تعذرت المزامنة مع Meta: ' . ($result['error'] ?? ''), 502);
            }

            $synced = 0;
            foreach ($result['campaigns'] as $c) {
                $existing = $this->db->query(
                    "SELECT id FROM ad_campaigns WHERE user_id = ? AND external_campaign_id = ? LIMIT 1",
                    [$access['owner_id'], $c['external_campaign_id']]
                );

                if (!empty($existing)) {
                    $this->db->exec(
                        "UPDATE ad_campaigns SET name = ?, objective = ?, daily_budget = ?, status = ?, impressions = ?, clicks = ?, spend = ?, started_at = ?, ended_at = ?, updated_at = NOW()
                         WHERE id = ?",
                        [$c['name'], $c['objective'], $c['daily_budget'], $c['status'], $c['impressions'], $c['clicks'], $c['spend'], $c['started_at'], $c['ended_at'], $existing[0]['id']]
                    );
                } else {
                    $this->db->exec(
                        "INSERT INTO ad_campaigns (user_id, website_id, platform_connection_id, name, objective, daily_budget, status, external_campaign_id, impressions, clicks, spend, started_at, ended_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$access['owner_id'], $conn['website_id'], $conn['id'], $c['name'], $c['objective'], $c['daily_budget'], $c['status'], $c['external_campaign_id'], $c['impressions'], $c['clicks'], $c['spend'], $c['started_at'], $c['ended_at']]
                    );
                }
                $synced++;
            }

            $this->db->exec("UPDATE platform_connections SET last_synced_at = NOW(), status = 'connected', last_error = NULL WHERE id = ?", [$conn['id']]);

            return $this->success(['synced' => $synced]);
        } catch (Exception $e) {
            Logger::error('syncMetaCampaigns Error', ['message' => $e->getMessage()]);
            return $this->error('تعذرت المزامنة', 500);
        }
    }

    /** POST /api/ads/meta/disconnect */
    public function disconnectMeta(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('admin');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        try {
            $this->db->exec(
                "UPDATE platform_connections SET status = 'disconnected', access_token = NULL WHERE user_id = ? AND platform = 'meta_ads'",
                [$access['owner_id']]
            );
            return $this->success([], 'تم فصل الربط');
        } catch (Exception $e) {
            Logger::error('disconnectMeta Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الفصل', 500);
        }
    }

    /** GET /ads/connect/google */
    public function connectGoogleAds(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads'));
            exit;
        }

        $oauth = new GoogleOAuthClient(GoogleOAuthClient::SCOPE_ADS, env('GOOGLE_ADS_OAUTH_REDIRECT_URI') ?: null);
        if (!$oauth->isConfigured()) {
            $this->renderAdsOAuthError('ربط Google Ads لسه مش مفعّل من إدارة النظام (بيانات GOOGLE_CLIENT_ID/SECRET أو GOOGLE_ADS_OAUTH_REDIRECT_URI ناقصة في إعدادات السيرفر).');
            exit;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['google_ads_oauth_nonce'] = $nonce;

        $state = base64_encode(json_encode(['nonce' => $nonce], JSON_UNESCAPED_UNICODE));
        header('Location: ' . $oauth->buildAuthUrl($state));
                exit;
    }
    /** GET /ads/connect/google/callback */
    public function googleAdsOAuthCallback(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $error = $this->get('error');
        if ($error) {
            $this->renderAdsOAuthError('العميل رفض الموافقة أو حصل خطأ من Google: ' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $code = $this->get('code');
        $state = $this->get('state');
        if (!$code || !$state) {
            $this->renderAdsOAuthError('رد غير مكتمل من Google');
            exit;
        }

        $decodedState = json_decode(base64_decode((string) $state), true);
        $expectedNonce = $_SESSION['google_ads_oauth_nonce'] ?? null;

        if (!$decodedState || !$expectedNonce || !hash_equals($expectedNonce, $decodedState['nonce'] ?? '')) {
            $this->renderAdsOAuthError('انتهت صلاحية الجلسة أو محاولة غير موثوقة، جرّب تربط الحساب تاني');
            exit;
        }

        $oauth = new GoogleOAuthClient(GoogleOAuthClient::SCOPE_ADS, env('GOOGLE_ADS_OAUTH_REDIRECT_URI') ?: null);
        $tokenResult = $oauth->exchangeCodeForTokens((string) $code);

        if (!$tokenResult['success']) {
            $this->renderAdsOAuthError('فشل تبادل التوكن مع Google: ' . htmlspecialchars($tokenResult['error'] ?? '', ENT_QUOTES, 'UTF-8'));
            exit;
        }
        if (empty($tokenResult['refresh_token'])) {
            $this->renderAdsOAuthError('Google ما رجعش refresh_token (محتاج تفصل أي ربط سابق لنفس الحساب من "Third-party apps & services" في إعدادات جوجل بتاعتك، ثم تحاول الربط تاني).');
            exit;
        }

        $_SESSION['google_ads_oauth_temp'] = [
            'access_token' => $tokenResult['access_token'],
            'refresh_token' => $tokenResult['refresh_token'],
            'expires_in' => $tokenResult['expires_in'],
        ];
        unset($_SESSION['google_ads_oauth_nonce']);

        header('Location: /ads/connect/google/choose');
                exit;
    }
    /** GET /ads/connect/google/choose - يختار العميل حساب Google Ads بتاعه */
    public function showGoogleAdsAccountPicker(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $temp = $_SESSION['google_ads_oauth_temp'] ?? null;
        if (!$temp) {
            header('Location: /ads');
            exit;
        }

        $api = new GoogleAdsAPI($temp['access_token']);
        if (!$api->isConfigured()) {
            $this->renderAdsOAuthError('GOOGLE_ADS_DEVELOPER_TOKEN لسه مش مضبوط في إعدادات السيرفر - لازم Developer Token معتمد من Google قبل ما تقدر تسحب حسابات Google Ads حقيقية. راجع تعليقات app/Services/Ads/GoogleAdsAPI.php.');
            exit;
        }

        $accountsResult = $api->listAccessibleCustomers();
        if (!$accountsResult['success'] || empty($accountsResult['accounts'])) {
            $this->renderAdsOAuthError('مفيش حسابات Google Ads متاحة للحساب ده. تأكد إن عندك صلاحية على حساب إعلانات Google Ads بنفس الإيميل ده.<br><br>تفاصيل تقنية: ' . htmlspecialchars($accountsResult['error'] ?? '', ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $optionsHtml = '';
        foreach ($accountsResult['accounts'] as $acc) {
            $id = htmlspecialchars($acc['id'], ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars($acc['name'], ENT_QUOTES, 'UTF-8');
            $currency = htmlspecialchars($acc['currency'], ENT_QUOTES, 'UTF-8');
            $optionsHtml .= "<button class=\"p-btn outline\" style=\"width:100%;text-align:start;margin-bottom:8px;\" onclick=\"chooseGoogleAdsAccountBtn('{$id}')\">{$name} <span class=\"p-cell-muted\">({$currency})</span></button>";
        }

        $body = $this->renderView('ads/account_picker', [
            'pickerTitle' => 'اختار حساب Google Ads',
            'pickerSubtitle' => 'هنربط حملاتك الحقيقية من الحساب ده',
            'pickerOptions' => $optionsHtml,
        ]);
        $script = '<script src="' . asset_v('/assets/js/ads/google_picker.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');

        echo $this->renderPanelPage('ads', 'اختيار حساب Google Ads', '', $body, $script);
        exit;
    }
    /** POST /api/ads/google/choose-account */
    public function chooseGoogleAdsAccount(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $temp = $_SESSION['google_ads_oauth_temp'] ?? null;
        if (!$temp) {
            return $this->error('انتهت الجلسة، ابدأ الربط تاني', 400);
        }

        $accountId = $this->get('account_id');
        if (!$accountId) {
            return $this->error('account_id مطلوب', 422);
        }

        try {
            $website = $this->firstWebsiteForUser((int) $this->user['id']);
            if (!$website) {
                return $this->error('لازم يكون عندك موقع مضاف الأول من صفحة "المواقع"', 422);
            }

            $encryption = new Encryption();
            $encryptedAccess = $encryption->encrypt($temp['access_token']);
            $encryptedRefresh = $encryption->encrypt($temp['refresh_token']);
            $expiresAt = date('Y-m-d H:i:s', time() + (int) $temp['expires_in']);

            $existing = $this->db->query(
                "SELECT id FROM platform_connections WHERE website_id = ? AND platform = 'google_ads' LIMIT 1",
                [$website['id']]
            );

            if (!empty($existing)) {
                $this->db->exec(
                    "UPDATE platform_connections SET access_token = ?, refresh_token = ?, token_expires_at = ?, external_account_id = ?, status = 'connected', last_error = NULL, connected_at = NOW() WHERE id = ?",
                    [$encryptedAccess, $encryptedRefresh, $expiresAt, $accountId, $existing[0]['id']]
                );
            } else {
                $this->db->exec(
                    "INSERT INTO platform_connections (website_id, user_id, platform, access_token, refresh_token, token_expires_at, external_account_id, status)
                     VALUES (?, ?, 'google_ads', ?, ?, ?, ?, 'connected')",
                    [$website['id'], $this->user['id'], $encryptedAccess, $encryptedRefresh, $expiresAt, $accountId]
                );
            }

            unset($_SESSION['google_ads_oauth_temp']);
            return $this->success([], 'تم ربط حساب Google Ads');
        } catch (Exception $e) {
            Logger::error('chooseGoogleAdsAccount Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حفظ الربط', 500);
        }
    }

    /** GET /api/ads/google/status */
    public function getGoogleAdsConnectionStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $configured = (new GoogleOAuthClient(GoogleOAuthClient::SCOPE_ADS, env('GOOGLE_ADS_OAUTH_REDIRECT_URI') ?: null))->isConfigured()
            && (env('GOOGLE_ADS_DEVELOPER_TOKEN') ?: '') !== '';

        if (!$configured) {
            return $this->success(['configured' => false, 'connected' => false]);
        }

        try {
            $row = $this->db->query(
                "SELECT external_account_id FROM platform_connections
                 WHERE user_id = ? AND platform = 'google_ads' AND status = 'connected' LIMIT 1",
                [$access['owner_id']]
            );

            if (empty($row)) {
                return $this->success(['configured' => true, 'connected' => false]);
            }

            return $this->success([
                'configured' => true,
                'connected' => true,
                'external_account_id' => $row[0]['external_account_id'],
            ]);
        } catch (Exception $e) {
            Logger::error('getGoogleAdsConnectionStatus Error', ['message' => $e->getMessage()]);
            return $this->success(['configured' => true, 'connected' => false]);
        }
    }

    /**
     * بيرجّع access_token صالح (يجدّده عبر refresh_token المخزّن لو قرب
     * ينتهي)، ويحدّث platform_connections لو حصل تجديد. Google Ads access
     * token عمره ساعة تقريبًا، على عكس Meta اللي بيدي توكن طويل العمر
     * (60 يوم) - عشان كده Meta مش محتاجة نفس منطق التجديد ده حاليًا.
     */
    private function getValidGoogleAdsAccessToken(array $conn, Encryption $encryption): ?string
    {
        $expiresAt = $conn['token_expires_at'] ?? null;
        $stillValid = $expiresAt && strtotime($expiresAt) > (time() + 120);

        $accessToken = $encryption->decrypt((string) $conn['access_token']);
        if ($stillValid) {
            return $accessToken;
        }

        $refreshToken = $encryption->decrypt((string) $conn['refresh_token']);
        if ($refreshToken === '') {
            return null;
        }

        $oauth = new GoogleOAuthClient(GoogleOAuthClient::SCOPE_ADS, env('GOOGLE_ADS_OAUTH_REDIRECT_URI') ?: null);
        $refreshed = $oauth->refreshAccessToken($refreshToken);
        if (!$refreshed['success']) {
            $this->db->exec("UPDATE platform_connections SET status = 'token_expired', last_error = ? WHERE id = ?", [$refreshed['error'] ?? 'refresh failed', $conn['id']]);
            return null;
        }

        $newAccessToken = $refreshed['access_token'];
        $newExpiresAt = date('Y-m-d H:i:s', time() + (int) $refreshed['expires_in']);
        $this->db->exec(
            "UPDATE platform_connections SET access_token = ?, token_expires_at = ? WHERE id = ?",
            [$encryption->encrypt($newAccessToken), $newExpiresAt, $conn['id']]
        );

        return $newAccessToken;
    }

    /** POST /api/ads/google/sync - سحب حملات حقيقية من Google Ads وتحديث ad_campaigns */
    public function syncGoogleAdsCampaigns(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        try {
            $connection = $this->db->query(
                "SELECT id, website_id, access_token, refresh_token, token_expires_at, external_account_id FROM platform_connections
                 WHERE user_id = ? AND platform = 'google_ads' AND status = 'connected' LIMIT 1",
                [$access['owner_id']]
            );

            if (empty($connection)) {
                return $this->error('مفيش حساب Google Ads مربوط', 400);
            }

            $conn = $connection[0];
            $encryption = new Encryption();
            $accessToken = $this->getValidGoogleAdsAccessToken($conn, $encryption);
            if (!$accessToken) {
                return $this->error('انتهت صلاحية الربط، محتاج تربط حساب Google Ads تاني', 400);
            }

            $api = new GoogleAdsAPI($accessToken);
            if (!$api->isConfigured()) {
                return $this->error('GOOGLE_ADS_DEVELOPER_TOKEN غير مضبوط في إعدادات السيرفر', 500);
            }

            $result = $api->listCampaignsWithMetrics($conn['external_account_id']);
            if (!$result['success']) {
                $this->db->exec("UPDATE platform_connections SET status = 'error', last_error = ? WHERE id = ?", [$result['error'] ?? 'unknown error', $conn['id']]);
                if (class_exists('Notification')) {
                    Notification::notify($access['owner_id'], 'ads_integration_error', 'تعذّرت مزامنة Google Ads', (string) ($result['error'] ?? ''), '/ads/connections');
                }
                return $this->error('تعذرت المزامنة مع Google Ads: ' . ($result['error'] ?? ''), 502);
            }

            $synced = 0;
            foreach ($result['campaigns'] as $c) {
                $existing = $this->db->query(
                    "SELECT id FROM ad_campaigns WHERE user_id = ? AND external_campaign_id = ? LIMIT 1",
                    [$access['owner_id'], $c['external_campaign_id']]
                );

                if (!empty($existing)) {
                    $this->db->exec(
                        "UPDATE ad_campaigns SET name = ?, objective = ?, daily_budget = ?, status = ?, impressions = ?, clicks = ?, spend = ?, external_budget_resource_name = ?, updated_at = NOW() WHERE id = ?",
                        [$c['name'], $c['objective'], $c['daily_budget'], $c['status'], $c['impressions'], $c['clicks'], $c['spend'], $c['budget_resource_name'], $existing[0]['id']]
                    );
                } else {
                    $this->db->exec(
                        "INSERT INTO ad_campaigns (user_id, website_id, platform_connection_id, name, objective, daily_budget, status, external_campaign_id, external_budget_resource_name, impressions, clicks, spend)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$access['owner_id'], $conn['website_id'], $conn['id'], $c['name'], $c['objective'], $c['daily_budget'], $c['status'], $c['external_campaign_id'], $c['budget_resource_name'], $c['impressions'], $c['clicks'], $c['spend']]
                    );
                }
                $synced++;
            }

            $this->db->exec("UPDATE platform_connections SET last_synced_at = NOW(), status = 'connected', last_error = NULL WHERE id = ?", [$conn['id']]);

            return $this->success(['synced' => $synced]);
        } catch (Exception $e) {
            Logger::error('syncGoogleAdsCampaigns Error', ['message' => $e->getMessage()]);
            return $this->error('تعذرت المزامنة', 500);
        }
    }

    /** POST /api/ads/google/disconnect */
    public function disconnectGoogleAds(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('admin');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        try {
            $this->db->exec(
                "UPDATE platform_connections SET status = 'disconnected', access_token = NULL, refresh_token = NULL WHERE user_id = ? AND platform = 'google_ads'",
                [$access['owner_id']]
            );
            return $this->success([], 'تم فصل الربط');
        } catch (Exception $e) {
            Logger::error('disconnectGoogleAds Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الفصل', 500);
        }
    }

    // ================================================================
    // AI Ads Autopilot - Guardrails / Pending Approvals / Log / Rollback
    // ================================================================

    /** GET /api/ads/autopilot/settings */
    public function getAutopilotSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $engine = new AdAutopilotEngine();
        $settings = $engine->getSettings($access['owner_id']);

        return $this->success([
            'optimization_mode' => $settings->getAttribute('optimization_mode'),
            'max_daily_budget' => $settings->getAttribute('max_daily_budget'),
            'max_budget_increase_pct' => $settings->getAttribute('max_budget_increase_pct'),
            'max_budget_decrease_pct' => $settings->getAttribute('max_budget_decrease_pct'),
            'max_allowed_cpa' => $settings->getAttribute('max_allowed_cpa'),
            'min_required_roas' => $settings->getAttribute('min_required_roas'),
            'max_changes_per_day' => $settings->getAttribute('max_changes_per_day'),
        ]);
    }

    /** POST /api/ads/autopilot/settings */
    public function saveAutopilotSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $access = $this->resolveAdsAccess('admin');
        if (!$access) {
            return $this->error('محتاج صلاحية Admin لتعديل إعدادات Autopilot (بيتحكم في إنفاق تلقائي حقيقي)', 403);
        }

        try {
            $engine = new AdAutopilotEngine();
            $engine->saveSettings($access['owner_id'], $this->all());
            return $this->success([], 'تم حفظ الإعدادات');
        } catch (Exception $e) {
            Logger::error('saveAutopilotSettings Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الحفظ', 500);
        }
    }

    /** GET /api/ads/autopilot/pending */
    public function listPendingActions(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $rows = AdPendingAction::pendingForUser($access['owner_id']);
        return $this->success(array_map(fn ($p) => $p->toArray(), $rows));
    }

    /** POST /api/ads/autopilot/pending/{id}/approve */
    public function approvePendingAction(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $engine = new AdAutopilotEngine();
        $result = $engine->approvePendingAction($access['owner_id'], (int) $params['id']);

        if (($result['status'] ?? '') === 'not_found') {
            return $this->error('القرار غير موجود أو تم اتخاذ قرار بشأنه بالفعل', 404);
        }
        if (($result['status'] ?? '') === 'executed') {
            return $this->success($result, 'تم التنفيذ فعليًا');
        }
        return $this->success($result, 'تم تسجيل القرار');
    }

    /** POST /api/ads/autopilot/pending/{id}/reject */
    public function rejectPendingAction(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $engine = new AdAutopilotEngine();
        $ok = $engine->rejectPendingAction($access['owner_id'], (int) $params['id']);

        return $ok ? $this->success([], 'تم الرفض') : $this->error('القرار غير موجود أو تم اتخاذ قرار بشأنه بالفعل', 404);
    }

    /** GET /api/ads/autopilot/logs */
    public function listOptimizationLogs(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $campaignId = $this->get('campaign_id');
        if ($campaignId) {
            $rows = (new AdOptimizationLog())->where(['user_id' => $access['owner_id'], 'campaign_id' => (int) $campaignId], ['created_at' => 'DESC'], 50);
        } else {
            $rows = AdOptimizationLog::forUser($access['owner_id'], 50);
        }
        return $this->success(array_map(fn ($l) => $l->toArray(), $rows));
    }

    /** POST /api/ads/autopilot/logs/{id}/rollback */
    public function rollbackOptimizationLog(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $engine = new AdAutopilotEngine();
        $result = $engine->rollback($access['owner_id'], (int) $params['id']);

        if (($result['status'] ?? '') === 'not_found') {
            return $this->error('السجل غير موجود', 404);
        }
        if (($result['status'] ?? '') === 'not_rollbackable') {
            return $this->error('التغيير ده مش قابل للتراجع (إما مش منفّذ فعليًا أو اتراجع عنه قبل كده)', 422);
        }
        if (($result['status'] ?? '') === 'executed') {
            return $this->success($result, 'تم التراجع بنجاح');
        }

        return $this->error('تعذر التراجع: ' . ($result['error'] ?? 'خطأ غير معروف'), 502);
    }

    /** POST /api/ads/autopilot/run - تشغيل يدوي فوري (نفس اللي بيحصل من الـ cron الدوري) */
    public function runAutopilotNow(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $engine = new AdAutopilotEngine();
        $campaigns = (new AdCampaign())->where(['user_id' => $access['owner_id'], 'status' => 'active', 'auto_optimize' => 1]);

        $results = [];
        foreach ($campaigns as $campaign) {
            $results[] = ['campaign_id' => $campaign->getAttribute('id'), 'result' => $engine->processCampaign($access['owner_id'], $campaign)];
        }

        return $this->success($results);
    }

    // ================================================================
    // Proactive Alerts (تنبيهات استباقية)
    // ================================================================

    /** GET /api/ads/alerts/rules */
    public function getAlertRules(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $service = new AdAlertService();
        return $this->success(['rules' => $service->getRules($access['owner_id'])]);
    }

    /** POST /api/ads/alerts/rules */
    public function saveAlertRules(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        try {
            $service = new AdAlertService();
            $rules = $service->saveRules($access['owner_id'], $this->all());
            return $this->success(['rules' => $rules], 'تم حفظ قواعد التنبيهات');
        } catch (Exception $e) {
            Logger::error('saveAlertRules Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الحفظ', 500);
        }
    }

    /** GET /api/ads/alerts */
    public function listAlerts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $limit = max(1, min(200, (int) $this->get('limit', 50)));
        $unreadOnly = (bool) $this->get('unread_only', false);

        $service = new AdAlertService();
        return $this->success([
            'alerts' => $service->listForUser($access['owner_id'], $limit, $unreadOnly),
            'unread_count' => $service->unreadCount($access['owner_id']),
        ]);
    }

    /** POST /api/ads/alerts/run - تقييم فوري لكل الحملات النشطة */
    public function runAlertsNow(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        try {
            $service = new AdAlertService();
            $result = $service->evaluateForUser($access['owner_id']);
            return $this->success($result, 'تم التقييم');
        } catch (Exception $e) {
            Logger::error('runAlertsNow Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التقييم', 500);
        }
    }

    /** POST /api/ads/alerts/read-all */
    public function markAllAlertsRead(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $service = new AdAlertService();
        $service->markAllRead($access['owner_id']);
        return $this->success([], 'تم تعليم الكل كمقروء');
    }

    /** POST /api/ads/alerts/{id}/dismiss */
    public function dismissAlert(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $service = new AdAlertService();
        $ok = $service->dismiss($access['owner_id'], (int) ($params['id'] ?? 0));
        return $ok ? $this->success([], 'تم تجاهل التنبيه') : $this->error('التنبيه غير موجود', 404);
    }

    // ================================================================
    // AI Marketing Copilot
    // ================================================================

    /** POST /api/ads/copilot/ask */
    public function askCopilot(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $message = trim((string) $this->get('message', ''));
        if ($message === '') {
            return $this->error('اكتب سؤال أو طلب الأول', 422);
        }

        try {
            $copilot = new AdsCopilotService();
            $result = $copilot->ask($access['owner_id'], $message);
            return $this->success($result);
        } catch (Exception $e) {
            Logger::error('askCopilot Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر معالجة الطلب', 500);
        }
    }

    // ================================================================
    // AI Keyword Strategist (البند 6)
    // ================================================================

    /** POST /api/ads/campaigns/{id}/keywords/generate */
    public function generateKeywords(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $goalDescription = trim((string) $this->get('goal_description', (string) $campaign->getAttribute('product_or_service')));
        if ($goalDescription === '') {
            return $this->error('اكتب وصف مختصر للعرض الأول', 422);
        }

        try {
            $service = new AdKeywordStrategistService();
            $result = $service->generateForCampaign($campaign, $goalDescription, $this->get('target_country'));
            return $this->success($result);
        } catch (Exception $e) {
            Logger::error('generateKeywords Error', ['message' => $e->getMessage()]);
            return $this->error($e->getMessage(), 502);
        }
    }

    /** GET /api/ads/campaigns/{id}/keywords */
    public function listKeywords(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'viewer')) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $keywords = (new AdKeyword())->where(['campaign_id' => (int) $campaign->getAttribute('id')], ['created_at' => 'DESC']);
        return $this->success(array_map(fn ($k) => $k->toArray(), $keywords));
    }

    // ================================================================
    // Ad Groups (البند 6) - تنظيم محلي داخل Tourfecto، راجع ملحوظة
    // migration 2026_08_11_000044 عن النطاق (مش مزامنة حقيقية مع Ad
    // Set/Ad Group على Meta/Google - العميل عنده حرية التنظيم الداخلي بس)
    // ================================================================

    /** POST /api/ads/campaigns/{id}/ad-groups */
    public function createAdGroup(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $name = trim((string) $this->get('name', ''));
        if ($name === '') {
            return $this->error('اسم المجموعة الإعلانية مطلوب', 422);
        }

        $budgetPct = $this->get('budget_allocation_pct');

        $group = new AdAdGroup([
            'campaign_id' => (int) $campaign->getAttribute('id'),
            'name' => mb_substr($name, 0, 255),
            'status' => 'active',
            'budget_allocation_pct' => ($budgetPct !== null && $budgetPct !== '') ? (float) $budgetPct : null,
        ]);
        $group->save();

        ActivityLog::record('ads_autopilot', 'ad_group.created', [
            'user_id' => (int) $this->user['id'], 'subject_type' => 'ad_ad_groups', 'subject_id' => (int) $group->getAttribute('id'),
            'meta' => ['campaign_id' => (int) $campaign->getAttribute('id')],
        ]);

        return $this->success($group->toArray(), 'تم إنشاء المجموعة الإعلانية');
    }

    /** GET /api/ads/campaigns/{id}/ad-groups - مع عدد الكلمات/الإعلانات المرتبطة بكل مجموعة */
    public function listAdGroups(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'viewer')) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $groups = (new AdAdGroup())->where(['campaign_id' => (int) $campaign->getAttribute('id')], ['created_at' => 'DESC']);

        $result = array_map(function ($g) {
            $groupId = (int) $g->getAttribute('id');
            $keywordCount = count((new AdKeyword())->where(['ad_group_id' => $groupId]));
            $copyCount = count((new AdCopy())->where(['ad_group_id' => $groupId]));
            $data = $g->toArray();
            $data['keywords_count'] = $keywordCount;
            $data['ads_count'] = $copyCount;
            return $data;
        }, $groups);

        // ملحوظة صراحة: مفيش "Performance" مستوى المجموعة - ad_performance_reports
        // بيانات على مستوى الحملة بس من المزامنة الحالية، مش مقسّمة لكل Ad Group.
        return $this->success(['ad_groups' => $result, 'performance_note' => 'بيانات الأداء متاحة على مستوى الحملة بس حاليًا، مش مقسّمة لكل مجموعة إعلانية']);
    }

    /** POST /api/ads/ad-groups/{id}/status */
    public function updateAdGroupStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $group = (new AdAdGroup())->find((int) ($params['id'] ?? 0));
        if (!$group) {
            return $this->error('المجموعة الإعلانية غير موجودة', 404);
        }

        $campaign = (new AdCampaign())->find((int) $group->getAttribute('campaign_id'));
        if (!$campaign || !$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('المجموعة الإعلانية غير موجودة', 404);
        }

        $status = $this->get('status');
        if (!in_array($status, ['active', 'paused'], true)) {
            return $this->error('status لازم يكون active أو paused', 422);
        }

        $group->setAttribute('status', $status);
        $group->save();

        return $this->success($group->toArray());
    }

    /** DELETE /api/ads/ad-groups/{id} - الكلمات/الإعلانات المرتبطة بترجع ad_group_id=NULL (مش بتتحذف) */
    public function deleteAdGroup(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $group = (new AdAdGroup())->find((int) ($params['id'] ?? 0));
        if (!$group) {
            return $this->error('المجموعة الإعلانية غير موجودة', 404);
        }

        $campaign = (new AdCampaign())->find((int) $group->getAttribute('campaign_id'));
        if (!$campaign || !$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('المجموعة الإعلانية غير موجودة', 404);
        }

        $this->db->exec("DELETE FROM ad_ad_groups WHERE id = ?", [(int) $group->getAttribute('id')]);

        return $this->success([], 'تم حذف المجموعة الإعلانية');
    }

    /** POST /api/ads/keywords/{id}/assign-group - ربط/فك ربط كلمة مفتاحية بمجموعة إعلانية */
    public function assignKeywordToGroup(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $keyword = (new AdKeyword())->find((int) ($params['id'] ?? 0));
        if (!$keyword) {
            return $this->error('الكلمة المفتاحية غير موجودة', 404);
        }

        $campaign = (new AdCampaign())->find((int) $keyword->getAttribute('campaign_id'));
        if (!$campaign || !$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('الكلمة المفتاحية غير موجودة', 404);
        }

        $adGroupId = $this->get('ad_group_id');
        if ($adGroupId) {
            $group = (new AdAdGroup())->find((int) $adGroupId);
            if (!$group || (int) $group->getAttribute('campaign_id') !== (int) $campaign->getAttribute('id')) {
                return $this->error('المجموعة الإعلانية غير موجودة أو مش تابعة لنفس الحملة', 422);
            }
        }

        $keyword->setAttribute('ad_group_id', $adGroupId ?: null);
        $keyword->save();

        return $this->success($keyword->toArray());
    }

    // ================================================================
    // AI Market / Country Research (البند 5)
    // ================================================================

    /** POST /api/ads/market-research */
    public function marketResearch(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $goalDescription = trim((string) $this->get('goal_description', ''));
        if ($goalDescription === '') {
            return $this->error('اكتب وصف مختصر لعرضك الأول', 422);
        }

        $campaignId = $this->get('campaign_id');
        if ($campaignId) {
            $campaign = (new AdCampaign())->find((int) $campaignId);
            if (!$campaign || (int) $campaign->getAttribute('user_id') !== $access['owner_id']) {
                return $this->error('الحملة غير موجودة', 404);
            }
        }

        try {
            $service = new AdMarketResearchService();
            $result = $service->research($access['owner_id'], $goalDescription, $campaignId ? (int) $campaignId : null);
            return $this->success($result);
        } catch (Exception $e) {
            Logger::error('marketResearch Error', ['message' => $e->getMessage()]);
            return $this->error($e->getMessage(), 502);
        }
    }

    /** GET /api/ads/market-research/history */
    public function marketResearchHistory(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $service = new AdMarketResearchService();
        $rows = $service->history($access['owner_id']);

        return $this->success(array_map(function ($r) {
            $r['result_json'] = json_decode((string) $r['result_json'], true);
            return $r;
        }, $rows));
    }

    // ================================================================
    // Landing Page Analysis (البند 17)
    // ================================================================

    /**
     * POST /api/ads/campaigns/{id}/status - إيقاف/استئناف يدوي مباشر من
     * العميل نفسه. مُختلف عن AdAutopilotEngine::execute() عمدًا: ده فعل
     * بشري صريح على حملة العميل نفسه، مش قرار AI - فمش بيمرّ عبر Guardrails
     * (الحدود دي مصمّمة تحمي من قرارات AI خاطئة، مش من إرادة العميل
     * المباشرة على حملته هو). بيسجّل نفس Audit Trail بالظبط عشان يظهر في
     * سجل النشاط وميزة الـRollback زي أي تغيير تاني.
     */
    public function updateCampaignStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('محتاج صلاحية Manager أو أعلى لتغيير حالة الحملة', 403);
        }

        $newStatus = $this->get('status');
        if (!in_array($newStatus, ['active', 'paused'], true)) {
            return $this->error('status لازم يكون active أو paused', 422);
        }
        if ($campaign->getAttribute('status') === $newStatus) {
            return $this->success([], 'الحملة بالفعل في هذه الحالة');
        }

        $connId = $campaign->getAttribute('platform_connection_id');
        $externalId = (string) $campaign->getAttribute('external_campaign_id');
        if (!$connId || $externalId === '') {
            return $this->error('الحملة دي لسه مش متزامنة مع منصة إعلانية حقيقية', 422);
        }

        try {
            $apiResult = $this->executeCampaignStatusOnPlatform($campaign, $newStatus);

            if (!$apiResult['success']) {
                return $this->error('تعذّر تنفيذ الإجراء على المنصة: ' . ($apiResult['error'] ?? 'خطأ غير معروف'), 502);
            }

            $previousStatus = (string) $campaign->getAttribute('status');
            $campaign->setAttribute('status', $newStatus);
            $campaign->save();

            $log = new AdOptimizationLog([
                'campaign_id' => (int) $campaign->getAttribute('id'),
                'user_id' => (int) $this->user['id'],
                'action_type' => $newStatus === 'paused' ? 'pause_campaign' : 'resume_campaign',
                'mode' => 'manual',
                'description' => 'إيقاف/استئناف يدوي مباشر من العميل عبر لوحة التحكم',
                'before_value' => $previousStatus,
                'after_value' => $newStatus,
                'ai_confidence' => null,
                'applied_automatically' => 1,
                'external_result' => 'success',
                'can_rollback' => 1,
            ]);
            $log->save();

            ActivityLog::record('ads_autopilot', 'campaign.status_changed_manually', [
                'user_id' => (int) $this->user['id'], 'subject_type' => 'ad_campaigns', 'subject_id' => (int) $campaign->getAttribute('id'),
                'meta' => ['before' => $previousStatus, 'after' => $newStatus],
            ]);

            return $this->success(['status' => $newStatus], 'تم تحديث حالة الحملة');
        } catch (Exception $e) {
            Logger::error('updateCampaignStatus Error', ['message' => $e->getMessage()]);
            return $this->error('تعذّر تنفيذ الإجراء', 500);
        }
    }

    /**
     * DELETE /api/ads/campaigns/{id} - بند 3 "Delete إذا الـBackend يسمح".
     * ملحوظة صراحة: Meta/Google Ads API مفيهمش حذف نهائي حقيقي للحملة على
     * المنصة - أقصى حاجة ممكنة تقنيًا هي PAUSED/REMOVED. فـ"الحذف" هنا
     * Soft Delete (إخفاء من قوائم Tourfecto مع الحفاظ الكامل على بيانات
     * الأداء/السجل التاريخية)، + إيقاف فعلي على المنصة الحقيقية أولًا لو
     * كانت الحملة شغّالة (أمان إضافي - نفس منطق updateCampaignStatus).
     */
    public function deleteCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign || $campaign->getAttribute('deleted_at')) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('محتاج صلاحية Manager أو أعلى لحذف الحملة', 403);
        }

        try {
            // لو الحملة شغّالة وفعليًا متزامنة مع منصة حقيقية - نوقفها هناك
            // الأول قبل ما نخفيها من واجهة العميل (منعًا لاستمرار إنفاق
            // فعلي على حملة اختفت من لوحة تحكمه).
            if ($campaign->getAttribute('status') === 'active' && $campaign->getAttribute('platform_connection_id') && $campaign->getAttribute('external_campaign_id')) {
                $pauseResult = $this->pauseCampaignOnPlatform($campaign);
                if (!$pauseResult['success']) {
                    return $this->error('تعذّر إيقاف الحملة على المنصة قبل حذفها: ' . ($pauseResult['error'] ?? 'خطأ غير معروف') . ' - جرّب توقفها يدويًا الأول', 502);
                }
                $campaign->setAttribute('status', 'paused');
            }

            $campaign->setAttribute('deleted_at', date('Y-m-d H:i:s'));
            $campaign->save();

            ActivityLog::record('ads_autopilot', 'campaign.deleted', [
                'user_id' => (int) $this->user['id'], 'subject_type' => 'ad_campaigns', 'subject_id' => (int) $campaign->getAttribute('id'),
            ]);

            return $this->success([], 'تم حذف الحملة (بياناتها التاريخية محفوظة، والحملة الحقيقية على المنصة أُوقفت لو كانت شغّالة)');
        } catch (Exception $e) {
            Logger::error('deleteCampaign Error', ['message' => $e->getMessage()]);
            return $this->error('تعذّر حذف الحملة', 500);
        }
    }

    /**
     * POST /api/ads/campaigns/bulk-status - بند 3 "Bulk Selection إذا
     * الـBackend يسمح". إيقاف/استئناف عدة حملات مرة واحدة - كل حملة بتتفحص
     * ملكيتها لوحدها وبتتنفذ عليها نفس منطق updateCampaignStatus بالظبط،
     * مفيش أي تجاوز أمان جماعي.
     */
    public function bulkUpdateCampaignStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $ids = $this->get('campaign_ids');
        $newStatus = $this->get('status');
        if (!is_array($ids) || empty($ids)) {
            return $this->error('campaign_ids مطلوبة (مصفوفة)', 422);
        }
        if (!in_array($newStatus, ['active', 'paused'], true)) {
            return $this->error('status لازم يكون active أو paused', 422);
        }

        $results = [];
        foreach (array_slice($ids, 0, 50) as $id) {
            $campaign = (new AdCampaign())->find((int) $id);
            if (!$campaign || $campaign->getAttribute('deleted_at') || !$this->resolveCampaignAccess($campaign, 'manager')) {
                $results[] = ['campaign_id' => $id, 'success' => false, 'error' => 'غير موجودة'];
                continue;
            }
            if ($campaign->getAttribute('status') === $newStatus) {
                $results[] = ['campaign_id' => $id, 'success' => true, 'note' => 'already in this status'];
                continue;
            }

            $connId = $campaign->getAttribute('platform_connection_id');
            $externalId = (string) $campaign->getAttribute('external_campaign_id');
            if (!$connId || $externalId === '') {
                $results[] = ['campaign_id' => $id, 'success' => false, 'error' => 'لسه مش متزامنة مع منصة حقيقية'];
                continue;
            }

            $apiResult = $newStatus === 'paused' ? $this->pauseCampaignOnPlatform($campaign) : $this->resumeCampaignOnPlatform($campaign);
            if (!$apiResult['success']) {
                $results[] = ['campaign_id' => $id, 'success' => false, 'error' => $apiResult['error'] ?? 'خطأ غير معروف'];
                continue;
            }

            $campaign->setAttribute('status', $newStatus);
            $campaign->save();

            $log = new AdOptimizationLog([
                'campaign_id' => (int) $campaign->getAttribute('id'), 'user_id' => (int) $this->user['id'],
                'action_type' => $newStatus === 'paused' ? 'pause_campaign' : 'resume_campaign', 'mode' => 'manual',
                'description' => 'إجراء جماعي (Bulk) من العميل', 'applied_automatically' => 1, 'external_result' => 'success',
            ]);
            $log->save();

            $results[] = ['campaign_id' => $id, 'success' => true];
        }

        return $this->success(['results' => $results]);
    }

    /** يستخدم نفس منطق التنفيذ في updateCampaignStatus - مستخرج كـhelper عشان يُستخدم من deleteCampaign() وbulkUpdateCampaignStatus() كمان */
    private function pauseCampaignOnPlatform(AdCampaign $campaign): array
    {
        return $this->executeCampaignStatusOnPlatform($campaign, 'paused');
    }

    private function resumeCampaignOnPlatform(AdCampaign $campaign): array
    {
        return $this->executeCampaignStatusOnPlatform($campaign, 'active');
    }

    private function executeCampaignStatusOnPlatform(AdCampaign $campaign, string $newStatus): array
    {
        $connId = $campaign->getAttribute('platform_connection_id');
        $externalId = (string) $campaign->getAttribute('external_campaign_id');
        if (!$connId || $externalId === '') {
            return ['success' => false, 'error' => 'الحملة مش متزامنة مع منصة حقيقية'];
        }

        $conn = (new PlatformConnection())->find((int) $connId);
        if (!$conn || $conn->getAttribute('status') !== 'connected') {
            return ['success' => false, 'error' => 'الربط بالمنصة غير متاح'];
        }

        $encryption = new Encryption();
        $accessToken = $encryption->decrypt((string) $conn->getAttribute('access_token'));
        $platform = (string) $conn->getAttribute('platform');

        if ($platform === 'meta_ads') {
            $api = new MetaAdsAPI($accessToken);
            return $newStatus === 'paused' ? $api->pauseCampaign($externalId) : $api->resumeCampaign($externalId);
        }
        if ($platform === 'google_ads') {
            $api = new GoogleAdsAPI($accessToken);
            $customerId = (string) $conn->getAttribute('external_account_id');
            $campaignResourceName = "customers/{$customerId}/campaigns/{$externalId}";
            return $newStatus === 'paused' ? $api->pauseCampaign($customerId, $campaignResourceName) : $api->resumeCampaign($customerId, $campaignResourceName);
        }
        return ['success' => false, 'error' => "منصة غير مدعومة: {$platform}"];
    }

    /** POST /api/ads/campaigns/{id}/landing-page/analyze */
    public function analyzeLandingPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $url = trim((string) $this->get('url', (string) $campaign->getAttribute('landing_page_url')));
        if ($url === '') {
            return $this->error('حدد رابط صفحة الهبوط الأول', 422);
        }

        try {
            $service = new LandingPageAnalysisService();
            $result = $service->analyze($url, (string) $campaign->getAttribute('product_or_service'));

            if ($result['fetch_error'] === null) {
                $campaign->setAttribute('landing_page_url', $url);
                $campaign->setAttribute('landing_page_last_analysis', json_encode($result, JSON_UNESCAPED_UNICODE));
                $campaign->setAttribute('landing_page_analyzed_at', date('Y-m-d H:i:s'));
                $campaign->save();

                ActivityLog::record('ads_autopilot', 'landing_page.analyzed', [
                    'user_id' => (int) $this->user['id'], 'subject_type' => 'ad_campaigns', 'subject_id' => (int) $campaign->getAttribute('id'),
                ]);
            }

            return $this->success($result);
        } catch (Exception $e) {
            Logger::error('analyzeLandingPage Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحليل الصفحة', 500);
        }
    }

    // ================================================================
    // UTM Tracking (البند 18)
    // ================================================================

    /** POST /api/ads/campaigns/{id}/utm-links */
    public function createUtmLink(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'manager')) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $destinationUrl = trim((string) $this->get('destination_url', ''));
        $utmSource = trim((string) $this->get('utm_source', 'google'));
        $utmMedium = trim((string) $this->get('utm_medium', 'cpc'));

        if ($destinationUrl === '') {
            return $this->error('رابط الوجهة مطلوب', 422);
        }

        try {
            $service = new AdTrackingService();
            $result = $service->buildLink(
                (int) $campaign->getAttribute('user_id'),
                $campaign,
                $destinationUrl,
                $utmSource,
                $utmMedium,
                $this->get('utm_content'),
                $this->get('utm_term')
            );
            return $this->success($result);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Exception $e) {
            Logger::error('createUtmLink Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنشاء الرابط', 500);
        }
    }

    /** GET /api/ads/campaigns/{id}/utm-links */
    public function listUtmLinks(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!$this->resolveCampaignAccess($campaign, 'viewer')) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $service = new AdTrackingService();
        return $this->success($service->listForCampaign((int) $campaign->getAttribute('id')));
    }

    /**
     * GET /r/{code} - رابط عام (بدون تسجيل دخول) بيسجّل نقرة حقيقية على
     * رابط UTM ثم يحوّل الزائر لصفحة الهبوط الفعلية. مقصود إنه ما يستخدمش
     * isAuthenticated() هنا لأن اللي بيضغط عليه زائر من إعلان، مش عميل
     * مسجّل دخول بالضرورة.
     */
    public function redirectUtmClick(array $params = []): array
    {
        $code = (string) ($params['code'] ?? '');
        $service = new AdTrackingService();
        $tracked = $service->resolveAndTrackClick($code);

        if (!$tracked) {
            http_response_code(404);
            echo 'الرابط غير صالح أو منتهي';
            exit;
        }

        // نخزّن إسناد النقرة (رابط UTM + المنصة) لمدة 30 يوم قبل التحويل -
        // أي حجز قادم من الزائر ده هيتم تنسبه تلقائيًا للرابط الإعلاني
        // (البيانات المخزنة معرّف الرابط بس، مش أي بيانات شخصية).
        $service->storeAttribution($tracked['utm_link_id'], $tracked['platform']);

        header('Location: ' . $tracked['destination'], true, 302);
        exit;
    }

    // ================================================================
    // Automated Reports (البند 21)
    // ================================================================

    /** GET /api/ads/dashboard/summary?period=&platform=&status= */
    public function getDashboardSummary(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $period = in_array($this->get('period'), ['daily', 'weekly', 'monthly'], true) ? $this->get('period') : 'weekly';
        $platform = $this->get('platform') ?: null;
        $status = $this->get('status') ?: null;

        $service = new AdReportService();
        return $this->success($service->dashboardSummary($access['owner_id'], $period, $platform, $status));
    }

    /** GET /api/ads/reports/trend?days=&campaign_id= */
    public function getReportTrend(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $days = max(1, min(90, (int) ($this->get('days', 30))));
        $campaignId = $this->get('campaign_id') ? (int) $this->get('campaign_id') : null;
        if ($campaignId !== null) {
            $campaign = (new AdCampaign())->find($campaignId);
            if (!$campaign || (int) $campaign->getAttribute('user_id') !== $access['owner_id']) {
                return $this->error('الحملة غير موجودة', 404);
            }
        }
        $service = new AdReportService();
        return $this->success($service->dailyTrend($access['owner_id'], $days, $campaignId));
    }

    /** GET /api/ads/reports/comparison?period= */
    public function getCampaignComparison(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $period = in_array($this->get('period'), ['daily', 'weekly', 'monthly'], true) ? $this->get('period') : 'weekly';
        $service = new AdReportService();
        return $this->success($service->campaignComparison($access['owner_id'], $period));
    }

    /** GET /api/ads/reports?period=daily|weekly|monthly */
    public function getReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $period = in_array($this->get('period'), ['daily', 'weekly', 'monthly'], true) ? $this->get('period') : 'weekly';

        $service = new AdReportService();
        return $this->success($service->generate($access['owner_id'], $period));
    }

    // ================================================================
    // Ads Competitor Insights (البند 16)
    // ================================================================

    /** POST /api/ads/competitors/{id}/analyze */
    public function analyzeAdsCompetitor(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $competitor = (new Competitor())->find((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('المنافس غير موجود', 404);
        }

        $access = $this->resolveAdsAccessForOwner((int) $competitor->getAttribute('user_id'), 'manager');
        if (!$access) {
            return $this->error('المنافس غير موجود', 404);
        }

        $offerDescription = trim((string) $this->get('offer_description', ''));
        if ($offerDescription === '') {
            return $this->error('اكتب وصف مختصر لعرضك الأول', 422);
        }

        try {
            $service = new AdsCompetitorInsightsService();
            return $this->success($service->analyzeForAds($competitor, $offerDescription));
        } catch (Exception $e) {
            Logger::error('analyzeAdsCompetitor Error', ['message' => $e->getMessage()]);
            return $this->error($e->getMessage(), 502);
        }
    }

    /** GET /api/ads/competitors/{id}/insights */
    public function listAdsCompetitorInsights(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $competitor = (new Competitor())->find((int) ($params['id'] ?? 0));
        if (!$competitor) {
            return $this->error('المنافس غير موجود', 404);
        }

        if (!$this->resolveAdsAccessForOwner((int) $competitor->getAttribute('user_id'), 'viewer')) {
            return $this->error('المنافس غير موجود', 404);
        }

        $service = new AdsCompetitorInsightsService();
        return $this->success($service->listForWebsite((int) $competitor->getAttribute('website_id')));
    }

    /** GET /api/ads/competitors - قائمة المنافسين المسجّلين لهذا العميل (لملء قائمة الاختيار في صفحة المنافسين) */
    public function listMyCompetitors(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $rows = (new Competitor())->where(['user_id' => $access['owner_id'], 'is_active' => 1], ['created_at' => 'DESC']);
        return $this->success(array_map(fn ($c) => $c->toArray(), $rows));
    }

    // ================================================================
    // Team Permissions (البند 27) - Viewer/Manager/Admin
    // إدارة الفريق نفسها متاحة لصاحب الحساب أو Admin بس (Manager/Viewer
    // ماينفعش يضيفوا/يشيلوا أعضاء تانيين - ده تحكّم على مستوى الحساب نفسه)
    // ================================================================

    /** GET /api/ads/team - قائمة أعضاء الفريق على حسابي (لو أنا Owner) */
    public function listTeamMembers(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $perm = new AdPermissionService();
        $members = $perm->listMembers($access['owner_id']);
        $accountsIBelongTo = $perm->accountsUserBelongsTo((int) $this->user['id']);

        return $this->success(['members' => $members, 'accounts_i_belong_to' => $accountsIBelongTo]);
    }

    /** POST /api/ads/team - إضافة عضو (بإيميله - لازم يكون له حساب Tourfecto بالفعل) */
    public function addTeamMember(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('admin');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $email = trim((string) $this->get('email', ''));
        $role = $this->get('role', 'viewer');
        if ($email === '') {
            return $this->error('اكتب إيميل العضو', 422);
        }

        $perm = new AdPermissionService();
        $result = $perm->addMemberByEmail($access['owner_id'], $email, $role, (int) $this->user['id']);

        if (!$result['success']) {
            return $this->error($result['error'], 422);
        }

        ActivityLog::record('ads_autopilot', 'team.member_added', [
            'user_id' => (int) $this->user['id'], 'meta' => ['email' => $email, 'role' => $role],
        ]);

        return $this->success([], 'تم إضافة العضو');
    }

    /** POST /api/ads/team/{id}/role */
    public function updateTeamMemberRole(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('admin');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $newRole = $this->get('role');
        $perm = new AdPermissionService();
        $ok = $perm->updateMemberRole($access['owner_id'], (int) ($params['id'] ?? 0), (string) $newRole);

        return $ok ? $this->success([], 'تم تحديث الدور') : $this->error('تعذّر التحديث - تأكد من الدور والعضو', 422);
    }

    /** POST /api/ads/team/{id}/remove */
    public function removeTeamMember(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('admin');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $perm = new AdPermissionService();
        $ok = $perm->removeMember($access['owner_id'], (int) ($params['id'] ?? 0));

        return $ok ? $this->success([], 'تم إزالة العضو') : $this->error('تعذّرت الإزالة', 422);
    }

    /** GET /ads/team */
    public function showTeamPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads/team'));
            exit;
        }

        $body = $this->renderView('ads/team', ['adsActive' => 'team']);
        $script = '<script src="' . asset_v('/assets/js/ads/team.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');

        echo $this->renderPanelPage('ads', 'فريق العمل', 'إدارة أعضاء الفريق وصلاحياتهم على موديول الإعلانات', $body, $script);
        exit;
    }
    /**
     * شريط تنقّل فرعي داخلي لكل صفحات الإعلانات (نفس نمط crmTabsHtml في
     * CrmController.php بالظبط - مفيش عنصر Sidebar عام جديد، الـ"ads"
     * الموجود بالفعل في القائمة الجانبية بيفضل نشط لكل الصفحات دي).
     * لينكات #anchor بترجع لنفس صفحة /ads (الأقسام لسه هناك، مجمّعة في
     * صفحة واحدة لتقليل مخاطر فصلها) - باقي اللينكات صفحات مستقلة فعلية.
     */
    /** GET /ads/reports */
    public function showReportsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads/reports'));
            exit;
        }

        $body = $this->renderView('ads/reports', ['adsActive' => 'reports']);
        $script = '<script src="' . asset_v('/assets/js/ads/reports.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');

        echo $this->renderPanelPage('ads', 'تقارير الأداء', 'اتجاه الأداء اليومي والتقارير الدورية والإسناد', $body, $script);
        exit;
    }
    /** GET /ads/budget */
    public function showBudgetPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads/budget'));
            exit;
        }

        $body = $this->renderView('ads/budget', ['adsActive' => 'budget']);
        $script = '<script src="' . asset_v('/assets/js/ads/budget.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');

        echo $this->renderPanelPage('ads', 'الميزانية والإنفاق', 'اتجاه الإنفاق ومقارنة أداء الحملات', $body, $script);
        exit;
    }
    /** GET /ads/campaigns/{id} */
    public function showCampaignDetailsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads'));
            exit;
        }

        $campaignId = (int) ($params['id'] ?? 0);
        $campaign = (new AdCampaign())->find($campaignId);
        if (!$campaign || !$this->resolveCampaignAccess($campaign, 'viewer')) {
            header('Location: /ads');
            exit;
        }

        $body = $this->renderView('ads/campaign_details', ['adsActive' => 'campaigns', 'campaignId' => $campaignId]);
        $script = '<script src="' . asset_v('/assets/js/ads/campaign_details.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');

        echo $this->renderPanelPage('ads', 'تفاصيل الحملة', 'نظرة شاملة على أداء واستهداف وإعدادات الحملة', $body, $script);
        exit;
    }
    /** GET /ads/competitors */
    public function showCompetitorsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads/competitors'));
            exit;
        }

        $body = $this->renderView('ads/competitors', ['adsActive' => 'competitors']);
        $script = '<script src="' . asset_v('/assets/js/ads/competitors.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');

        echo $this->renderPanelPage('ads', 'المنافسون', 'تحليل رسائل وتموضع المنافسين من منظور إعلاني', $body, $script);
        exit;
    }
    /** GET /api/ads/connections/status - تفاصيل كاملة لحالة ربط Google Ads وMeta Ads معًا (Connection Center) */
    public function getConnectionsStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $rows = $this->db->query(
            "SELECT platform, status, external_account_id, last_error, last_synced_at, token_expires_at
             FROM platform_connections WHERE user_id = ? AND platform IN ('meta_ads','google_ads')",
            [$access['owner_id']]
        );

        $byPlatform = ['meta_ads' => null, 'google_ads' => null];
        foreach ($rows as $r) {
            // لو فيه أكتر من صف لنفس المنصة (نادر) ناخد الأحدث حسب last_synced_at
            if ($byPlatform[$r['platform']] === null || ($r['last_synced_at'] ?? '') > ($byPlatform[$r['platform']]['last_synced_at'] ?? '')) {
                $byPlatform[$r['platform']] = $r;
            }
        }

        $metaConfigured = (new MetaOAuthClient())->isConfigured();
        $googleConfigured = (new GoogleOAuthClient(GoogleOAuthClient::SCOPE_ADS, env('GOOGLE_ADS_OAUTH_REDIRECT_URI') ?: null))->isConfigured()
            && (env('GOOGLE_ADS_DEVELOPER_TOKEN') ?: '') !== '';

        return $this->success([
            'meta_ads' => ['configured' => $metaConfigured, 'connection' => $byPlatform['meta_ads']],
            'google_ads' => ['configured' => $googleConfigured, 'connection' => $byPlatform['google_ads']],
        ]);
    }

    /** GET /ads/connections */
    public function showConnectionsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads/connections'));
            exit;
        }

        $body = $this->renderView('ads/connections', ['adsActive' => 'connections']);
        $script = '<script src="' . asset_v('/assets/js/ads/connections.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');

        echo $this->renderPanelPage('ads', 'ربط المنصات', 'حالة ربط Google Ads وMeta Ads والمزامنة', $body, $script);
        exit;
    }
    /** GET /ads/alerts */
    public function showAlertsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads/alerts'));
            exit;
        }

        $body = $this->renderView('ads/alerts', ['adsActive' => 'alerts']);
        $script = '<script src="' . asset_v('/assets/js/ads/alerts.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');

        echo $this->renderPanelPage('ads', 'التنبيهات الاستباقية', 'مراقبة تلقائية لصحة الحملات الإعلانية', $body, $script);
        exit;
    }
    /** GET /ads/autopilot */
    public function showAutopilotPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads/autopilot'));
            exit;
        }

        $body = $this->renderView('ads/autopilot', ['adsActive' => 'autopilot']);
        $script = '<script src="' . asset_v('/assets/js/ads/autopilot.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');

        echo $this->renderPanelPage('ads', 'AI Ads Autopilot', 'وضع التشغيل، حدود الأمان، القرارات المعلّقة، وسجل التحسين', $body, $script);
        exit;
    }
    /** GET /ads/copilot */
    public function showCopilotPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads/copilot'));
            exit;
        }

        $body = $this->renderView('ads/copilot', ['adsActive' => 'copilot']);
        $script = '<script src="' . asset_v('/assets/js/ads/copilot.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');

        echo $this->renderPanelPage('ads', 'AI Copilot', 'اسأل عن أداء حسابك أو اطلب تعديل مباشر', $body, $script);
        exit;
    }
    /** GET /ads/market-research */
    public function showMarketResearchPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads/market-research'));
            exit;
        }

        $body = $this->renderView('ads/market_research', ['adsActive' => 'market_research']);
        $script = '<script src="' . asset_v('/assets/js/ads/market_research.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');

        echo $this->renderPanelPage('ads', 'بحث الأسواق', 'ترشيح وترتيب الدول المناسبة لحملتك القادمة', $body, $script);
        exit;
    }
    /**
     * بيحدد "مين صاحب حساب الإعلانات اللي المستخدم الحالي بيشتغل عليه
     * دلوقتي" ويتحقق إن دوره كافي. المشروع مالوش مفهوم "Workspace Switcher"
     * جاهز، فبنستخدم `owner_id` اختياري في الطلب: لو موجود وعنده صلاحية
     * عليه (كعضو فريق)، بيشتغل عليه؛ لو مش موجود، بيشتغل على حسابه هو
     * (السلوك الافتراضي القديم زي ما هو تمامًا - Backward-compatible 100%
     * لأي عميل مفعّلش الفريق أصلًا).
     *
     * @return array{owner_id:int, role:string}|null null يعني ممنوع (403)
     */
    /**
     * زي resolveAdsAccess() لكن بيحدد صاحب الحساب من الحملة نفسها مباشرة
     * (مش من `owner_id` في الطلب) - يُستخدم في أي endpoint شغّال على
     * campaign_id محدّد أصلًا (زي /campaigns/{id}/status) حيث معرفة
     * المالك مش محتاجة يحددها الطالب، هي معروفة من الحملة نفسها.
     * @return array{owner_id:int, role:string}|null
     */
    /**
     * زي resolveCampaignAccess() لكن لأي مورد تاني معروف صاحبه مباشرة (زي
     * Competitor) - نفس منطق الفحص بالظبط، مجرّد من الحاجة لكائن AdCampaign.
     * @return array{owner_id:int, role:string}|null
     */
    private function resolveAdsAccessForOwner(int $resourceOwnerUserId, string $minRole = 'viewer'): ?array
    {
        $currentUserId = (int) $this->user['id'];
        if ($resourceOwnerUserId === $currentUserId) {
            return ['owner_id' => $resourceOwnerUserId, 'role' => 'owner'];
        }

        $perm = new AdPermissionService();
        $access = $perm->resolveAccess($currentUserId, $resourceOwnerUserId);
        if (!$access['allowed'] || !$perm->hasMinRole($access['role'], $minRole)) {
            return null;
        }

        return ['owner_id' => $resourceOwnerUserId, 'role' => $access['role']];
    }

    private function resolveCampaignAccess(AdCampaign $campaign, string $minRole = 'viewer'): ?array
    {
        // Soft Delete: الحملة المحذوفة غير قابلة للوصول نهائيًا حتى بالرابط المباشر
        if ($campaign->getAttribute('deleted_at')) {
            return null;
        }

        $ownerId = (int) $campaign->getAttribute('user_id');
        $currentUserId = (int) $this->user['id'];

        if ($ownerId === $currentUserId) {
            return ['owner_id' => $ownerId, 'role' => 'owner'];
        }

        $perm = new AdPermissionService();
        $access = $perm->resolveAccess($currentUserId, $ownerId);
        if (!$access['allowed'] || !$perm->hasMinRole($access['role'], $minRole)) {
            return null;
        }

        return ['owner_id' => $ownerId, 'role' => $access['role']];
    }

    private function resolveAdsAccess(string $minRole = 'viewer'): ?array
    {
        $currentUserId = (int) $this->user['id'];
        $requestedOwnerId = $this->get('owner_id') ? (int) $this->get('owner_id') : $currentUserId;

        if ($requestedOwnerId === $currentUserId) {
            return ['owner_id' => $currentUserId, 'role' => 'owner'];
        }

        $perm = new AdPermissionService();
        $access = $perm->resolveAccess($currentUserId, $requestedOwnerId);
        if (!$access['allowed'] || !$perm->hasMinRole($access['role'], $minRole)) {
            return null;
        }

        return ['owner_id' => $requestedOwnerId, 'role' => $access['role']];
    }

    private function firstWebsiteForUser(int $userId): ?array
    {
        $rows = $this->db->query("SELECT id FROM websites WHERE user_id = ? ORDER BY created_at ASC LIMIT 1", [$userId]);
        return $rows[0] ?? null;
    }

    private function renderAdsOAuthError(string $message): void
    {
        $body = $this->renderView('ads/oauth_error', ['message' => $message]);
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'تعذر الربط', 'Meta Ads', $body, '');
    }
}
