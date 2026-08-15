<?php
/**
 * Tourfecto - Web Routes
 * تعريف مسارات الويب الخاصة بواجهة المستخدم
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

// ============================================
// الصفحة الرئيسية
// ============================================
$router->get('/', 'HomeController', 'index');
$router->get('/sitemap.xml', 'HomeController', 'sitemap');
$router->get('/services/{slug}', 'ServicesController', 'show');

// ============================================
// صفحات المصادقة
// ============================================
$router->get('/login', 'AuthController', 'showLoginForm');
$router->post('/login', 'AuthController', 'login');
$router->get('/login/2fa', 'AuthController', 'showTwoFactorChallenge');
$router->post('/login/2fa/verify', 'AuthController', 'verifyTwoFactor');
$router->get('/register', 'AuthController', 'showRegisterForm');
$router->post('/register', 'AuthController', 'register');
$router->get('/workspace/accept-invite', 'WorkspaceController', 'showAcceptInvitePage');
$router->get('/logout', 'AuthController', 'logout');
$router->get('/forgot-password', 'AuthController', 'showForgotForm');
$router->post('/forgot-password', 'AuthController', 'forgotPassword');
$router->get('/reset-password/{token}', 'AuthController', 'showResetForm');
$router->post('/reset-password', 'AuthController', 'resetPassword');
$router->get('/verify-email/{token}', 'AuthController', 'verifyEmail');

// تسجيل الدخول الاجتماعي (Google/Apple/Facebook/Microsoft)
$router->get('/auth/{provider}', 'AuthController', 'socialRedirect');
$router->get('/auth/{provider}/callback', 'AuthController', 'socialCallback');
$router->post('/auth/apple/callback', 'AuthController', 'appleCallback');

// ============================================
// لوحة التحكم (Dashboard)
// ============================================
$router->group('/dashboard', function($router) {
    $router->get('', 'DashboardController', 'index', ['AuthMiddleware']);
    $router->get('/overview', 'DashboardController', 'overview', ['AuthMiddleware']);
    $router->get('/analytics', 'DashboardController', 'analytics', ['AuthMiddleware']);
    $router->get('/activity', 'DashboardController', 'activity', ['AuthMiddleware']);
    $router->get('/executive', 'DashboardController', 'executive', ['AuthMiddleware']);
    $router->get('/growth', 'DashboardController', 'growth', ['AuthMiddleware']);
});

$router->get('/revenue', 'RevenueController', 'index', ['AuthMiddleware']);
$router->get('/revenue/intelligence', 'RevenueIntelligenceController', 'index', ['AuthMiddleware']);
$router->get('/website-optimizer', 'WebsiteOptimizerController', 'index', ['AuthMiddleware']);
$router->get('/competitor-monitoring', 'CompetitorMonitoringController', 'index', ['AuthMiddleware']);

// ============================================
// Competitor Intelligence (موديول موحّد جديد - لا يستبدل competitor-monitoring القديم)
// ============================================
$router->get('/competitor-intelligence', 'CompetitorIntelligenceController', 'index', ['AuthMiddleware']);
$router->get('/competitor-intelligence/reports/{id}/export', 'CompetitorIntelligenceController', 'exportReportPrintable', ['AuthMiddleware']);

// ============================================
// صفحات الذكاء الاصطناعي
// ============================================
$router->group('/ai', function($router) {
    $router->get('/analyze', 'AIController', 'showAnalyze', ['AuthMiddleware']);
    $router->get('/reports', 'AIController', 'showReports', ['AuthMiddleware']);
    $router->get('/report/{id}', 'AIController', 'showReport', ['AuthMiddleware']);
    $router->get('/competitors', 'AIController', 'showCompetitors', ['AuthMiddleware']);
    $router->get('/keywords', 'AIController', 'showKeywords', ['AuthMiddleware']);
    $router->get('/articles', 'AIController', 'showArticles', ['AuthMiddleware']);
    $router->get('/article/{id}', 'AIController', 'showArticle', ['AuthMiddleware']);
}, ['AuthMiddleware']);

// ============================================
// دمج الموديولات (2026-07-14): السوشيال ميديا، Creative Studio،
// مساعد التسويق الذكي، White-Label، الإعلانات، CRM
// ============================================
$router->get('/social', 'SocialMediaController', 'index', ['AuthMiddleware']);
$router->get('/creative-studio', 'CreativeStudioController', 'index', ['AuthMiddleware']);
$router->get('/marketing-assistant', 'MarketingAssistantController', 'index', ['AuthMiddleware']);
$router->get('/agency', 'AgencyController', 'index', ['AuthMiddleware']);
$router->get('/ads', 'AdsController', 'index', ['AuthMiddleware']);
$router->get('/ads/reports', 'AdsController', 'showReportsPage', ['AuthMiddleware']);
$router->get('/ads/budget', 'AdsController', 'showBudgetPage', ['AuthMiddleware']);
$router->get('/ads/competitors', 'AdsController', 'showCompetitorsPage', ['AuthMiddleware']);
$router->get('/ads/connections', 'AdsController', 'showConnectionsPage', ['AuthMiddleware']);
$router->get('/ads/autopilot', 'AdsController', 'showAutopilotPage', ['AuthMiddleware']);
$router->get('/ads/copilot', 'AdsController', 'showCopilotPage', ['AuthMiddleware']);
$router->get('/ads/alerts', 'AdsController', 'showAlertsPage', ['AuthMiddleware']);
$router->get('/ads/market-research', 'AdsController', 'showMarketResearchPage', ['AuthMiddleware']);
$router->get('/ads/team', 'AdsController', 'showTeamPage', ['AuthMiddleware']);
$router->get('/ads/campaigns/{id}', 'AdsController', 'showCampaignDetailsPage', ['AuthMiddleware']);
$router->get('/ads/connect/meta', 'AdsController', 'connectMeta', ['AuthMiddleware']);
$router->get('/ads/connect/meta/callback', 'AdsController', 'metaOAuthCallback', ['AuthMiddleware']);
$router->get('/ads/connect/meta/choose', 'AdsController', 'showMetaAdAccountPicker', ['AuthMiddleware']);
$router->get('/ads/connect/google-ads', 'AdsController', 'connectGoogleAds', ['AuthMiddleware']);
$router->get('/ads/connect/google-ads/callback', 'AdsController', 'googleAdsOAuthCallback', ['AuthMiddleware']);
$router->get('/ads/connect/google-ads/choose', 'AdsController', 'showGoogleAdsAccountPicker', ['AuthMiddleware']);
$router->get('/ads/connect/google', 'AdsController', 'connectGoogleAds', ['AuthMiddleware']);
$router->get('/ads/connect/google/callback', 'AdsController', 'googleAdsOAuthCallback', ['AuthMiddleware']);
$router->get('/ads/connect/google/choose', 'AdsController', 'showGoogleAdsAccountPicker', ['AuthMiddleware']);
$router->get('/r/{code}', 'AdsController', 'redirectUtmClick');
$router->get('/crm', 'CrmController', 'index', ['AuthMiddleware']);
$router->get('/crm/leads', 'CrmController', 'showLeads', ['AuthMiddleware']);
$router->get('/crm/deals', 'CrmController', 'showDeals', ['AuthMiddleware']);
$router->get('/gbp-content', 'GoogleBusinessContentController', 'index', ['AuthMiddleware']);
$router->get('/review-requests', 'ReviewRequestController', 'index', ['AuthMiddleware']);
$router->get('/ai-assistant', 'AiAssistantController', 'index', ['AuthMiddleware']);
$router->get('/website-builder', 'WebsiteBuilderController', 'index', ['AuthMiddleware']);
$router->get('/dashboard/sites/{id}', 'SiteDashboardController', 'index', ['AuthMiddleware']);
$router->get('/sites/{slug}', 'WebsiteBuilderController', 'showPublicSite');
$router->get('/sites/{slug}/tours/{tourSlug}', 'WebsiteBuilderController', 'showTourDetail');
$router->get('/sites/{slug}/rooms/{roomSlug}', 'WebsiteBuilderController', 'showRoomDetail');
$router->post('/sites/{slug}/lead', 'WebsiteBuilderController', 'submitLead');
$router->post('/sites/{slug}/review', 'WebsiteBuilderController', 'submitReview');

// ============================================
// صفحات إدارة السمعة
// ============================================
$router->group('/reputation', function($router) {
    $router->get('/overview', 'ReputationController', 'showOverview', ['AuthMiddleware']);
    $router->get('/reviews', 'ReputationController', 'showReviews', ['AuthMiddleware']);
    $router->get('/review/{id}', 'ReputationController', 'showReview', ['AuthMiddleware']);
    $router->get('/stats', 'ReputationController', 'showStats', ['AuthMiddleware']);
    $router->get('/platforms', 'ReputationController', 'showPlatforms', ['AuthMiddleware']);
    $router->get('/connect/google/callback', 'ReputationController', 'googleOAuthCallback', ['AuthMiddleware']);
    $router->get('/connect/google/choose', 'ReputationController', 'showGoogleLocationPicker', ['AuthMiddleware']);
    $router->get('/connect/google/{website_id}', 'ReputationController', 'connectGoogleBusiness', ['AuthMiddleware']);
    $router->get('/connect/tripadvisor/{website_id}', 'ReputationController', 'connectTripAdvisor', ['AuthMiddleware']);
}, ['AuthMiddleware']);

// ============================================
// صفحات ربط Google Search Console
// ============================================
$router->group('/search-console', function($router) {
    $router->get('/callback', 'SearchConsoleController', 'callback', ['AuthMiddleware']);
    $router->get('/choose', 'SearchConsoleController', 'showSitePicker', ['AuthMiddleware']);
    $router->get('/connect/{website_id}', 'SearchConsoleController', 'connect', ['AuthMiddleware']);
}, ['AuthMiddleware']);

// ============================================
// صفحة الربط والتكاملات الموحّدة
// ============================================
$router->get('/integrations', 'IntegrationsController', 'index', ['AuthMiddleware']);

// ============================================
// صفحات الشات
// ============================================
$router->group('/chat', function($router) {
    $router->get('', 'ChatController', 'index', ['AuthMiddleware']);
    $router->get('/conversation/{id}', 'ChatController', 'showConversation', ['AuthMiddleware']);
    $router->get('/pending', 'ChatController', 'showPending', ['AuthMiddleware']);
    $router->get('/settings', 'ChatController', 'showSettings', ['AuthMiddleware']);
    $router->get('/knowledge-base', 'ChatController', 'showKnowledgeBase', ['AuthMiddleware']);
    $router->get('/followup-settings', 'ChatController', 'showFollowupSettings', ['AuthMiddleware']);
    $router->get('/analytics', 'ChatController', 'showAnalytics', ['AuthMiddleware']);
    $router->get('/leads', 'ChatController', 'showLeads', ['AuthMiddleware']);
}, ['AuthMiddleware']);

// ============================================
// صفحات الاشتراكات
// ============================================
$router->get('/pricing', 'SubscriptionController', 'showPricing');
$router->get('/subscription', 'SubscriptionController', 'showSubscription', ['AuthMiddleware']);
$router->get('/plans', 'SubscriptionController', 'showPlans');
$router->get('/invoice/{id}', 'SubscriptionController', 'showInvoice', ['AuthMiddleware']);

// ============================================
// صفحات الملف الشخصي
// ============================================
$router->group('/profile', function($router) {
    $router->get('', 'UserController', 'showProfile', ['AuthMiddleware']);
    $router->get('/edit', 'UserController', 'showEditProfile', ['AuthMiddleware']);
    $router->post('/update', 'UserController', 'updateProfile', ['AuthMiddleware']);
    $router->get('/settings', 'UserController', 'showSettings', ['AuthMiddleware']);
    $router->post('/settings', 'UserController', 'updateSettings', ['AuthMiddleware']);
    $router->get('/security', 'UserController', 'showSecurity', ['AuthMiddleware']);
    $router->post('/security', 'UserController', 'updateSecurity', ['AuthMiddleware']);
    $router->get('/api', 'UserController', 'showAPI', ['AuthMiddleware']);
    $router->get('/data-export/download/{id}', 'UserController', 'downloadDataExport', ['AuthMiddleware']);
}, ['AuthMiddleware']);

// ============================================
// صفحات المواقع
// ============================================
$router->group('/websites', function($router) {
    $router->get('', 'WebsiteController', 'index', ['AuthMiddleware']);
    $router->get('/create', 'WebsiteController', 'create', ['AuthMiddleware']);
    $router->post('/store', 'WebsiteController', 'store', ['AuthMiddleware']);
    $router->get('/{id}', 'WebsiteController', 'show', ['AuthMiddleware']);
    $router->get('/{id}/edit', 'WebsiteController', 'edit', ['AuthMiddleware']);
    $router->put('/{id}', 'WebsiteController', 'update', ['AuthMiddleware']);
    $router->delete('/{id}', 'WebsiteController', 'destroy', ['AuthMiddleware']);
}, ['AuthMiddleware']);

// ============================================
// صفحات التقارير
// ============================================
$router->group('/reports', function($router) {
    $router->get('', 'ReportController', 'index', ['AuthMiddleware']);
    $router->get('/export', 'ReportController', 'export', ['AuthMiddleware']);
    $router->get('/scheduled', 'ReportController', 'scheduled', ['AuthMiddleware']);
}, ['AuthMiddleware']);

// ============================================
// صفحات المساعدة والدعم
// ============================================
$router->get('/help', 'HelpController', 'index');
$router->get('/help/faq', 'HelpController', 'faq');
$router->get('/help/contact', 'HelpController', 'contact');
$router->post('/help/contact', 'HelpController', 'sendContact');
$router->get('/docs', 'DocumentationController', 'index');
$router->get('/docs/api', 'DocumentationController', 'api');
$router->get('/docs/guide', 'DocumentationController', 'guide');

// ============================================
// الصفحات القانونية
// ============================================
$router->get('/terms', 'LegalController', 'terms');
$router->get('/privacy', 'LegalController', 'privacy');
$router->get('/cookies', 'LegalController', 'cookies');
$router->get('/gdpr', 'LegalController', 'gdpr');
$router->get('/data-deletion', 'LegalController', 'dataDeletion');

// ============================================
// مسارات إدارية (Admin Web)
// ============================================
$router->group('/admin', function($router) {
    $router->get('', 'AdminController', 'index', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/platform', 'AdminController', 'platform', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/users', 'AdminController', 'users', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/users/{id}', 'AdminController', 'userDetail', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/subscriptions', 'AdminController', 'subscriptions', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/contact-messages', 'AdminController', 'contactMessages', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/export/users', 'AdminController', 'exportUsers', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/export/subscriptions', 'AdminController', 'exportSubscriptions', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/plans', 'AdminController', 'plans', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/system', 'AdminController', 'system', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/logs', 'AdminController', 'logs', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/settings', 'AdminController', 'settings', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/login-history', 'AdminController', 'loginHistory', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/visitors', 'AdminController', 'visitorStatsPage', ['AuthMiddleware', 'AdminMiddleware']);
}, ['AuthMiddleware', 'AdminMiddleware']);

// ============================================
// معالجة الأخطاء
// ============================================
$router->any('/404', 'ErrorController', 'notFound');
$router->any('/403', 'ErrorController', 'forbidden');
$router->any('/500', 'ErrorController', 'serverError');

// ============================================
// ملفات ثابتة
// ============================================
$router->get('/assets/{path}', 'AssetController', 'serve');
$router->get('/favicon.ico', 'AssetController', 'favicon');
$router->get('/robots.txt', 'AssetController', 'robots');
$router->get('/sitemap.xml', 'AssetController', 'sitemap');
$router->get('/.well-known/{path}', 'AssetController', 'wellKnown');

// ============================================
// مسارات الحالة الصحية (للمراقبة)
// ============================================
$router->get('/health', 'HealthController', 'webCheck');
$router->get('/ping', 'HealthController', 'ping');