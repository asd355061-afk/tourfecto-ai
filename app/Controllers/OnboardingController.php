<?php

/**
 * Tourfecto - Onboarding Controller
 * Phase 16 + Phase 18 + Phase 19.
 *
 * Phase 16: الـWizard بيلخد كل بيانات الخطوات السبعة في نداء واحد وبيربطهم
 * فعليًا بكل الـAgents الموجودة (مش بس بيحفظ بيانات فاضية):
 *
 *   بيانات الأعمال → إنشاء/تحديث Website → إضافة المنافسين فعليًا لنظام
 *   Competitor Intelligence (Phase 7) → تشغيل تدقيق SEO حقيقي تلقائيًا
 *   (Website Optimizer - Phase 5) → محاولة توليد خطة نمو 90 يوم
 *   (SEO Strategy Agent - Phase 14).
 *
 * Phase 18: واجهة الـWizard الكاملة (7 خطوات + شاشة "بنجهّز حسابك" + شاشة
 * النتائج) بنفس الـTheme البصري لصفحات auth.
 *
 * Phase 19: تحويل الشغل الثقيلي (Audit + خطة النمو) إلى طابور خلفية
 * (OnboardingAuditJob) عشان الـWizard يرجّع فورًا والواجهة بتعمل polling
 * على /api/onboarding/status - مع fallback تلقائي للتنفيذ المتزامن لو
 * الطابور مش متاح. كمان: منع تكرار المواقع (نفس الـURL بيحدّث الموقع
 * القديم بدل ما يعمل نسخة تانية)، تطبيع الروابط، وتوسيع /api/onboarding/status
 * بحالة الـAudit وخطة النمو والمنافسين.
 *
 * @version 1.0.0
 */
class OnboardingController extends Controller
{
    /**
     * POST /api/onboarding/complete
     * { business_name, main_url, industry, target_country, target_language?,
     *   target_customers, main_services, competitors: [{name, domain}, ...] (حتى 3) }
     *
     * بيرجّع:
     *   - processing = true: الشغل بيشتغل في الخلفية (طابور) - الواجهة
     *     بتعمل polling على /api/onboarding/status.
     *   - processing = false: كل حاجة اتخلصت في نفس الـ request (fallback
     *     لما الطابور مش متاح) - الواجهة بتعرض النتيجة مباشرة.
     */
    public function complete(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = (int) $this->user['id'];

        if (!$this->validate(['main_url' => 'required', 'business_name' => 'required'])) {
            return $this->error($this->tr('onboarding.api.invalid_data'), 422, $this->getErrors());
        }

        $mainUrl = $this->canonicalizeUrl((string) $this->get('main_url'));
        if ($mainUrl === null) {
            return $this->error($this->tr('onboarding.api.invalid_url'), 422);
        }

        $competitorsInput = $this->get('competitors', []);
        if (!is_array($competitorsInput)) {
            $competitorsInput = [];
        }
        $competitorsInput = array_slice($competitorsInput, 0, 3);

        $competitors = [];
        foreach ($competitorsInput as $c) {
            if (!is_array($c) || empty($c['domain'])) {
                continue;
            }
            $domain = $this->canonicalizeUrl((string) $c['domain']);
            $competitors[] = [
                'name' => trim((string) ($c['name'] ?? '')),
                'domain' => $domain ?? trim((string) $c['domain']),
            ];
        }

        try {
            $websiteId = $this->findOrCreateWebsite($userId, $mainUrl, $competitors);
            if (!$websiteId) {
                return $this->error($this->tr('onboarding.api.create_failed'), 500);
            }

            // ============ Async أولًا (طابور الخلفية) ============
            $queue = new QueueManager();
            if ($queue->isReady()) {
                $jobId = $queue->push(OnboardingAuditJob::class, [
                    'user_id' => $userId,
                    'website_id' => $websiteId,
                    'competitors' => $competitors,
                ]);

                if ($jobId) {
                    $this->log('Onboarding Started (background)', ['website_id' => $websiteId, 'job_id' => $jobId]);

                    return $this->success([
                        'website_id' => $websiteId,
                        'processing' => true,
                        'website' => (new Website())->find($websiteId)->toArray(),
                    ], $this->tr('onboarding.api.started'), 202);
                }
            }

            // ============ Fallback: تنفيذ متزامن ============
            $result = $this->runSetup([
                'user_id' => $userId,
                'website_id' => $websiteId,
                'competitors' => $competitors,
            ]);

            $this->log('Onboarding Completed', ['website_id' => $websiteId, 'competitors_added' => count($result['competitors_added'] ?? [])]);

            return $this->success(array_merge([
                'website_id' => $websiteId,
                'processing' => false,
                'website' => (new Website())->find($websiteId)->toArray(),
            ], $result), $this->tr('onboarding.result.title'));

        } catch (Exception $e) {
            Logger::error('Onboarding Complete Error', ['message' => $e->getMessage()]);
            $debugMsg = (defined('APP_DEBUG') && APP_DEBUG) ? 'تعذر إكمال الإعداد: ' . $e->getMessage() : 'تعذر إكمال الإعداد';
            return $this->error($debugMsg, 500);
        }
    }

    /**
     * Pipeline مشترك بين المسار المتزامن (fallback) وطابور الخلفية
     * (OnboardingAuditJob): المنافسين → Audit → خطة النمو.
     * بيستقبل بيانات الموقع والمنافسين ويعيد نتيجة موحّدة للواجهة.
     *
     * @param array $payload ['user_id','website_id','competitors']
     * @return array
     */
    public function runSetup(array $payload = []): array
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $websiteId = (int) ($payload['website_id'] ?? 0);
        $competitors = (array) ($payload['competitors'] ?? []);

        // ============ خطوة 7: المنافسين - تسجيل حقيقي (بدون تكرار) ============
        $addedCompetitors = [];
        if (class_exists('CompetitorAnalysisService')) {
            $competitorService = new CompetitorAnalysisService();
            foreach ($competitors as $c) {
                if (empty($c['domain'])) {
                    continue;
                }
                try {
                    $exists = $this->db->query(
                        "SELECT id FROM competitors WHERE user_id = ? AND website_id = ? AND competitor_domain = ? LIMIT 1",
                        [$userId, $websiteId, (string) $c['domain']]
                    );
                    if (!empty($exists)) {
                        $addedCompetitors[] = ['id' => (int) $exists[0]['id'], 'competitor_domain' => (string) $c['domain'], 'duplicate' => true];
                        continue;
                    }
                    $comp = $competitorService->addCompetitor($userId, $websiteId, (string) ($c['name'] ?: $c['domain']), (string) $c['domain']);
                    $addedCompetitors[] = $comp->toArray();
                } catch (Exception $e) {
                    // منافس واحد فشل مايوقفش باقي الـWizard
                }
            }
        }

        // ============ "ابدأ AI Audit" - تدقيق SEO حقيقي تلقائيًا (Phase 5) ============
        $auditResult = null;
        if (class_exists('WebsiteOptimizerController')) {
            $originalGet = $_GET;
            $_GET = ['website_id' => $websiteId];
            try {
                $optimizer = new WebsiteOptimizerController();
                $auditResult = $optimizer->runAudit([]);
            } catch (Exception $e) {
                Logger::error('Onboarding Auto-Audit Error', ['message' => $e->getMessage()]);
            } finally {
                $_GET = $originalGet;
            }
        }

        // ============ "خطتك جاهزة" - محاولة توليد خطة 90 يوم (Phase 14، اختياري) ============
        $strategyResult = null;
        if (($auditResult['success'] ?? false) && class_exists('SeoStrategyController')) {
            $originalGet2 = $_GET;
            $_GET = ['website_id' => $websiteId];
            try {
                $strategyController = new SeoStrategyController();
                $strategyGenResult = $strategyController->generate([]);
                if ($strategyGenResult['success'] ?? false) {
                    $strategyResult = $strategyGenResult['data'];
                }
            } catch (Exception $e) {
                // خطة النمو "بونص" - فشلها (مثلاً رصيد AI منتهي) ميعطلش اكتمال الـOnboarding نفسه
                Logger::error('Onboarding Auto-Strategy Error', ['message' => $e->getMessage()]);
            } finally {
                $_GET = $originalGet2;
            }
        }

        $auditData = $auditResult['data'] ?? null;

        return [
            'competitors_added' => $addedCompetitors,
            'audit' => $auditResult['success'] ?? false ? $auditData : null,
            'audit_error' => $auditResult['success'] ?? false ? null : ($auditResult['error'] ?? null),
            'growth_plan' => $strategyResult,
            'ready' => true,
        ];
    }

    /**
     * تطبيع الرابط: بيضيف https:// لو مفيش scheme، بيخلي الـhost lowercase،
     * وبيشيل أي trailing slash. بيرجع null لو الرابط مش صالح خالص.
     */
    private function canonicalizeUrl(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (!preg_match('#^https?://#i', $raw)) {
            $raw = 'https://' . $raw;
        }

        $parts = parse_url($raw);
        if (!$parts || empty($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $scheme = strtolower($parts['scheme']) === 'http' && $port === ':80' ? 'http' : strtolower((string) $parts['scheme']);

        if (!filter_var($scheme . '://' . $host . $port . ($path ?: '/'), FILTER_VALIDATE_URL)) {
            return null;
        }

        return $scheme . '://' . $host . $port . ($path ?: '/');
    }

    /**
     * إيجاد موقع بنفس الرابط لنفس المستخدم أو إنشاؤه. بيحدّث الموقع الموجود
     * بدل ما يعمل نسخة مكررة كل مرة العميل يعيد تشغيل الـWizard.
     */
    private function findOrCreateWebsite(int $userId, string $mainUrl, array $competitors): int
    {
        $urlCol = Website::urlColumn();
        $rows = $this->db->query(
            "SELECT id FROM websites WHERE user_id = ? AND {$urlCol} = ? ORDER BY id DESC LIMIT 1",
            [$userId, $mainUrl]
        );

        $attrs = [
            'user_id' => $userId,
            'main_url' => $mainUrl,
            'company_name' => $this->get('business_name'),
            'industry' => $this->get('industry', 'tourism'),
            'target_language' => $this->get('target_language', 'ar'),
            'target_country' => $this->get('target_country'),
            'target_customers' => $this->get('target_customers'),
            'main_services' => $this->get('main_services'),
            'onboarding_completed_at' => date('Y-m-d H:i:s'),
        ];

        if (!empty($rows)) {
            $website = new Website(array_merge($attrs, ['id' => (int) $rows[0]['id']]));
            $website->save();
            return (int) $rows[0]['id'];
        }

        $attrs['is_verified'] = 0;
        $website = new Website($attrs);
        $websiteId = $website->save();
        return (int) $websiteId;
    }

    /** GET /onboarding - صفحة الـWizard */
    public function showWizard(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderWizardPage();
        exit;
    }

    /**
     * صفحة الـWizard الكاملة - نفس الـTheme البصري لصفحات auth (خلفية
     * غامقة + لون ذهبي + خطوط IBM Plex Sans Arabic/Fraunces).
     *
     * تحسينات Phase 19:
     *  - صفوف منافسين ديناميكية (إضافة/إزالة حتى 3)
     *  - حفظ مسودة تلقائي في localStorage (مش بتضيع بيانات العميل لو
     *    ساب الصفحة ورجع)
     *  - شاشة "بنجهّز حسابك" بمراحل متحركة + polling على /api/onboarding/status
     *    لما الشغل في الخلفية (processing=true)
     *  - شاشة نتائج غنية: درجة SEO + كسر الفئات + عدد الملاحظات + خطة النمو
     *  - تخطّي (skip) لسهولة الخروج للداشبورد، وتنقّل بالـ Enter
     *  - تعبئة مسبقة من آخر موقع (تحديث البيانات بدل البدء من الصفر)
     */
    private function renderWizardPage(): string
    {
        $appName = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
        $topNavBrandHtml = site_brand_html();
        $faviconHtml = site_favicon_html();
        $lang = current_lang();
        $dir = current_dir();
        $panelJsUrl = asset_v('/assets/js/panel.js');

        $t = fn ($key) => $this->tr($key);

        // تعبئة مسبقة من آخر موقع للعميل (لو راجع يحدّث بياناته)
        $prefill = null;
        try {
            $websites = (new Website())->where(['user_id' => (int) ($this->user['id'] ?? 0)], ['id' => 'DESC'], 1);
            if (!empty($websites)) {
                $w = $websites[0];
                $prefill = [
                    'business_name' => (string) $w->getAttribute('company_name'),
                    'main_url' => (string) $w->getAttribute('main_url'),
                    'industry' => (string) ($w->getAttribute('industry') ?: 'tourism'),
                    'target_country' => (string) $w->getAttribute('target_country'),
                    'target_customers' => (string) $w->getAttribute('target_customers'),
                    'main_services' => (string) $w->getAttribute('main_services'),
                ];
            }
        } catch (Exception $e) {
            // مش حاسم - لو مفيش مواقع لسه فالتعبئة هتبقى فاضية
        }
        $prefillJson = json_encode($prefill, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$t('onboarding.page_title')} | {$appName}</title>
    {$faviconHtml}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="{$panelJsUrl}"></script>
    <script>window.I18N = {$this->i18nJson()};</script>
    <style>
        :root { --ob-bg:#060A13; --ob-card:#0F1A2C; --ob-line:rgba(255,255,255,.09); --ob-text:#F2F4F8; --ob-muted:#8996AC; --ob-gold:#EFB05E; --ob-green:#3FA796; --ob-red:#E5736D; }
        body { background: var(--ob-bg); color: var(--ob-text); font-family: 'IBM Plex Sans Arabic', 'Tajawal', sans-serif; margin:0; }
        .ob-nav { padding:18px 24px; border-bottom:1px solid var(--ob-line); display:flex; align-items:center; justify-content:space-between; gap:16px; }
        .ob-nav-brand { font-family:'Fraunces',serif; font-weight:700; font-size:18px; }
        .ob-nav-skip { color: var(--ob-muted); font-size:13px; text-decoration:none; border:1px solid var(--ob-line); border-radius:20px; padding:6px 16px; transition: color .2s, border-color .2s; }
        .ob-nav-skip:hover { color: var(--ob-text); border-color: var(--ob-muted); }
        .ob-wrap { min-height: calc(100vh - 64px); display:flex; align-items:center; justify-content:center; padding: 32px 16px; }
        .ob-card { width:100%; max-width:640px; background: var(--ob-card); border:1px solid var(--ob-line); border-radius:16px; padding:36px; box-shadow: 0 20px 50px -20px rgba(0,0,0,.5); }
        .ob-progress-wrap { margin-bottom:14px; }
        .ob-progress-bar { height:4px; border-radius:2px; background: rgba(255,255,255,.1); overflow:hidden; }
        .ob-progress-fill { height:100%; width:14%; background: linear-gradient(90deg, var(--ob-green), var(--ob-gold)); border-radius:2px; transition: width .35s ease; }
        .ob-steps { display:flex; gap:6px; margin-bottom:28px; }
        .ob-step-dot { flex:1; height:4px; border-radius:2px; background: rgba(255,255,255,.12); transition: background .2s; }
        .ob-step-dot.done { background: var(--ob-green); }
        .ob-step-dot.active { background: var(--ob-gold); }
        .ob-h { font-family:'Fraunces',serif; font-size:22px; font-weight:700; margin:0 0 6px; }
        .ob-sub { color: var(--ob-muted); font-size:13.5px; margin:0 0 22px; }
        .ob-field { margin-bottom:16px; }
        .ob-field label { display:block; font-size:13px; color: var(--ob-muted); margin-bottom:6px; }
        .ob-field input, .ob-field textarea, .ob-field select {
            width:100%; box-sizing:border-box; background:#0A1220; border:1px solid var(--ob-line); border-radius:10px;
            padding:11px 14px; color: var(--ob-text); font-family:inherit; font-size:14px;
        }
        .ob-field input:focus, .ob-field textarea:focus { outline:none; border-color: var(--ob-gold); }
        .ob-competitor-row { display:flex; gap:8px; margin-bottom:8px; align-items:center; }
        .ob-competitor-row input { flex:1; }
        .ob-comp-remove { background:none; border:1px solid var(--ob-line); color: var(--ob-muted); border-radius:8px; width:34px; height:34px; font-size:16px; cursor:pointer; flex-shrink:0; }
        .ob-comp-remove:hover { color: var(--ob-red); border-color: var(--ob-red); }
        .ob-comp-add { background:none; border:1px dashed var(--ob-muted); color: var(--ob-muted); border-radius:10px; padding:9px 14px; font-size:13px; cursor:pointer; font-family:inherit; width:100%; }
        .ob-comp-add:hover { color: var(--ob-gold); border-color: var(--ob-gold); }
        .ob-comp-add:disabled { opacity:.4; cursor:not-allowed; }
        .ob-nav-btns { display:flex; justify-content:space-between; margin-top:24px; gap:10px; }
        .ob-btn { border:none; border-radius:10px; padding:11px 22px; font-size:14px; font-weight:600; cursor:pointer; font-family:inherit; }
        .ob-btn.primary { background: var(--ob-gold); color:#1A1200; }
        .ob-btn.primary:hover { filter:brightness(1.08); }
        .ob-btn.primary:disabled { opacity:.5; cursor:not-allowed; }
        .ob-btn.ghost { background:transparent; color: var(--ob-muted); border:1px solid var(--ob-line); }
        .ob-btn.ghost:hover { color: var(--ob-text); }
        .ob-btn.block { width:100%; }
        .ob-step { display:none; }
        .ob-step.active { display:block; }
        .ob-error { color:#ff8080; font-size:13px; margin-top:10px; display:none; }
        .ob-running-stage { display:flex; align-items:center; gap:12px; padding:12px 0; border-bottom:1px solid var(--ob-line); color: var(--ob-muted); font-size:14px; opacity:.4; transition: opacity .3s, color .3s; }
        .ob-running-stage:last-child { border-bottom:none; }
        .ob-running-stage.active { opacity:1; color: var(--ob-text); }
        .ob-running-stage .ob-dot { width:8px; height:8px; border-radius:50%; background: var(--ob-muted); flex-shrink:0; }
        .ob-running-stage.active .ob-dot { background: var(--ob-gold); box-shadow:0 0 0 4px rgba(239,176,94,.15); }
        .ob-running-stage.done .ob-dot { background: var(--ob-green); }
        .ob-spinner { width:16px; height:16px; border:2px solid rgba(0,0,0,.2); border-top-color:#1A1200; border-radius:50%; display:inline-block; animation: ob-spin .7s linear infinite; vertical-align:middle; margin-inline-end:6px; }
        .ob-spinner.light { border-color: rgba(255,255,255,.25); border-top-color: var(--ob-gold); }
        @keyframes ob-spin { to { transform: rotate(360deg); } }
        .ob-result-row { display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid var(--ob-line); font-size:13.5px; }
        .ob-result-row:last-child { border-bottom:none; }
        .ob-score-badge { font-size:28px; font-weight:700; color: var(--ob-gold); font-family:'Fraunces',serif; }
        .ob-score-badge.muted { color: var(--ob-muted); font-size:20px; }
        .ob-cat-bar-wrap { display:flex; align-items:center; gap:12px; padding:7px 0; }
        .ob-cat-label { width:120px; flex-shrink:0; font-size:12.5px; color: var(--ob-muted); }
        .ob-cat-track { flex:1; height:8px; border-radius:4px; background: rgba(255,255,255,.08); overflow:hidden; }
        .ob-cat-fill { height:100%; border-radius:4px; background: linear-gradient(90deg, var(--ob-gold), var(--ob-green)); }
        .ob-cat-fill.low { background: var(--ob-red); }
        .ob-cat-fill.mid { background: var(--ob-gold); }
        .ob-cat-value { width:38px; flex-shrink:0; text-align:end; font-size:12.5px; font-weight:600; color: var(--ob-text); }
        .ob-result-actions { display:flex; flex-direction:column; gap:10px; margin-top:20px; }
        .ob-note { font-size:12.5px; color: var(--ob-muted); margin-top:12px; line-height:1.7; }
        .ob-badge { display:inline-block; font-size:11.5px; font-weight:600; border-radius:20px; padding:4px 12px; }
        .ob-badge.ok { background: rgba(63,167,150,.15); color: var(--ob-green); }
        .ob-badge.warn { background: rgba(239,176,94,.15); color: var(--ob-gold); }
        .ob-badge.err { background: rgba(229,115,109,.15); color: var(--ob-red); }
        .ob-trust { font-size:12px; color: var(--ob-muted); margin-top:16px; line-height:1.7; text-align:center; }
        .ob-trust strong { color: var(--ob-text); font-weight:600; }
        .ob-bench { font-size:12.5px; }
        .ob-bench-ico { width:16px; text-align:center; flex-shrink:0; }
        .ob-bench-up { color: var(--ob-green); }
        .ob-bench-down { color: var(--ob-gold); }
        @media (max-width:640px) {
            .ob-card { padding:24px 18px; border-radius:12px; }
            .ob-h { font-size:19px; }
            .ob-nav { padding:14px 16px; }
            .ob-field input, .ob-field textarea, .ob-field select { padding:11px 12px; font-size:16px; }
            .ob-btn { padding:12px 18px; }
        }
    </style>
</head>
<body>
    <div class="ob-nav">
        <div class="ob-nav-brand">{$topNavBrandHtml}</div>
        <a href="/dashboard" class="ob-nav-skip">{$t('onboarding.nav.skip')}</a>
    </div>
    <div class="ob-wrap">
        <div class="ob-card">
            <div class="ob-progress-wrap">
                <div class="ob-progress-bar"><div class="ob-progress-fill" id="obProgressFill"></div></div>
            </div>
            <div class="ob-steps" id="obSteps"></div>

            <!-- Step 1: Business Name -->
            <div class="ob-step active" data-step="1">
                <h2 class="ob-h">{$t('onboarding.step1.title')}</h2>
                <p class="ob-sub">{$t('onboarding.step1.sub')}</p>
                <div class="ob-field">
                    <label>{$t('onboarding.field.business_name')}</label>
                    <input type="text" id="obBusinessName" placeholder="{$t('onboarding.field.business_name_ph')}" autocomplete="organization">
                </div>
            </div>

            <!-- Step 2: Website -->
            <div class="ob-step" data-step="2">
                <h2 class="ob-h">{$t('onboarding.step2.title')}</h2>
                <p class="ob-sub">{$t('onboarding.step2.sub')}</p>
                <div class="ob-field">
                    <label>{$t('onboarding.field.website_url')}</label>
                    <input type="text" id="obMainUrl" inputmode="url" placeholder="https://example.com">
                </div>
            </div>

            <!-- Step 3: Business Type -->
            <div class="ob-step" data-step="3">
                <h2 class="ob-h">{$t('onboarding.step3.title')}</h2>
                <p class="ob-sub">{$t('onboarding.step3.sub')}</p>
                <div class="ob-field">
                    <label>{$t('onboarding.field.industry')}</label>
                    <select id="obIndustry">
                        <option value="tours">{$t('onboarding.industry.tours')}</option>
                        <option value="hotel">{$t('onboarding.industry.hotel')}</option>
                        <option value="travel_agency">{$t('onboarding.industry.travel_agency')}</option>
                        <option value="other">{$t('onboarding.industry.other')}</option>
                    </select>
                </div>
            </div>

            <!-- Step 4: Country / Market -->
            <div class="ob-step" data-step="4">
                <h2 class="ob-h">{$t('onboarding.step4.title')}</h2>
                <p class="ob-sub">{$t('onboarding.step4.sub')}</p>
                <div class="ob-field">
                    <label>{$t('onboarding.field.target_country')}</label>
                    <input type="text" id="obTargetCountry" placeholder="{$t('onboarding.field.target_country_ph')}">
                </div>
            </div>

            <!-- Step 5: Target Customers -->
            <div class="ob-step" data-step="5">
                <h2 class="ob-h">{$t('onboarding.step5.title')}</h2>
                <p class="ob-sub">{$t('onboarding.step5.sub')}</p>
                <div class="ob-field">
                    <label>{$t('onboarding.field.target_customers')}</label>
                    <textarea id="obTargetCustomers" rows="3" placeholder="{$t('onboarding.field.target_customers_ph')}"></textarea>
                </div>
            </div>

            <!-- Step 6: Main Services -->
            <div class="ob-step" data-step="6">
                <h2 class="ob-h">{$t('onboarding.step6.title')}</h2>
                <p class="ob-sub">{$t('onboarding.step6.sub')}</p>
                <div class="ob-field">
                    <label>{$t('onboarding.field.main_services')}</label>
                    <textarea id="obMainServices" rows="3" placeholder="{$t('onboarding.field.main_services_ph')}"></textarea>
                </div>
            </div>

            <!-- Step 7: Competitors -->
            <div class="ob-step" data-step="7">
                <h2 class="ob-h">{$t('onboarding.step7.title')}</h2>
                <p class="ob-sub">{$t('onboarding.step7.sub')}</p>
                <div id="obCompetitorRows"></div>
                <button type="button" class="ob-comp-add" id="obAddCompetitor">+ {$t('onboarding.nav.add_competitor')}</button>
                <p class="ob-note">{$t('onboarding.step7.optional_note')}</p>
            </div>

            <!-- Step 8: Running -->
            <div class="ob-step" data-step="8">
                <h2 class="ob-h">{$t('onboarding.running.title')}</h2>
                <div id="obRunningStages">
                    <div class="ob-running-stage" data-stage="1"><span class="ob-dot"></span><span>{$t('onboarding.running.stage.fetch')}</span></div>
                    <div class="ob-running-stage" data-stage="2"><span class="ob-dot"></span><span>{$t('onboarding.running.stage.competitors')}</span></div>
                    <div class="ob-running-stage" data-stage="3"><span class="ob-dot"></span><span>{$t('onboarding.running.stage.audit')}</span></div>
                    <div class="ob-running-stage" data-stage="4"><span class="ob-dot"></span><span>{$t('onboarding.running.stage.strategy')}</span></div>
                    <div class="ob-running-stage" data-stage="5"><span class="ob-dot"></span><span>{$t('onboarding.running.stage.finalize')}</span></div>
                </div>
                <p class="ob-sub" id="obRunningStatus" style="margin-top:18px;">{$t('onboarding.running.sub')}</p>
            </div>

            <!-- Step 9: Result -->
            <div class="ob-step" data-step="9">
                <h2 class="ob-h">{$t('onboarding.result.title')}</h2>
                <div id="obResultBody"></div>
            </div>

            <div id="obError" class="ob-error"></div>

            <div class="ob-nav-btns" id="obNavBtns">
                <button class="ob-btn ghost" id="obBackBtn" onclick="obBack()">{$t('onboarding.nav.back')}</button>
                <button class="ob-btn primary" id="obNextBtn" onclick="obNext()">{$t('onboarding.nav.next')}</button>
            </div>
            <p class="ob-trust" id="obTrustNote">{$t('onboarding.trust_note')}</p>
        </div>
    </div>

    <script>
    const esc = P.esc, fetchJSON = P.fetchJSON;
    const OB_TOTAL_STEPS = 7;
    const OB_MAX_COMPETITORS = 3;
    const OB_DRAFT_KEY = 'tf_onboarding_draft';
    const OB_PREFILL = {$prefillJson};
    let obCurrentStep = 1;
    let obProcessing = false;

    const obL = function (k) { return (window.I18N && window.I18N[k]) || k; };

    function obRenderDots() {
        const wrap = document.getElementById('obSteps');
        let html = '';
        for (let i = 1; i <= OB_TOTAL_STEPS; i++) {
            const cls = i < obCurrentStep ? 'done' : (i === obCurrentStep ? 'active' : '');
            html += `<div class="ob-step-dot \${cls}"></div>`;
        }
        wrap.innerHTML = html;
        const pct = Math.round((obCurrentStep / OB_TOTAL_STEPS) * 100);
        document.getElementById('obProgressFill').style.width = pct + '%';
    }

    function obShowStep(n) {
        document.querySelectorAll('.ob-step').forEach(el => el.classList.remove('active'));
        const el = document.querySelector(`.ob-step[data-step="\${n}"]`);
        if (el) el.classList.add('active');
        document.getElementById('obError').style.display = 'none';

        document.getElementById('obTrustNote').style.display = (n === OB_TOTAL_STEPS) ? 'block' : 'none';

        const first = el ? el.querySelector('input, select, textarea') : null;
        if (first) setTimeout(function () { first.focus(); }, 60);

        const navBtns = document.getElementById('obNavBtns');
        if (n <= OB_TOTAL_STEPS) {
            navBtns.style.display = 'flex';
            document.getElementById('obBackBtn').style.visibility = n === 1 ? 'hidden' : 'visible';
            document.getElementById('obNextBtn').textContent = n === OB_TOTAL_STEPS ? obL('onboarding.nav.finish') : obL('onboarding.nav.next');
            obRenderDots();
        } else {
            navBtns.style.display = 'none';
        }
    }

    function obShowError(msg) {
        const box = document.getElementById('obError');
        box.textContent = msg;
        box.style.display = 'block';
    }

    function obValidateStep(n) {
        if (n === 1 && !document.getElementById('obBusinessName').value.trim()) {
            obShowError(obL('onboarding.error.business_name')); return false;
        }
        if (n === 2) {
            const url = document.getElementById('obMainUrl').value.trim();
            if (!url || !/^https?:\\/\\//i.test(url)) { obShowError(obL('onboarding.error.website_url')); return false; }
        }
        return true;
    }

    function obSaveDraft() {
        if (obProcessing) return;
        try {
            const data = {
                business_name: document.getElementById('obBusinessName').value,
                main_url: document.getElementById('obMainUrl').value,
                industry: document.getElementById('obIndustry').value,
                target_country: document.getElementById('obTargetCountry').value,
                target_customers: document.getElementById('obTargetCustomers').value,
                main_services: document.getElementById('obMainServices').value,
                competitors: Array.from(document.querySelectorAll('#obCompetitorRows .ob-competitor-row')).map(row => ({
                    name: row.querySelector('.ob-comp-name').value,
                    domain: row.querySelector('.ob-comp-domain').value,
                }))
            };
            localStorage.setItem(OB_DRAFT_KEY, JSON.stringify(data));
        } catch (e) { /* localStorage غير متاح */ }
    }

    function obRestoreDraft() {
        let draft = null;
        try { draft = JSON.parse(localStorage.getItem(OB_DRAFT_KEY) || 'null'); } catch (e) { draft = null; }
        if (!draft) draft = OB_PREFILL;
        if (!draft) return;
        const set = (id, v) => { const el = document.getElementById(id); if (el && v) el.value = v; };
        set('obBusinessName', draft.business_name);
        set('obMainUrl', draft.main_url);
        if (draft.industry) document.getElementById('obIndustry').value = draft.industry;
        set('obTargetCountry', draft.target_country);
        set('obTargetCustomers', draft.target_customers);
        set('obMainServices', draft.main_services);
        if (Array.isArray(draft.competitors)) {
            obRenderCompetitors(draft.competitors);
        }
    }

    function obAddCompetitor() {
        const rows = document.querySelectorAll('#obCompetitorRows .ob-competitor-row');
        if (rows.length >= OB_MAX_COMPETITORS) return;
        const row = document.createElement('div');
        row.className = 'ob-competitor-row';
        row.innerHTML = `<input type="text" class="ob-comp-name" placeholder="\${obL('onboarding.field.competitor_name_ph')}"><input type="text" class="ob-comp-domain" placeholder="\${obL('onboarding.field.competitor_domain_ph')}"><button type="button" class="ob-comp-remove" onclick="obRemoveCompetitor(this)" aria-label="\${obL('onboarding.nav.remove_competitor')}">×</button>`;
        document.getElementById('obCompetitorRows').appendChild(row);
        obRenderCompetitors();
    }

    function obRemoveCompetitor(btn) {
        const row = btn.closest('.ob-competitor-row');
        if (row) row.remove();
        obRenderCompetitors();
    }

    function obRenderCompetitors(prefilled) {
        const wrap = document.getElementById('obCompetitorRows');
        if (!prefilled) {
            prefilled = Array.from(wrap.querySelectorAll('.ob-competitor-row')).map(row => ({
                name: row.querySelector('.ob-comp-name').value,
                domain: row.querySelector('.ob-comp-domain').value,
            })).filter(c => c.name || c.domain);
        }
        wrap.innerHTML = '';
        prefilled.forEach(c => {
            const row = document.createElement('div');
            row.className = 'ob-competitor-row';
            row.innerHTML = `<input type="text" class="ob-comp-name" value="\${esc(c.name || '')}" placeholder="\${obL('onboarding.field.competitor_name_ph')}"><input type="text" class="ob-comp-domain" value="\${esc(c.domain || '')}" placeholder="\${obL('onboarding.field.competitor_domain_ph')}"><button type="button" class="ob-comp-remove" onclick="obRemoveCompetitor(this)" aria-label="\${obL('onboarding.nav.remove_competitor')}">×</button>`;
            wrap.appendChild(row);
        });
        if (prefilled.length === 0) obAddCompetitor();
        document.getElementById('obAddCompetitor').disabled = prefilled.length >= OB_MAX_COMPETITORS;
    }

    function obBack() {
        if (obCurrentStep > 1) { obCurrentStep--; obShowStep(obCurrentStep); }
    }

    async function obNext() {
        if (!obValidateStep(obCurrentStep)) return;
        obSaveDraft();
        if (obCurrentStep < OB_TOTAL_STEPS) {
            obCurrentStep++;
            obShowStep(obCurrentStep);
        } else {
            await obSubmit();
        }
    }

    function obStartRunning() {
        obCurrentStep = 8;
        obShowStep(8);
        document.querySelectorAll('.ob-running-stage').forEach(el => el.classList.remove('active', 'done'));
        document.getElementById('obRunningStatus').textContent = obL('onboarding.running.sub');
    }

    function obAdvanceStages() {
        const stages = document.querySelectorAll('.ob-running-stage');
        stages.forEach((el, i) => {
            const activeIdx = Math.floor(Date.now() / 4500) % stages.length;
            el.classList.toggle('done', i < activeIdx);
            el.classList.toggle('active', i === activeIdx);
        });
    }

    async function obSubmit() {
        obProcessing = true;
        obStartRunning();
        const stageTimer = setInterval(obAdvanceStages, 1000);
        obAdvanceStages();

        const competitors = Array.from(document.querySelectorAll('#obCompetitorRows .ob-competitor-row')).map(row => ({
            name: row.querySelector('.ob-comp-name').value.trim(),
            domain: row.querySelector('.ob-comp-domain').value.trim(),
        })).filter(c => c.domain);

        const payload = {
            business_name: document.getElementById('obBusinessName').value.trim(),
            main_url: document.getElementById('obMainUrl').value.trim(),
            industry: document.getElementById('obIndustry').value,
            target_country: document.getElementById('obTargetCountry').value.trim(),
            target_customers: document.getElementById('obTargetCustomers').value.trim(),
            main_services: document.getElementById('obMainServices').value.trim(),
            competitors: competitors,
        };

        try {
            const res = await fetchJSON('/api/onboarding/complete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });

            if (!res.success) {
                clearInterval(stageTimer);
                obProcessing = false;
                obCurrentStep = 7;
                obShowStep(7);
                obShowError(res.error || obL('onboarding.error.generic'));
                return;
            }

            try { localStorage.removeItem(OB_DRAFT_KEY); } catch (e) {}

            const data = res.data || {};
            if (data.processing) {
                obPollUntilReady(data.website_id, stageTimer);
            } else {
                clearInterval(stageTimer);
                obRenderResult(data);
                obCurrentStep = 9;
                obShowStep(9);
            }
        } catch (e) {
            clearInterval(stageTimer);
            obProcessing = false;
            obCurrentStep = 7;
            obShowStep(7);
            obShowError(obL('onboarding.error.generic'));
        }
    }

    function obPollUntilReady(websiteId, stageTimer) {
        const statusEl = document.getElementById('obRunningStatus');
        const startedAt = Date.now();
        const maxWait = 90000;
        let lastAuditState = '';

        const poll = async function () {
            const elapsed = Date.now() - startedAt;
            const stageIdx = Math.floor(elapsed / 4500) % 5;
            statusEl.textContent = obL('onboarding.running.stage.detail_' + (stageIdx + 1));

            if (elapsed >= maxWait) {
                clearInterval(stageTimer);
                obProcessing = false;
                obRenderResult({ timeout: true });
                obCurrentStep = 9;
                obShowStep(9);
                return;
            }

            try {
                const res = await fetchJSON('/api/onboarding/status?website_id=' + encodeURIComponent(websiteId));
                const site = (res && res.data && res.data.websites || []).find(w => String(w.id) === String(websiteId));
                if (site && site.audit_status && site.audit_status !== lastAuditState) {
                    lastAuditState = site.audit_status;
                    if (site.audit_status === 'completed' || site.audit_status === 'failed') {
                        clearInterval(stageTimer);
                        obProcessing = false;
                        obRenderResult(site);
                        obCurrentStep = 9;
                        obShowStep(9);
                        return;
                    }
                }
            } catch (e) { /* تجاهل أخطاء الـpoll المؤقتة */ }

            setTimeout(poll, 2500);
        };

        setTimeout(poll, 2500);
    }

    function obCatLabel(cat) {
        const k = 'onboarding.result.category.' + cat;
        return (window.I18N && window.I18N[k]) ? window.I18N[k] : cat;
    }

    function obRenderResult(data) {
        const box = document.getElementById('obResultBody');
        let html = '';

        if (data.timeout) {
            html += `<div class="ob-result-row">⏳ \${obL('onboarding.result.running_note')}</div>`;
        } else if (data.audit_status === 'failed' || (data.audit && data.audit_error)) {
            const errMsg = (data.audit && data.audit_error) || obL('onboarding.result.audit_failed');
            html += `<div class="ob-result-row"><span class="ob-score-badge muted">—</span><span>\${esc(errMsg)}</span></div>`;
        } else {
            const score = data.audit_score != null ? Math.round(data.audit_score) : (data.audit && data.audit.audit ? Math.round(data.audit.audit.overall_score || 0) : null);
            const findingsCount = data.findings_count != null
                ? data.findings_count
                : ((data.audit && data.audit.findings || []).length);
            const scoreLabel = score != null ? score : '—';
            html += `<div class="ob-result-row"><span class="ob-score-badge\${score == null ? ' muted' : ''}">\${scoreLabel}</span><span>\${obL('onboarding.result.seo_score')} \${findingsCount || 0} \${obL('onboarding.result.findings_found')}</span></div>`;

            if (score != null) {
                const diff = score - 55;
                const dir = diff >= 0 ? 'ob-bench-up' : 'ob-bench-down';
                const txt = diff >= 0
                    ? obL('onboarding.result.benchmark_above') + ' ' + Math.abs(diff) + ' ' + obL('onboarding.result.benchmark_vs')
                    : obL('onboarding.result.benchmark_below') + ' ' + Math.abs(diff) + ' ' + obL('onboarding.result.benchmark_vs');
                html += `<div class="ob-result-row ob-bench \${dir}"><span class="ob-bench-ico">\${diff >= 0 ? '▲' : '▼'}</span><span>\${txt}</span></div>`;
            }

            const cats = data.category_scores || (data.audit && data.audit.category_scores) || null;
            if (cats && Array.isArray(cats) && cats.length) {
                html += `<p class="ob-sub" style="margin:16px 0 6px;">\${obL('onboarding.result.categories_title')}</p>`;
                cats.slice(0, 4).forEach(function (c) {
                    const val = Math.round(c.score || 0);
                    const cls = val < 50 ? 'low' : (val < 75 ? 'mid' : '');
                    html += `<div class="ob-cat-bar-wrap"><span class="ob-cat-label">\${esc(obCatLabel(c.category))}</span><span class="ob-cat-track"><span class="ob-cat-fill \${cls}" style="width:\${val}%"></span></span><span class="ob-cat-value">\${val}</span></div>`;
                });
            }
        }

        const compsCount = data.competitors_count != null ? data.competitors_count : (data.competitors_added ? data.competitors_added.length : 0);
        if (compsCount) {
            html += `<div class="ob-result-row">🕵️ \${compsCount} \${obL('onboarding.result.competitors_added')}</div>`;
        }

        const tasksCount = data.growth_plan_tasks != null
            ? data.growth_plan_tasks
            : (data.growth_plan && data.growth_plan.tasks ? data.growth_plan.tasks.length : 0);
        if (tasksCount) {
            html += `<div class="ob-result-row">🚀 \${obL('onboarding.result.growth_plan_ready')} (\${tasksCount} \${obL('onboarding.result.tasks')})</div>`;
        } else {
            html += `<div class="ob-result-row">ℹ️ \${obL('onboarding.result.growth_plan_pending')}</div>`;
        }

        box.innerHTML = html;
        box.insertAdjacentHTML('beforeend', `
            <div class="ob-result-actions">
                <a href="/dashboard/growth" class="ob-btn primary block" style="text-decoration:none;text-align:center;">\${obL('onboarding.result.cta_growth')}</a>
                <a href="/website-optimizer" class="ob-btn ghost block" style="text-decoration:none;text-align:center;">\${obL('onboarding.result.view_audit')}</a>
            </div>`);
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target && e.target.tagName !== 'TEXTAREA' && obCurrentStep >= 1 && obCurrentStep <= OB_TOTAL_STEPS) {
            const tag = e.target.tagName;
            if (tag === 'INPUT' || tag === 'SELECT') {
                e.preventDefault();
                obNext();
            }
        }
    });

    document.getElementById('obAddCompetitor').addEventListener('click', obAddCompetitor);
    ['obBusinessName', 'obMainUrl', 'obTargetCountry', 'obTargetCustomers', 'obMainServices'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', obSaveDraft);
    });
    document.getElementById('obIndustry').addEventListener('change', obSaveDraft);
    document.getElementById('obCompetitorRows').addEventListener('input', obSaveDraft);

    obRestoreDraft();
    obShowStep(1);
    </script>
</body>
</html>
HTML;
    }

    /** GET /api/onboarding/status?website_id=X (اختياري - بدونه بيرجع حالة كل مواقع الحساب) */
    public function status(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = $this->get('website_id') ? (int) $this->get('website_id') : null;
        $userId = (int) $this->user['id'];

        try {
            $websites = (new Website())->where(['user_id' => $userId], ['id' => 'DESC']);
            $out = [];
            foreach ($websites as $w) {
                $id = (int) $w->getAttribute('id');
                if ($websiteId && $id !== $websiteId) {
                    continue;
                }

                $audit = $this->websiteAuditStatus($id);
                $growth = $this->websiteGrowthPlan($id);
                $competitorsCount = $this->websiteCompetitorsCount($id);

                $out[] = [
                    'id' => $id,
                    'main_url' => (string) $w->getAttribute('main_url'),
                    'company_name' => (string) $w->getAttribute('company_name'),
                    'onboarding_completed' => (bool) ($w->getAttribute('onboarding_completed_at') !== null),
                    'audit_status' => $audit['status'],
                    'audit_score' => $audit['score'],
                    'audit_completed_at' => $audit['completed_at'],
                    'findings_count' => $audit['findings_count'],
                    'category_scores' => $audit['category_scores'],
                    'growth_plan_ready' => $growth['ready'],
                    'growth_plan_tasks' => $growth['tasks'],
                    'growth_plan_summary' => $growth['summary'],
                    'competitors_count' => $competitorsCount,
                ];
            }

            return $this->success([
                'websites' => $out,
            ]);
        } catch (Exception $e) {
            Logger::error('Onboarding Status Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب حالة الإعداد', 500);
        }
    }

    /** حالة أحدث تدقيق SEO للموقع (wo_audits). */
    private function websiteAuditStatus(int $websiteId): array
    {
        $empty = ['status' => 'none', 'score' => null, 'completed_at' => null, 'findings_count' => 0, 'category_scores' => []];
        try {
            $rows = $this->db->query(
                "SELECT id, status, overall_score, completed_at FROM wo_audits WHERE website_id = ? ORDER BY id DESC LIMIT 1",
                [$websiteId]
            );
            if (empty($rows)) {
                return $empty;
            }

            $auditId = (int) $rows[0]['id'];
            $findingsCount = (int) ($this->db->query(
                "SELECT COUNT(*) AS c FROM wo_audit_findings WHERE audit_id = ?",
                [$auditId]
            )[0]['c'] ?? 0);

            $catRows = $this->db->query(
                "SELECT category, COUNT(*) AS total, SUM(status = 'pass') AS passed FROM wo_audit_findings WHERE audit_id = ? GROUP BY category ORDER BY total DESC",
                [$auditId]
            );
            $categoryScores = [];
            foreach ($catRows as $cr) {
                $total = (int) ($cr['total'] ?? 0);
                if ($total <= 0) {
                    continue;
                }
                $passed = (int) ($cr['passed'] ?? 0);
                $categoryScores[] = [
                    'category' => (string) $cr['category'],
                    'score' => (int) round(($passed / $total) * 100),
                ];
            }

            return [
                'status' => (string) ($rows[0]['status'] ?? 'none'),
                'score' => $rows[0]['overall_score'] !== null ? (int) round((float) $rows[0]['overall_score']) : null,
                'completed_at' => $rows[0]['completed_at'] ?? null,
                'findings_count' => $findingsCount,
                'category_scores' => $categoryScores,
            ];
        } catch (Exception $e) {
            return $empty;
        }
    }

    /** حالة خطة النمو (seo_strategy_plans) للموقع. */
    private function websiteGrowthPlan(int $websiteId): array
    {
        $empty = ['ready' => false, 'tasks' => 0, 'summary' => null];
        try {
            $planRows = $this->db->query(
                "SELECT id, summary FROM seo_strategy_plans WHERE website_id = ? ORDER BY id DESC LIMIT 1",
                [$websiteId]
            );
            if (empty($planRows)) {
                return $empty;
            }

            $tasksCount = (int) ($this->db->query(
                "SELECT COUNT(*) AS c FROM seo_strategy_tasks WHERE plan_id = ?",
                [(int) $planRows[0]['id']]
            )[0]['c'] ?? 0);

            return [
                'ready' => true,
                'tasks' => $tasksCount,
                'summary' => (string) ($planRows[0]['summary'] ?? ''),
            ];
        } catch (Exception $e) {
            return $empty;
        }
    }

    /** عدد المنافسين المسجلين للموقع. */
    private function websiteCompetitorsCount(int $websiteId): int
    {
        try {
            return (int) ($this->db->query(
                "SELECT COUNT(*) AS c FROM competitors WHERE website_id = ?",
                [$websiteId]
            )[0]['c'] ?? 0);
        } catch (Exception $e) {
            return 0;
        }
    }
}
