<?php

/**
 * Tourfecto - Base Controller Class
 * كلاس التحكم الأساسي لجميع المتحكمات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

abstract class Controller
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    protected $db;

    /**
     * @var array $data - بيانات الطلب
     */
    protected $data = [];

    /**
     * @var array $input - بيانات الإدخال (JSON)
     */
    protected $input = [];

    /**
     * @var array $errors - أخطاء التحقق
     */
    protected $errors = [];

    /**
     * @var bool $authenticated - حالة المصادقة
     */
    protected $authenticated = false;

    /**
     * @var array $user - بيانات المستخدم الحالي
     */
    protected $user = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->parseInput();
        $this->loadAuthenticatedUser();
    }

    /**
     * ربط حالة المصادقة التي يضبطها AuthMiddleware (عبر $_SESSION أو $_SERVER)
     * بخصائص الكلاس $user / $authenticated.
     *
     * ملاحظة: هذه الدالة أُضيفت لأن AuthMiddleware وConstroller::authenticate()
     * كانا منفصلين تمامًا؛ AuthMiddleware يتحقق من التوكن ويخزّن المستخدم في
     * $_SESSION['user']، لكن لا شيء كان يستدعي $this->authenticate() داخل
     * المتحكمات، فكانت $this->isAuthenticated() ترجع false دائمًا حتى مع
     * تسجيل دخول صحيح، ما يعطّل كل مسارات AI/Chat/Dashboard/Reputation/Subscription.
     */
    protected function loadAuthenticatedUser(): void
    {
        $user = $_SESSION['user'] ?? ($_SERVER['auth_user'] ?? null);

        if (is_array($user) && !empty($user)) {
            $this->user = $user;
            $this->authenticated = true;
        }
    }

    /**
     * تحليل بيانات الإدخال
     */
    protected function parseInput(): void
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (strpos($contentType, 'application/json') !== false) {
            $input = file_get_contents('php://input');
            $this->input = json_decode($input, true) ?? [];
        } else {
            $this->input = $_POST;
        }

        // دمج مع بيانات GET
        $this->data = array_merge($this->input, $_GET);
    }

    /**
     * إرسال استجابة ناجحة
     * @param array $data
     * @param string $message
     * @param int $code
     * @return array
     */
    protected function success(array $data = [], string $message = 'Success', int $code = 200): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'code' => $code
        ];
    }

    /**
     * إرسال استجابة خطأ
     * @param string $error
     * @param int $code
     * @param array $details
     * @return array
     */
    protected function error(string $error, int $code = 400, array $details = []): array
    {
        return [
            'success' => false,
            'error' => $error,
            'code' => $code,
            'details' => $details
        ];
    }

    /**
     * فحص CSRF اختياري (Opt-in) - بيتنادى من أي Controller/Method بيقرر
     * يستخدمه بنفسه صراحة. بيستثني عملاء الـ Bearer Token (API Keys
     * الشخصية، JWT، مفاتيح الشركاء) لأنهم مش معتمدين على كوكي الجلسة.
     * @return array|null يرجع مصفوفة خطأ (419) لو فشل الفحص، أو null لو ناجح
     */
    protected function verifyCsrf(): ?array
    {
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        if (!empty($headers['Authorization']) || !empty($headers['authorization'])) {
            return null;
        }

        $submitted = (string) $this->get('csrf_token');
        if (!class_exists('Csrf') || !Csrf::verify($submitted)) {
            return $this->error('انتهت صلاحية الجلسة، حدّث الصفحة وحاول تاني', 419);
        }
        return null;
    }

    /**
     * الحصول على قيمة من الإدخال
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * الحصول على جميع قيم الإدخال
     * @return array
     */
    protected function all(): array
    {
        return $this->data;
    }

    /**
     * التحقق من وجود قيمة في الإدخال
     * @param string $key
     * @return bool
     */
    protected function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * التحقق من البيانات
     * @param array $rules
     * @return bool
     */
    protected function validate(array $rules): bool
    {
        $validator = new Validator();
        $result = $validator->validate($this->data, $rules);

        if (!$result['valid']) {
            $this->errors = $result['errors'];
            return false;
        }

        return true;
    }

    /**
     * الحصول على أخطاء التحقق
     * @return array
     */
    protected function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * مصادقة المستخدم
     * @param string $token
     * @return bool
     */
    protected function authenticate(string $token): bool
    {
        try {
            // التحقق من التوكن في قاعدة البيانات
            // تصحيح: لا يوجد عمود is_active، العمود الفعلي هو status='active'
            $sql = "SELECT * FROM users WHERE api_token = :token AND status = 'active' LIMIT 1";
            $result = $this->db->query($sql, [':token' => $token]);

            if (empty($result)) {
                return false;
            }

            $this->user = $result[0];
            $this->authenticated = true;
            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * التحقق من المصادقة
     * @return bool
     */
    protected function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    /**
     * الحصول على بيانات المستخدم الحالي
     * @return array
     */
    protected function getUser(): array
    {
        return $this->user;
    }

    /**
     * التحقق من صلاحية المستخدم
     * @param string $permission
     * @return bool
     */
    protected function hasPermission(string $permission): bool
    {
        if (!$this->isAuthenticated()) {
            return false;
        }

        // التحقق من صلاحيات المستخدم
        // يمكن توسيع هذا حسب نظام الصلاحيات
        return true;
    }

    /**
     * تسجيل نشاط
     * @param string $action
     * @param array $data
     */
    protected function log(string $action, array $data = []): void
    {
        Logger::info('User Action: ' . $action, [
            'user_id' => $this->user['id'] ?? null,
            'data' => $data,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    }

    // ============================================
    // Panel Layout المشترك (نفس الشكل المستخدم في DashboardController)
    // ============================================
    // ملاحظة: تم نقل توليد الـ Sidebar/الشِل هنا بدل ما يتكرر في كل
    // Controller (AIController, ReputationController, ChatController...)
    // كل واحدة منهم أصلاً كانت بترجع JSON فاضي بدل صفحة HTML حقيقية.

    /**
     * سايد بار لوحة العميل - نفس القائمة المستخدمة في /dashboard
     * @param string $activeTab
     * @return string
     */
    /**
     * اختصار t() + htmlspecialchars للاستخدام الآمن جوه heredoc HTML.
     * @param string $key
     * @return string
     */
    /**
     * زي tr() بس بترجع الترجمة كـ JS string literal جاهز للحقن المباشر
     * جوه <script> (مع quotes وescaping صحيح) - مختلفة عن tr() اللي
     * بتعمل htmlspecialchars للاستخدام جوه HTML.
     * @param string $key
     * @return string
     */
    protected function trJs(string $key): string
    {
        return json_encode(t($key), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);
    }

    protected function tr(string $key): string
    {
        return htmlspecialchars(t($key), ENT_QUOTES, 'UTF-8');
    }

    /**
     * قاموس الترجمة الحالي كـ JSON، يتحقن في window.I18N جوه الصفحة
     * عشان الـ JS بتاع كل صفحة (اللي مكتوب بـ nowdoc <<<'JS' عشان
     * template literals ${} تشتغل صح) يقدر يستخدم I18N['key'] من غير
     * ما نحتاج نحوّل أي nowdoc لـ heredoc (خطر حقيقي لأن ${} ممكن PHP
     * يفهمها غلط كمتغير).
     * @return string
     */
    protected function i18nJson(): string
    {
        return json_encode(load_translations(current_lang()), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);
    }

    protected function renderPanelSidebar(string $activeTab): string
    {
        $isAdmin = in_array($this->user['role'] ?? 'user', ['admin', 'super_admin'], true);

        $groups = [
            t('sidebar.group.assistant') => [
                'ai_assistant' => [t('sidebar.ai_assistant'), '✨', '/ai-assistant'],
                'website_builder' => [t('sidebar.website_builder'), '🏗️', '/website-builder'],
            ],
            t('sidebar.group.main') => [
                'overview' => [t('sidebar.overview'), '📊', '/dashboard'],
                'executive' => [t('sidebar.executive'), '🧭', '/dashboard/executive'],
                'growth' => [t('sidebar.growth'), '🚀', '/dashboard/growth'],
                'analytics' => [t('sidebar.analytics'), '📈', '/dashboard/analytics'],
                'activity' => [t('sidebar.activity'), '🕓', '/dashboard/activity'],
            ],
            t('sidebar.group.business_intelligence') => [
                'revenue' => [t('sidebar.revenue'), '💰', '/revenue'],
                'revenue_intelligence' => [t('sidebar.revenue_intelligence'), '🧠', '/revenue/intelligence'],
                'website_optimizer' => [t('sidebar.website_optimizer'), '🛠️', '/website-optimizer'],
                'competitor_monitoring' => [t('sidebar.competitor_monitoring'), '🕵️', '/competitor-monitoring'],
                'competitor_intelligence' => [t('sidebar.competitor_intelligence'), '🕵️‍♂️', '/competitor-intelligence'],
            ],
            // تصحيح تنظيمي: كانت مجموعة "التسويق" فيها 9 عناصر مكدّسة سوا
            // (تحليل SEO، تقارير، مقالات، منافسين، كلمات مفتاحية، سوشيال،
            // إعلانات، مساعد تسويقي، استوديو إبداعي) - إحساس فعلي بالفوضى.
            // اتقسّمت هنا لمجموعتين منطقيتين: محتوى AI تحليلي، وتوزيع/نشر.
            t('sidebar.group.ai_content') => [
                'ai_analyze' => [t('sidebar.seo_analysis'), '🤖', '/ai/analyze'],
                'ai_reports' => [t('sidebar.ai_reports'), '🗂️', '/ai/reports'],
                'ai_articles' => [t('sidebar.ai_articles'), '✍️', '/ai/articles'],
                'ai_competitors' => [t('sidebar.ai_competitors'), '🏁', '/ai/competitors'],
                'ai_keywords' => [t('sidebar.ai_keywords'), '🔑', '/ai/keywords'],
            ],
            t('sidebar.group.distribution') => [
                'social' => [t('sidebar.social'), '📱', '/social'],
                'ads' => [t('sidebar.ads'), '📣', '/ads'],
                'marketing_assistant' => [t('sidebar.marketing_assistant'), '💡', '/marketing-assistant'],
                'creative_studio' => [t('sidebar.creative_studio'), '🎨', '/creative-studio'],
            ],
            t('sidebar.group.customers') => [
                'crm' => [t('sidebar.crm'), '🧾', '/crm'],
            ],
            // ============================================================
            // AI Chat Platform (2026-08-16) - مجموعة مستقلة كاملة لموديول
            // الشات الذكي، تعادل منتجًا منفصلًا بذاته (Intercom/Zendesk).
            // صندوق الوارد الموحّد + التحليلات + قاعدة المعرفة + حلقة
            // التعلّم (فجوات المعرفة) + Leads + المتابعة التلقائية.
            // ملاحظة: استُبدل العنصر الأحادي القديم 'chat' بهذه المجموعة
            // الكاملة، وكل صفحة بتستخدم مفتاحها النشط المنفصل في الـSidebar.
            // ============================================================
            t('sidebar.group.ai_chat') => [
                'ai_chat_inbox' => [t('sidebar.ai_chat_inbox'), '💬', '/chat'],
                'ai_chat_analytics' => [t('sidebar.ai_chat_analytics'), '📊', '/chat/analytics'],
                'ai_chat_knowledge' => [t('sidebar.ai_chat_knowledge'), '📚', '/chat/knowledge-base'],
                'ai_chat_learning' => [t('sidebar.ai_chat_learning'), '🧠', '/chat/learning'],
                'ai_chat_leads' => [t('sidebar.ai_chat_leads'), '🎯', '/chat/leads'],
                'ai_chat_followup' => [t('sidebar.ai_chat_followup'), '⏰', '/chat/followup-settings'],
            ],
            // تصحيح تنظيمي: صفحات السمعة الثلاثة (المراجعات، نظرة عامة،
            // إحصائيات) + محتوى Google Business كانوا متفرّقين وسط مجموعة
            // "العملاء" من غير ما يبان إنهم مرتبطين ببعض - دلوقتي في مجموعة
            // واحدة واضحة اسمها "السمعة" عشان تبان علاقتهم ببعض فورًا.
            t('sidebar.group.reputation') => [
                'reputation' => [t('sidebar.reputation'), '⭐', '/reputation/reviews'],
                'reputation_overview' => [t('sidebar.reputation_overview'), '📊', '/reputation/overview'],
                'reputation_stats' => [t('sidebar.reputation_stats'), '📈', '/reputation/stats'],
                'reputation_intelligence' => [t('sidebar.reputation_intelligence'), '🧠', '/reputation/intelligence'],
                'gbp_content' => [t('sidebar.gbp_content'), '📍', '/gbp-content'],
                'review_requests' => [t('sidebar.review_requests'), '📨', '/review-requests'],
            ],
            t('sidebar.group.agency') => [
                'agency' => [t('sidebar.agency'), '🏢', '/agency'],
            ],
            // تصحيح تنظيمي: "المواقع" و"التكاملات" كانوا في مجموعة
            // "العملاء" رغم إنهم إعدادات أساسية للحساب مش عن العملاء -
            // نقلناهم هنا مع الاشتراك والملف الشخصي، أماكنهم المنطقية.
            t('sidebar.group.account') => [
                '_websites' => [t('sidebar.websites'), '🌐', '/websites'],
                '_integrations' => [t('sidebar.integrations'), '🔗', '/integrations'],
                '_subscription' => [t('sidebar.subscription'), '💳', '/subscription'],
                '_profile' => [t('sidebar.profile'), '👤', '/profile/settings'],
            ],
        ];

        if ($isAdmin) {
            $groups[t('sidebar.group.account')]['_admin'] = [t('sidebar.admin'), '🛠️', '/admin'];
        }

        $featureCheck = class_exists('FeatureFlagService') && !empty($this->user['id']) ? new FeatureFlagService() : null;
        $enabledMap = $featureCheck !== null ? $featureCheck->getEnabledMap((int) $this->user['id']) : [];

        $html = '';
        foreach ($groups as $groupTitle => $items) {
            $groupHtml = '';
            foreach ($items as $key => $item) {
                // مفاتيح البادئة بـ "_" (المواقع، التكاملات، الاشتراك،
                // الملف الشخصي، الأدمن) إعدادات حساب أساسية مش ميزات
                // بيزنس قابلة للإخفاء - نستثنيهم من فحص التحكم في الميزات.
                if (strpos($key, '_') !== 0 && isset($enabledMap[$key]) && !$enabledMap[$key]) {
                    continue;
                }
                [$label, $icon, $href] = $item;
                $active = $key === $activeTab ? ' active' : '';
                $groupHtml .= "<a href=\"{$href}\" class=\"panel-nav-link{$active}\"><span class=\"ic\">{$icon}</span>{$label}</a>";
            }
            if ($groupHtml === '') {
                continue; // كل عناصر المجموعة دي متعطّلة - منعرضش عنوان مجموعة فاضي
            }
            $html .= '<div class="panel-nav-group"><div class="panel-nav-group-title">' . htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8') . '</div>' . $groupHtml . '</div>';
        }

        return $html;
    }

    /**
     * الشِل الكامل لأي صفحة داخل لوحة العميل (نفس تصميم /dashboard تمامًا).
     * الصفحات (AI, السمعة, الشات...) بتمرر بس عنوانها ومحتواها وجافاسكريبت
     * التفاعل بتاعها، وده بيتجنب تكرار كود الـ layout في كل Controller.
     *
     * @param string $activeTab مفتاح العنصر النشط في السايد بار
     * @param string $pageTitle
     * @param string $pageSubtitle
     * @param string $bodyHtml محتوى panel-content (HTML كامل)
     * @param string $scriptJs كود JS بيتنفذ بعد تحميل الصفحة (من غير <script> tags)
     * @return string
     */
    protected function renderPanelPage(string $activeTab, string $pageTitle, string $pageSubtitle, string $bodyHtml, string $scriptJs = ''): string
    {
        // تصحيح مهم: الصفحات دي فيها بيانات مستخدم شخصية (شات/تقارير/إعدادات)
        // ومحتاجة تفضل ديناميكية دايمًا. من غير الهيدرز دي، أي كاش على
        // مستوى السيرفر (زي LiteSpeed LSCache اللي شفناه في اللوج) ممكن
        // يخزّن نسخة قديمة أو حتى (في أسوأ الحالات) يوريها لمستخدم تاني.
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
            header('Pragma: no-cache');
            header('X-LiteSpeed-Cache-Control: no-cache');
        }

        // التحكم في الميزات: فحص مركزي واحد هنا بيغطي كل صفحات اللوحة
        // تلقائيًا - لو الأدمن عطّل الميزة دي (عام أو لعميل بعينه)،
        // نستبدل المحتوى برسالة واضحة بدل ما نعرض الصفحة، مع الحفاظ
        // على القائمة الجانبية والشريط العلوي عاديين.
        if (!empty($this->user['id']) && class_exists('FeatureFlagService')) {
            $featureCheck = new FeatureFlagService();
            if (!$featureCheck->isEnabled($activeTab, (int) $this->user['id'])) {
                $bodyHtml = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">🔒</div>'
                    . $this->tr('feature.disabled_message') . '</div></div>';
                $scriptJs = '';
            }
        }

        $appName = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
        $companyName = htmlspecialchars($this->user['company_name'] ?? t('sidebar.profile'), ENT_QUOTES, 'UTF-8');
        $userEmail = htmlspecialchars($this->user['email'] ?? '', ENT_QUOTES, 'UTF-8');
        $userInitial = htmlspecialchars(mb_substr($companyName, 0, 1), ENT_QUOTES, 'UTF-8');
        $navHtml = $this->renderPanelSidebar($activeTab);
        $pageTitleSafe = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');
        $pageSubtitleSafe = htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8');
        $lang = current_lang();
        $dir = current_dir();

        $langMenu = '';
        foreach (language_switcher_links() as $l) {
            $activeClass = $l['active'] ? ' on' : '';
            $langMenu .= "<a href=\"{$l['url']}\" class=\"{$activeClass}\">{$l['label']}</a>";
        }

        // تصحيح باغ فادح: {asset_v('/path')} جوه heredoc مبيتفسّرش من PHP
        // خالص (الصياغة دي بتشتغل بس لمتغيرات زي {$var}، مش لاستدعاء
        // دالة مباشر) - كان بيطبع النص حرفيًا فيكسر كل روابط CSS/JS في
        // الموقع كله. الحل: نحسب القيمة في متغير الأول ونستخدم المتغير.
        $styleCssUrl = asset_v('/assets/css/style.css');
        $panelCssUrl = asset_v('/assets/css/panel.css');
        $panelJsUrl = asset_v('/assets/js/panel.js');
        // وحدة الشات: طبقة مكوّنات احترافية (chat.css + chat-panel.js)
        // بتتحقن بس لما الصفحة بتاعة الشات - باقي اللوحة مبيتأثرش بيها.
        $chatAssetsHead = '';
        $chatAssetsFoot = '';
        if ($activeTab === 'chat') {
            $chatAssetsHead = '    <link rel="stylesheet" href="' . asset_v('/assets/css/chat.css') . '">' . "\n";
            $chatAssetsFoot = '    <script src="' . asset_v('/assets/js/chat-panel.js') . '"></script>' . "\n";
        }
        // نفس باغ asset_v بالظبط - site_brand_html() لازم يتحسب في متغير
        $brandHtml = site_brand_html();

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#060A13">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">
    <title>{$pageTitleSafe} | {$appName}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/icons/favicon-16.png">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
    <link rel="stylesheet" href="{$styleCssUrl}">
    <link rel="stylesheet" href="{$panelCssUrl}">
{$chatAssetsHead}</head>
<body>
    <div class="panel-shell">
        <div class="panel-overlay-bg"></div>
        <aside class="panel-sidebar">
            <div class="panel-brand">
                {$brandHtml}
            </div>
            <div class="panel-user-mini">
                <div class="avatar">{$userInitial}</div>
                <div class="info">
                    <div class="name">{$companyName}</div>
                    <div class="role">{$userEmail}</div>
                </div>
            </div>
            <nav class="panel-nav">{$navHtml}</nav>
            <div class="panel-sidebar-footer">
                <a href="/logout">🚪 {$this->tr('nav.logout')}</a>
            </div>
        </aside>

        <div class="panel-main">
            <header class="panel-topbar">
                <button class="panel-menu-toggle" id="panelMenuToggle">☰</button>
                <div>
                    <h1>{$pageTitleSafe}</h1>
                    <div class="subtitle">{$pageSubtitleSafe}</div>
                </div>
                <div class="panel-topbar-spacer"></div>
                <div class="panel-topbar-actions">
                    <select id="panelWebsiteSelect" class="panel-website-select" style="display:none;" title="{$this->tr('website_context.tooltip')}"></select>
                    <a href="/subscription" id="panelWalletWrap" class="panel-credits" style="display:none;" title="{$this->tr('wallet.tooltip')}">
                        <span>💰</span>
                        <span id="panelWalletText"></span>
                    </a>
                    <a href="/subscription" id="panelCreditsWrap" class="panel-credits" style="display:none;" title="{$this->tr('credits.tooltip')}">
                        <span id="panelCreditsIcon">🤖</span>
                        <span id="panelCreditsText"></span>
                    </a>
                    <div class="panel-notif" id="panelNotifWrap">
                        <button class="icon-btn" id="panelNotifBtn" title="الإشعارات" style="position:relative;">
                            🔔<span id="panelNotifBadge" class="panel-notif-badge" style="display:none;">0</span>
                        </button>
                        <div class="panel-notif-menu" id="panelNotifMenu" style="display:none;"></div>
                    </div>
                    <details class="panel-langsel">
                        <summary class="icon-btn" title="{$this->tr('lang.switch')}">🌐</summary>
                        <div class="panel-langsel-menu">{$langMenu}</div>
                    </details>
                    <a href="/ai/analyze" class="icon-btn" title="{$this->tr('dashboard.action.new_seo_analysis')}">✨</a>
                    <a href="/profile/settings" class="icon-btn" title="{$this->tr('sidebar.profile')}">👤</a>
                </div>
            </header>

            <div class="panel-content">
                {$bodyHtml}
            </div>
        </div>
    </div>

    <div id="toastStack"></div>

    <script>window.I18N = {$this->i18nJson()};</script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
    <script src="{$panelJsUrl}"></script>
{$chatAssetsFoot}    <script>{$scriptJs}</script>
<button id="pwaInstallBtn" class="pwa-install-fab" type="button" aria-label="تثبيت التطبيق" title="تثبيت التطبيق">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <span>تثبيت التطبيق</span>
</button>
<style>
.pwa-install-fab {
    position: fixed;
    bottom: 24px;
    left: 24px;
    z-index: 9999;
    display: none;
    align-items: center;
    gap: 8px;
    background: var(--primary-color, #0077be);
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: 12px 18px;
    font-family: inherit;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    box-shadow: 0 8px 24px rgba(0, 119, 190, .35);
    transition: transform .2s ease, box-shadow .2s ease, opacity .2s ease;
}
.pwa-install-fab:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(0, 119, 190, .45);
}
.pwa-install-fab svg { flex-shrink: 0; }
@media (max-width: 480px) {
    .pwa-install-fab span { display: none; }
    .pwa-install-fab { padding: 14px; border-radius: 50%; bottom: 18px; left: 18px; }
}
</style>
<script>
(function () {
    var btn = document.getElementById('pwaInstallBtn');
    if (!btn) return;
    var deferredPrompt = null;

    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    }

    if (isStandalone()) return;

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        btn.style.display = 'flex';
    });

    btn.addEventListener('click', function () {
        if (!deferredPrompt) return;
        btn.style.display = 'none';
        var promptEvent = deferredPrompt;
        deferredPrompt = null;
        promptEvent.prompt();
        promptEvent.userChoice.then(function () {});
    });

    window.addEventListener('appinstalled', function () {
        btn.style.display = 'none';
        deferredPrompt = null;
    });
})();
</script>
</body>
</html>
HTML;
    }
}
