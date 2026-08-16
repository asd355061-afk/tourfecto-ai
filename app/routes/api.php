<?php

/**
 * Tourfecto - API Routes
 * تعريف جميع مسارات API الخاصة بالتطبيق
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

// ============================================
// مسارات المصادقة (Authentication)
// ============================================
$router->post('/api/auth/login', 'AuthController', 'login');
$router->post('/api/auth/register', 'AuthController', 'register');
$router->post('/api/auth/logout', 'AuthController', 'logout');
$router->post('/api/auth/refresh', 'AuthController', 'refresh');
$router->post('/api/auth/forgot-password', 'AuthController', 'forgotPassword');
$router->post('/api/auth/reset-password', 'AuthController', 'resetPassword');
$router->post('/api/auth/verify-email', 'AuthController', 'verifyEmail');
$router->post('/api/auth/resend-verification', 'AuthController', 'resendVerification');

// ============================================
// مسارات المستخدم (User)
// ============================================
$router->get('/api/user/profile', 'UserController', 'profile', ['AuthMiddleware']);
$router->put('/api/user/profile', 'UserController', 'updateProfile', ['AuthMiddleware']);
$router->post('/api/user/avatar', 'UserController', 'uploadAvatar', ['AuthMiddleware']);
$router->put('/api/user/password', 'UserController', 'updatePassword', ['AuthMiddleware']);
$router->get('/api/user/settings', 'UserController', 'getSettings', ['AuthMiddleware']);
$router->put('/api/user/settings', 'UserController', 'updateSettings', ['AuthMiddleware']);
// Profile Center Phase 5 (2026-08-10): Two-Factor Authentication + OAuth disconnect
$router->delete('/api/user/oauth/{provider}', 'UserController', 'disconnectOAuth', ['AuthMiddleware']);
$router->post('/api/user/2fa/setup', 'UserController', 'setupTwoFactor', ['AuthMiddleware']);
$router->post('/api/user/2fa/enable', 'UserController', 'enableTwoFactor', ['AuthMiddleware']);
$router->post('/api/user/2fa/disable', 'UserController', 'disableTwoFactor', ['AuthMiddleware']);
// Profile Center Phase 9 (2026-08-10): Data Export
$router->post('/api/user/data-export', 'UserController', 'requestDataExport', ['AuthMiddleware']);
$router->get('/api/user/data-export', 'UserController', 'listDataExports', ['AuthMiddleware']);
$router->delete('/api/user/account', 'UserController', 'deleteAccount', ['AuthMiddleware']);
// Tourfecto Account & Workspace Settings Center (Phases 2-8, 2026-08-09)
$router->get('/api/user/sessions', 'UserController', 'listSessions', ['AuthMiddleware']);
$router->post('/api/user/sessions/{id}/logout', 'UserController', 'logoutSession', ['AuthMiddleware']);
$router->post('/api/user/sessions/logout-others', 'UserController', 'logoutOtherSessions', ['AuthMiddleware']);
$router->get('/api/user/api-keys', 'UserController', 'listApiKeys', ['AuthMiddleware']);
$router->post('/api/user/api-keys', 'UserController', 'createApiKey', ['AuthMiddleware']);
$router->post('/api/user/api-keys/{id}/revoke', 'UserController', 'revokeApiKey', ['AuthMiddleware']);
$router->get('/api/user/audit-log', 'UserController', 'listAuditLog', ['AuthMiddleware']);
$router->post('/api/user/deactivate', 'UserController', 'deactivateAccount', ['AuthMiddleware']);
$router->get('/api/workspace', 'WorkspaceController', 'getWorkspace', ['AuthMiddleware']);
$router->put('/api/workspace', 'WorkspaceController', 'updateWorkspace', ['AuthMiddleware']);
$router->post('/api/workspace/logo', 'WorkspaceController', 'uploadLogo', ['AuthMiddleware']);
$router->get('/api/workspace/members', 'WorkspaceController', 'listMembers', ['AuthMiddleware']);
$router->post('/api/workspace/invite', 'WorkspaceController', 'inviteMember', ['AuthMiddleware']);
$router->get('/api/workspace/invites', 'WorkspaceController', 'listInvites', ['AuthMiddleware']);
$router->post('/api/workspace/invites/{id}/revoke', 'WorkspaceController', 'revokeInvite', ['AuthMiddleware']);
$router->put('/api/workspace/members/{id}/role', 'WorkspaceController', 'changeRole', ['AuthMiddleware']);
$router->post('/api/workspace/members/{id}/deactivate', 'WorkspaceController', 'deactivateMember', ['AuthMiddleware']);
$router->post('/api/workspace/members/{id}/reactivate', 'WorkspaceController', 'reactivateMember', ['AuthMiddleware']);
$router->delete('/api/workspace/members/{id}', 'WorkspaceController', 'removeMember', ['AuthMiddleware']);
$router->post('/api/workspace/leave', 'WorkspaceController', 'leaveWorkspace', ['AuthMiddleware']);
$router->get('/api/workspace/invite/{token}', 'WorkspaceController', 'showInvite');
$router->post('/api/workspace/invite/{token}/accept', 'WorkspaceController', 'acceptInvite');

// ============================================
// Business Control Center (Phases 1-7, 2026-08-14)
// Business Profile منفصل عن User Profile + Locations + Services +
// Target Markets + AI Business Context + Brand Settings.
// كل المسارات AuthMiddleware-protected (زي باقي /api/user/*).
// ============================================
$router->get('/api/business', 'BusinessController', 'show', ['AuthMiddleware']);
$router->get('/api/business/overview', 'BusinessController', 'overview', ['AuthMiddleware']);
$router->post('/api/business', 'BusinessController', 'store', ['AuthMiddleware']);
$router->put('/api/business/{id}', 'BusinessController', 'update', ['AuthMiddleware']);
$router->get('/api/business/{businessId}/locations', 'BusinessLocationController', 'index', ['AuthMiddleware']);
$router->post('/api/business/{businessId}/locations', 'BusinessLocationController', 'store', ['AuthMiddleware']);
$router->put('/api/business/locations/{id}', 'BusinessLocationController', 'update', ['AuthMiddleware']);
$router->delete('/api/business/locations/{id}', 'BusinessLocationController', 'destroy', ['AuthMiddleware']);
$router->get('/api/business/{businessId}/services', 'BusinessServiceController', 'index', ['AuthMiddleware']);
$router->post('/api/business/{businessId}/services', 'BusinessServiceController', 'store', ['AuthMiddleware']);
$router->put('/api/business/services/{id}', 'BusinessServiceController', 'update', ['AuthMiddleware']);
$router->delete('/api/business/services/{id}', 'BusinessServiceController', 'destroy', ['AuthMiddleware']);
$router->get('/api/business/{businessId}/markets', 'BusinessTargetMarketController', 'show', ['AuthMiddleware']);
$router->put('/api/business/{businessId}/markets', 'BusinessTargetMarketController', 'upsert', ['AuthMiddleware']);
$router->get('/api/business/{businessId}/ai-context', 'BusinessAiContextController', 'show', ['AuthMiddleware']);
$router->get('/api/business/{businessId}/ai-context/full', 'BusinessAiContextController', 'full', ['AuthMiddleware']);
$router->put('/api/business/{businessId}/ai-context', 'BusinessAiContextController', 'upsert', ['AuthMiddleware']);
$router->get('/api/business/{businessId}/brand', 'BusinessBrandSettingsController', 'show', ['AuthMiddleware']);
$router->put('/api/business/{businessId}/brand', 'BusinessBrandSettingsController', 'upsert', ['AuthMiddleware']);

// ============================================
// مسارات الذكاء الاصطناعي (AI)
// ============================================
$router->post('/api/ai/analyze', 'AIController', 'analyze', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_competitor_analysis'
]);
$router->get('/api/ai/report/{id}', 'AIController', 'getReport', ['AuthMiddleware']);
$router->get('/api/ai/reports', 'AIController', 'getReports', ['AuthMiddleware']);
$router->get('/api/ai/report/{id}/export', 'AIController', 'exportReport', ['AuthMiddleware']);
$router->delete('/api/ai/report/{id}', 'AIController', 'deleteReport', ['AuthMiddleware']);

// مولّد المقالات التسويقية
$router->post('/api/ai/article', 'AIController', 'generateArticle', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_ai_credits'
]);
$router->get('/api/ai/articles', 'AIController', 'getArticles', ['AuthMiddleware']);
$router->get('/api/ai/article/{id}', 'AIController', 'getArticle', ['AuthMiddleware']);
$router->get('/api/ai/article/{id}/export', 'AIController', 'exportArticle', ['AuthMiddleware']);
$router->delete('/api/ai/article/{id}', 'AIController', 'deleteArticle', ['AuthMiddleware']);

// نشر المقال مباشرة على ووردبريس
$router->get('/api/publishing/status/{website_id}', 'AIController', 'publishingStatus', ['AuthMiddleware']);
$router->post('/api/publishing/wordpress/connect', 'AIController', 'connectWordPress', ['AuthMiddleware']);
$router->post('/api/publishing/custom/connect', 'AIController', 'connectCustomApi', ['AuthMiddleware']);
$router->post('/api/publishing/disconnect/{website_id}', 'AIController', 'disconnectPublishing', ['AuthMiddleware']);
$router->post('/api/ai/article/{id}/publish', 'AIController', 'publishArticle', ['AuthMiddleware']);
$router->post('/api/ai/article/{id}/schedule', 'AIController', 'scheduleArticle', ['AuthMiddleware']);
$router->post('/api/ai/article/{id}/schedule/cancel', 'AIController', 'cancelScheduledArticle', ['AuthMiddleware']);

// تحليلات إضافية
$router->post('/api/ai/keywords', 'AIController', 'analyzeKeywords', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_ai_credits'
]);
$router->post('/api/ai/competitors', 'AIController', 'analyzeCompetitors', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_competitor_analysis'
]);
$router->post('/api/ai/sentiment', 'AIController', 'analyzeSentiment', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_ai_credits'
]);
$router->post('/api/ai/translate', 'AIController', 'translate', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_ai_credits'
]);

// ============================================
// مسارات إدارة السمعة (Reputation)
// ============================================
$router->get('/api/reputation/overview-data', 'ReputationController', 'getOverviewData', ['AuthMiddleware']);
$router->post('/api/reputation/review/{id}/dismiss', 'ReputationController', 'dismissReply', ['AuthMiddleware']);
$router->get('/api/reputation/reviews', 'ReputationController', 'getReviews', ['AuthMiddleware']);

$router->post('/api/revenue/records', 'RevenueController', 'createRecord', ['AuthMiddleware']);
$router->get('/api/revenue/kpis', 'RevenueController', 'getKpis', ['AuthMiddleware']);

// ============================================
// TOURFECTO AI REVENUE INTELLIGENCE (module, additive only)
// ============================================
$router->get('/api/revenue-intelligence/overview', 'RevenueIntelligenceController', 'apiOverview', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/sources', 'RevenueIntelligenceController', 'apiSources', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/products', 'RevenueIntelligenceController', 'apiProducts', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/forecast', 'RevenueIntelligenceController', 'apiForecast', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/forecast/accuracy', 'RevenueIntelligenceController', 'apiForecastAccuracy', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/opportunities', 'RevenueIntelligenceController', 'apiOpportunities', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/risks', 'RevenueIntelligenceController', 'apiRisks', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/anomalies', 'RevenueIntelligenceController', 'apiAnomalies', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/customers', 'RevenueIntelligenceController', 'apiCustomers', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/segments', 'RevenueIntelligenceController', 'apiSegments', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/pipeline', 'RevenueIntelligenceController', 'apiPipeline', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/actions', 'RevenueIntelligenceController', 'apiActions', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/executive-summary', 'RevenueIntelligenceController', 'apiExecutiveSummary', ['AuthMiddleware']);
$router->post('/api/revenue-intelligence/assistant/ask', 'RevenueIntelligenceController', 'apiAssistantAsk', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/retention', 'RevenueIntelligenceController', 'apiRetention', ['AuthMiddleware']);
$router->get('/api/revenue-intelligence/reports/export', 'RevenueIntelligenceController', 'apiExportReport', ['AuthMiddleware']);

$router->get('/api/website-optimizer/websites', 'WebsiteOptimizerController', 'listWebsites', ['AuthMiddleware']);
$router->post('/api/website-optimizer/audit', 'WebsiteOptimizerController', 'runAudit', ['AuthMiddleware']);
$router->get('/api/website-optimizer/history', 'WebsiteOptimizerController', 'history', ['AuthMiddleware']);
$router->get('/api/website-optimizer/fixes', 'WebsiteOptimizerController', 'listFixes', ['AuthMiddleware']);
$router->post('/api/website-optimizer/fixes/{id}/status', 'WebsiteOptimizerController', 'updateFixStatus', ['AuthMiddleware']);

$router->get('/api/competitor-monitoring/detail', 'CompetitorMonitoringController', 'getDetail', ['AuthMiddleware']);
$router->post('/api/competitor-monitoring/pricing', 'CompetitorMonitoringController', 'addPricing', ['AuthMiddleware']);
$router->post('/api/competitor-monitoring/offers', 'CompetitorMonitoringController', 'addOffer', ['AuthMiddleware']);
$router->get('/api/competitor-monitoring/alerts', 'CompetitorMonitoringController', 'getAlerts', ['AuthMiddleware']);
$router->get('/api/competitor-monitoring/summary', 'CompetitorMonitoringController', 'getSummary', ['AuthMiddleware']);

// ============================================
// Competitor Intelligence (موديول موحّد جديد)
// ============================================
$router->get('/api/competitor-intelligence/dashboard', 'CompetitorIntelligenceController', 'apiDashboard', ['AuthMiddleware']);

$router->get('/api/competitor-intelligence/competitors', 'CompetitorIntelligenceController', 'apiListCompetitors', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/competitors', 'CompetitorIntelligenceController', 'apiAddCompetitor', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/competitors/bulk-import', 'CompetitorIntelligenceController', 'apiBulkImportCompetitors', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/competitors/{id}', 'CompetitorIntelligenceController', 'apiCompetitorProfile', ['AuthMiddleware']);
$router->put('/api/competitor-intelligence/competitors/{id}', 'CompetitorIntelligenceController', 'apiUpdateCompetitor', ['AuthMiddleware']);
$router->delete('/api/competitor-intelligence/competitors/{id}', 'CompetitorIntelligenceController', 'apiDeleteCompetitor', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/competitors/{id}/check-now', 'CompetitorIntelligenceController', 'apiCheckNow', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/competitors/{id}/timeline', 'CompetitorIntelligenceController', 'apiTimeline', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/competitors/{id}/price-history', 'CompetitorIntelligenceController', 'apiPriceHistory', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/competitors/{id}/scan-insights', 'CompetitorIntelligenceController', 'apiScanInsights', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/competitors/{id}/analyze-profile', 'CompetitorIntelligenceController', 'apiAnalyzeProfile', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/competitors/{id}/compute-scorecard', 'CompetitorIntelligenceController', 'apiComputeScorecard', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/competitors/{id}/scorecard-trend', 'CompetitorIntelligenceController', 'apiScorecardTrend', ['AuthMiddleware']);

$router->post('/api/competitor-intelligence/discovery/suggest', 'CompetitorIntelligenceController', 'apiDiscoverySuggest', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/discovery/run', 'CompetitorIntelligenceController', 'apiDiscoveryRun', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/discovery', 'CompetitorIntelligenceController', 'apiDiscoveryList', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/discovery/{id}/approve', 'CompetitorIntelligenceController', 'apiDiscoveryApprove', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/discovery/{id}/dismiss', 'CompetitorIntelligenceController', 'apiDiscoveryDismiss', ['AuthMiddleware']);

$router->get('/api/competitor-intelligence/watchlist', 'CompetitorIntelligenceController', 'apiWatchlist', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/watchlist', 'CompetitorIntelligenceController', 'apiWatchlistUpsert', ['AuthMiddleware']);
$router->delete('/api/competitor-intelligence/watchlist/{id}', 'CompetitorIntelligenceController', 'apiWatchlistRemove', ['AuthMiddleware']);

$router->get('/api/competitor-intelligence/activity', 'CompetitorIntelligenceController', 'apiActivityFeed', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/comparison', 'CompetitorIntelligenceController', 'apiComparison', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/comparison/export', 'CompetitorIntelligenceController', 'apiComparisonExport', ['AuthMiddleware']);

$router->get('/api/competitor-intelligence/alerts', 'CompetitorIntelligenceController', 'apiAlerts', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/alerts/{id}/read', 'CompetitorIntelligenceController', 'apiMarkAlertRead', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/alerts/read-all', 'CompetitorIntelligenceController', 'apiMarkAllAlertsRead', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/alerts/unread-count', 'CompetitorIntelligenceController', 'apiUnreadAlertsCount', ['AuthMiddleware']);

$router->get('/api/competitor-intelligence/insights', 'CompetitorIntelligenceController', 'apiInsights', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/insights/{id}/status', 'CompetitorIntelligenceController', 'apiInsightStatus', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/ai/ask', 'CompetitorIntelligenceController', 'apiAiAsk', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/ai/weekly-summary', 'CompetitorIntelligenceController', 'apiAiWeeklySummary', ['AuthMiddleware']);

$router->get('/api/competitor-intelligence/reports', 'CompetitorIntelligenceController', 'apiListReports', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/reports', 'CompetitorIntelligenceController', 'apiGenerateReport', ['AuthMiddleware']);
$router->get('/api/competitor-intelligence/reports/{id}', 'CompetitorIntelligenceController', 'apiGetReport', ['AuthMiddleware']);

$router->get('/api/competitor-intelligence/settings', 'CompetitorIntelligenceController', 'apiGetSettings', ['AuthMiddleware']);
$router->put('/api/competitor-intelligence/settings', 'CompetitorIntelligenceController', 'apiUpdateSettings', ['AuthMiddleware']);
$router->post('/api/competitor-intelligence/settings/pause-all', 'CompetitorIntelligenceController', 'apiPauseAllMonitoring', ['AuthMiddleware']);

$router->get('/api/executive/extras', 'ExecutiveExtrasController', 'getExtras', ['AuthMiddleware']);
$router->post('/api/executive/notes', 'ExecutiveExtrasController', 'addNote', ['AuthMiddleware']);
$router->post('/api/executive/tasks/{id}/complete', 'ExecutiveExtrasController', 'completeTask', ['AuthMiddleware']);
$router->post('/api/executive/alerts/{id}/read', 'ExecutiveExtrasController', 'markAlertRead', ['AuthMiddleware']);
$router->get('/api/reputation/review/{id}', 'ReputationController', 'getReview', ['AuthMiddleware']);
$router->get('/api/reputation/stats', 'ReputationController', 'getStats', ['AuthMiddleware']);
$router->post('/api/reputation/review/{id}/reply', 'ReputationController', 'sendReply', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_review_credits'
]);
$router->post('/api/reputation/review/{id}/generate-reply', 'ReputationController', 'generateReply', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_ai_credits'
]);
$router->put('/api/reputation/review/{id}/reply', 'ReputationController', 'updateReply', ['AuthMiddleware']);
$router->delete('/api/reputation/review/{id}', 'ReputationController', 'deleteReview', ['AuthMiddleware']);

// منصات المراجعات
$router->post('/api/reputation/connect/tripadvisor', 'ReputationController', 'finalizeTripAdvisorConnection', ['AuthMiddleware']);
$router->get('/api/reputation/tripadvisor/search', 'ReputationController', 'searchTripAdvisor', ['AuthMiddleware']);
$router->post('/api/reputation/disconnect/tripadvisor/{website_id}', 'ReputationController', 'disconnectTripAdvisor', ['AuthMiddleware']);
// ملحوظة: ربط Google Business بقى GET redirect حقيقي تحت /reputation/connect/google/{website_id}
// (شوف web.php) مش POST API - محتاج يفتح نافذة موافقة Google، مش نداء AJAX.
$router->post('/api/reputation/connect/google/finalize', 'ReputationController', 'finalizeGoogleConnection', ['AuthMiddleware']);
$router->post('/api/reputation/disconnect/google/{website_id}', 'ReputationController', 'disconnectGoogleBusiness', ['AuthMiddleware']);
$router->get('/api/reputation/platforms', 'ReputationController', 'getPlatforms', ['AuthMiddleware']);

// Google Search Console (نفس منطق ربط Google Business: GET redirect حقيقي
// تحت /search-console/connect/{website_id} - شوف web.php - مش POST API)
$router->post('/api/search-console/finalize', 'SearchConsoleController', 'finalize', ['AuthMiddleware']);
$router->post('/api/search-console/disconnect/{website_id}', 'SearchConsoleController', 'disconnect', ['AuthMiddleware']);
$router->get('/api/search-console/stats/{website_id}', 'SearchConsoleController', 'stats', ['AuthMiddleware']);

// تصحيح: نفس المشكلة - webhook() كان method حقيقي شغال في ReputationController
// من غير ما يتسجل في أي route. ملحوظة مهمة: ده مش webhook رسمي من
// TripAdvisor/Google (المنصتين دول مبيقدموش webhooks عامة لأي طرف تالت
// أصلاً) - ده endpoint مخصص محتاج حد (أنت، أو أداة وسيطة زي Zapier/Make)
// يبعتله بيانات المراجعة يدويًا بصيغة معينة + secret. مش ربط تلقائي حقيقي
// بالمنصات لسه.
$router->post('/api/reputation/webhook', 'ReputationController', 'webhook');

// ============================================
// مسارات الشات (Chat)
// ============================================
$router->get('/api/chat/messages', 'ChatController', 'getMessages', ['AuthMiddleware']);
$router->get('/api/chat/conversation/{session_id}', 'ChatController', 'getConversation', ['AuthMiddleware']);
$router->get('/api/chat/pending', 'ChatController', 'getPendingApprovals', ['AuthMiddleware']);
$router->post('/api/chat/approve', 'ChatController', 'approveReply', ['AuthMiddleware']);
$router->post('/api/chat/send', 'ChatController', 'sendMessage', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_chat_credits'
]);
$router->post('/api/chat/generate-reply', 'ChatController', 'generateReply', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_ai_credits'
]);
// ملحوظة: /api/chat/stats كانت مسجّلة قبل كده لدالة getStats غير موجودة
// أصلاً في ChatController ومفيش أي زرار بينده عليها - اتشالت عشان متسببش
// خطأ فادح لو حد فتحها. لو محتاجينها فعليًا (كارت إحصائيات شات مثلاً)، تقدر تتبنى بسهولة.
$router->get('/api/chat/settings', 'ChatController', 'getSettings', ['AuthMiddleware']);
$router->put('/api/chat/settings', 'ChatController', 'updateSettings', ['AuthMiddleware']);
$router->delete('/api/chat/message/{id}', 'ChatController', 'deleteMessage', ['AuthMiddleware']);

// تصحيح: هذين المسارين كانا موجودين كـ methods حقيقية وشغالة في
// ChatController (webhook/verifyWebhook) من غير ما يتسجلوا في أي مكان
// في الراوتر أبدًا. النتيجة: مفيش أي طريقة كانت متاحة فعليًا لاستقبال
// رسائل واتساب واردة من Meta WhatsApp Cloud API - أي رسالة عميل ترسلها
// كانت هتضيع (404). من غير AuthMiddleware لأن Meta بتتحقق بتوكن verify
// خاص (WHATSAPP_WEBHOOK_VERIFY_TOKEN) مش بجلسة مستخدم.
$router->get('/api/chat/webhook', 'ChatController', 'verifyWebhook');
$router->post('/api/chat/webhook', 'ChatController', 'webhook');

// ربط UltraMsg (واتساب) لكل عميل بحسابه الخاص
$router->get('/api/chat/ultramsg/status', 'ChatController', 'getUltraMsgStatus', ['AuthMiddleware']);
$router->post('/api/chat/connect/ultramsg', 'ChatController', 'connectUltraMsg', ['AuthMiddleware']);
$router->post('/api/chat/disconnect/ultramsg/{website_id}', 'ChatController', 'disconnectUltraMsg', ['AuthMiddleware']);
// من غير AuthMiddleware - ده بيتنده من سيرفرات UltraMsg نفسها، مش من متصفح
// مستخدم عندنا. الحماية عن طريق secret موقّع لكل موقع (شوف ultraMsgWebhookSecret).
$router->post('/api/chat/webhook/ultramsg/{website_id}', 'ChatController', 'ultraMsgWebhook');

// ============================================
// منصات الحجز والوساطة السياحية - OTA
// (GetYourGuide, Viator) - كل عميل بيربط حساب الـ Partner بتاعه هو
// ============================================
$router->get('/api/ota/status', 'OTAController', 'getStatus', ['AuthMiddleware']);
$router->post('/api/ota/connect', 'OTAController', 'connect', ['AuthMiddleware']);
$router->post('/api/ota/disconnect/{platform}/{website_id}', 'OTAController', 'disconnect', ['AuthMiddleware']);

// ============================================
// مسارات المواقع الإلكترونية (Websites)
// ============================================
$router->get('/api/websites', 'WebsiteController', 'index', ['AuthMiddleware']);
$router->post('/api/websites', 'WebsiteController', 'store', ['AuthMiddleware']);
$router->get('/api/websites/{id}', 'WebsiteController', 'show', ['AuthMiddleware']);
$router->put('/api/websites/{id}', 'WebsiteController', 'update', ['AuthMiddleware']);
$router->delete('/api/websites/{id}', 'WebsiteController', 'destroy', ['AuthMiddleware']);
$router->post('/api/websites/{id}/verify', 'WebsiteController', 'verify', ['AuthMiddleware']);
$router->post('/api/websites/{id}/competitors', 'WebsiteController', 'updateCompetitors', ['AuthMiddleware']);

// ============================================
// مسارات الاشتراكات (Subscription)
// ============================================
$router->post('/api/subscription/validate', 'SubscriptionController', 'validateSubscriptionStatus', ['AuthMiddleware']);
$router->get('/api/subscription/current', 'SubscriptionController', 'current', ['AuthMiddleware']);
$router->post('/api/subscription/create', 'SubscriptionController', 'create', ['AuthMiddleware']);
$router->post('/api/subscription/renew', 'SubscriptionController', 'renew', ['AuthMiddleware']);
$router->post('/api/subscription/cancel', 'SubscriptionController', 'cancel', ['AuthMiddleware']);
$router->get('/api/wallet/balance', 'WalletController', 'getBalance', ['AuthMiddleware']);
$router->post('/api/wallet/redeem-card', 'WalletController', 'redeemCard', ['AuthMiddleware']);
$router->get('/api/wallet/history', 'WalletController', 'getHistory', ['AuthMiddleware']);
$router->post('/api/wallet/deposit', 'WalletController', 'requestDeposit', ['AuthMiddleware']);
$router->post('/api/wallet/subscribe', 'WalletController', 'subscribeWithBalance', ['AuthMiddleware']);
$router->post('/api/subscription/upgrade', 'SubscriptionController', 'upgrade', ['AuthMiddleware']);
$router->get('/api/subscription/plans', 'SubscriptionController', 'getPlans');
$router->get('/api/subscription/invoices', 'SubscriptionController', 'getInvoices', ['AuthMiddleware']);
$router->get('/api/subscription/invoice/{id}', 'SubscriptionController', 'getInvoice', ['AuthMiddleware']);
$router->post('/api/subscription/payment', 'SubscriptionController', 'processPayment', ['AuthMiddleware']);
$router->get('/api/subscription/billing-profile', 'SubscriptionController', 'getBillingProfile', ['AuthMiddleware']);
$router->put('/api/subscription/billing-profile', 'SubscriptionController', 'updateBillingProfile', ['AuthMiddleware']);

// ============================================
// مسارات لوحة التحكم (Dashboard)
// ============================================
$router->get('/api/dashboard/stats', 'DashboardController', 'getStats', ['AuthMiddleware']);
$router->get('/api/dashboard/smart-insights', 'DashboardController', 'smartInsights', ['AuthMiddleware']);
$router->get('/api/dashboard/chart/reviews', 'DashboardController', 'getReviewChart', ['AuthMiddleware']);
$router->get('/api/dashboard/chart/chat', 'DashboardController', 'getChatChart', ['AuthMiddleware']);
$router->get('/api/dashboard/chart/api', 'DashboardController', 'getApiChart', ['AuthMiddleware']);
$router->get('/api/dashboard/chart/ai', 'DashboardController', 'getAIChart', ['AuthMiddleware']);
$router->get('/api/dashboard/activity', 'DashboardController', 'getRecentActivity', ['AuthMiddleware']);
$router->get('/api/dashboard/notifications', 'DashboardController', 'getNotifications', ['AuthMiddleware']);
$router->post('/api/dashboard/notifications/{id}/read', 'DashboardController', 'markNotificationRead', ['AuthMiddleware']);
$router->get('/api/dashboard/login-history', 'DashboardController', 'getLoginHistory', ['AuthMiddleware']);

// ============================================
// مسارات إدارية (Admin)
// ============================================
$router->group('/api/admin', function ($router) {
    // Phase 4 - AI Usage & Cost Tracking
    $router->get('/ai-usage-stats', 'AdminController', 'aiUsageStats', ['AuthMiddleware', 'AdminMiddleware']);
    // Profile Center Phase 5 - مسار طوارئ لإلغاء 2FA لمستخدم فقد جهازه
    $router->post('/users/{id}/reset-2fa', 'AdminController', 'resetUserTwoFactor', ['AuthMiddleware', 'AdminMiddleware']);
    // المستخدمين
    $router->get('/users', 'AdminController', 'getUsers', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/users/{id}', 'AdminController', 'getUserById', ['AuthMiddleware', 'AdminMiddleware']);
    $router->put('/users/{id}', 'AdminController', 'updateUser', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/broadcast', 'AdminController', 'broadcast', ['AuthMiddleware', 'AdminMiddleware']);
    $router->put('/branding', 'AdminController', 'updateBranding', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/branding/logo', 'AdminController', 'uploadLogo', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/branding/favicon', 'AdminController', 'uploadFavicon', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/new-features-stats', 'AdminController', 'newFeaturesStats', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/features', 'AdminController', 'listFeatures', ['AuthMiddleware', 'AdminMiddleware']);
    $router->put('/features/{key}', 'AdminController', 'updateFeature', ['AuthMiddleware', 'AdminMiddleware']);
    $router->put('/legal-content', 'AdminController', 'updateLegalContent', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/system-settings', 'AdminController', 'getSystemSettings', ['AuthMiddleware', 'AdminMiddleware']);
    $router->put('/system-settings', 'AdminController', 'updateSystemSettings', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/faq', 'AdminController', 'listFaqAdmin', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/faq', 'AdminController', 'createFaq', ['AuthMiddleware', 'AdminMiddleware']);
    $router->put('/faq/{id}', 'AdminController', 'updateFaq', ['AuthMiddleware', 'AdminMiddleware']);
    $router->delete('/faq/{id}', 'AdminController', 'deleteFaq', ['AuthMiddleware', 'AdminMiddleware']);
    $router->delete('/users/{id}', 'AdminController', 'deleteUser', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/users/{id}/suspend', 'AdminController', 'suspendUser', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/users/{id}/activate', 'AdminController', 'activateUser', ['AuthMiddleware', 'AdminMiddleware']);

    // الاشتراكات
    $router->get('/subscriptions', 'AdminController', 'getSubscriptions', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/subscriptions/{id}', 'AdminController', 'getSubscription', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/subscriptions/{id}/cancel', 'AdminController', 'cancelSubscription', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/subscriptions/run-lifecycle-checks', 'AdminController', 'runSubscriptionLifecycleChecks', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->post('/invoices/run-lifecycle-checks', 'AdminController', 'runInvoiceLifecycleChecks', ['AuthMiddleware', 'BillingAdminMiddleware']);

    // رسائل التواصل
    $router->get('/contact-messages', 'AdminController', 'getContactMessages', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/contact-messages/{id}/read', 'AdminController', 'markContactMessageRead', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/plans', 'AdminController', 'listPlansAdmin', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/wallet/pending', 'WalletController', 'listPendingDeposits', ['AuthMiddleware', 'BillingViewerMiddleware']);
    $router->post('/users/{id}/add-balance', 'WalletController', 'adminAddBalance', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->post('/wallet/cards/generate', 'WalletController', 'generateCards', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->get('/wallet/cards', 'WalletController', 'listCards', ['AuthMiddleware', 'BillingViewerMiddleware']);
    $router->get('/wallet/stats', 'WalletController', 'getAdminStats', ['AuthMiddleware', 'BillingViewerMiddleware']);
    $router->get('/wallet/mrr-trend', 'WalletController', 'getMrrTrend', ['AuthMiddleware', 'BillingViewerMiddleware']);
    $router->get('/wallet/usage-revenue', 'WalletController', 'getUsageRevenueBreakdown', ['AuthMiddleware', 'BillingViewerMiddleware']);
    $router->post('/wallet/{id}/approve', 'WalletController', 'approveDeposit', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->post('/wallet/{id}/reject', 'WalletController', 'rejectDeposit', ['AuthMiddleware', 'BillingAdminMiddleware']);
    // getPaymentSettingsAdmin بيكشف تفاصيل IBAN/PayPal الحقيقية اللي
    // المنصة بتستقبل فيها الفلوس - فسيبناه Billing Admin (manager+) بس،
    // مش متاح حتى لدور "اطّلاع فقط" (agent).
    $router->get('/wallet/settings', 'WalletController', 'getPaymentSettingsAdmin', ['AuthMiddleware', 'BillingAdminMiddleware']);
    // تصحيح (2026-08-09 / Phase 7): ده أخطر إجراء في القسم ده - بيغيّر
    // IBAN/PayPal اللي بتستلم فيها المنصة الفلوس فعليًا - فسيبناه Admin
    // كامل بس (مش Billing Admin) عن قصد، حتى لو باقي عمليات الفوترة
    // بقت متاحة لـ manager.
    $router->put('/wallet/settings', 'WalletController', 'updatePaymentSettingsAdmin', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/wallet/usage-pricing', 'WalletController', 'listUsagePricingAdmin', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->put('/wallet/usage-pricing/{id}', 'WalletController', 'updateUsagePricingAdmin', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->get('/refunds', 'WalletController', 'listRefunds', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->post('/refunds', 'WalletController', 'createRefund', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->get('/tax-rules', 'WalletController', 'listTaxRules', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->post('/tax-rules', 'WalletController', 'upsertTaxRule', ['AuthMiddleware', 'BillingAdminMiddleware']);
    $router->put('/plans/{id}', 'AdminController', 'updatePlan', ['AuthMiddleware', 'AdminMiddleware']);

    // النظام
    $router->get('/system/health', 'AdminController', 'systemHealth', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/system/logs', 'AdminController', 'getLogs', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/system/cache/clear', 'AdminController', 'clearCache', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/system/stats', 'AdminController', 'getSystemStats', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/platform-overview', 'AdminController', 'getPlatformOverview', ['AuthMiddleware', 'AdminMiddleware']);

    // سجل تسجيل الدخول (Login History)
    $router->get('/login-history', 'AdminController', 'getAllLoginHistory', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/users/{id}/login-history', 'AdminController', 'getUserLoginHistory', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/users/{id}/feature-overrides', 'AdminController', 'addUserFeatureOverride', ['AuthMiddleware', 'AdminMiddleware']);
    $router->delete('/users/{id}/feature-overrides/{key}', 'AdminController', 'removeUserFeatureOverride', ['AuthMiddleware', 'AdminMiddleware']);

    // انتحال حساب العميل (Impersonate)
    $router->post('/users/{id}/impersonate', 'AdminController', 'impersonateUser', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/impersonate/stop', 'AdminController', 'stopImpersonation', ['AuthMiddleware']);

    // تتبع الزوار (Visitor Analytics)
    $router->get('/visitors/stats', 'AdminController', 'visitorStats', ['AuthMiddleware', 'AdminMiddleware']);
    $router->get('/visitors/log', 'AdminController', 'visitorLog', ['AuthMiddleware', 'AdminMiddleware']);

    // مفاتيح API الخاصة بالشركاء الخارجيين (Partner API Keys)
    // جزء من المرحلة 2 من خطة API Gateway - إدارة كاملة من لوحة الأدمن
    $router->get('/partner-keys', 'PartnerKeyController', 'list', ['AuthMiddleware', 'AdminMiddleware']);
    $router->post('/partner-keys', 'PartnerKeyController', 'create', ['AuthMiddleware', 'AdminMiddleware']);
    $router->delete('/partner-keys/{id}', 'PartnerKeyController', 'revoke', ['AuthMiddleware', 'AdminMiddleware']);
}, ['AuthMiddleware', 'AdminMiddleware']);

// ============================================
// Partner API - نقاط وصول للشركاء الخارجيين
// مصادقة مستقلة تمامًا عن المستخدم العادي: X-API-Key header بدل
// Session/Bearer token، وصلاحيات (scopes) محدودة لكل مفتاح.
// راجع PartnerAuthMiddleware للتفاصيل.
// ============================================
$router->get('/api/partner/ping', 'PartnerController', 'ping', ['PartnerAuthMiddleware']);
$router->get(
    '/api/partner/websites/{website_id}/reputation-summary',
    'PartnerController',
    'reputationSummary',
    ['PartnerAuthMiddleware:reputation:read']
);

// ============================================
// مسارات التقارير (Reports)
// ============================================
$router->get('/api/reports/export', 'ReportController', 'export', ['AuthMiddleware']);
$router->get('/api/reports/scheduled', 'ReportController', 'getScheduled', ['AuthMiddleware']);
$router->post('/api/reports/schedule', 'ReportController', 'schedule', ['AuthMiddleware']);
$router->delete('/api/reports/schedule/{id}', 'ReportController', 'deleteSchedule', ['AuthMiddleware']);

// ============================================
// مسارات إعدادات المستخدم (Settings)
// ============================================
$router->get('/api/settings/general', 'SettingsController', 'getGeneral', ['AuthMiddleware']);
$router->put('/api/settings/general', 'SettingsController', 'updateGeneral', ['AuthMiddleware']);
$router->get('/api/settings/notifications', 'SettingsController', 'getNotifications', ['AuthMiddleware']);
$router->put('/api/settings/notifications', 'SettingsController', 'updateNotifications', ['AuthMiddleware']);
$router->get('/api/settings/api', 'SettingsController', 'getAPI', ['AuthMiddleware']);
$router->post('/api/settings/api/regenerate', 'SettingsController', 'regenerateAPIKey', ['AuthMiddleware']);
$router->get('/api/settings/languages', 'SettingsController', 'getLanguages');
$router->get('/api/settings/timezones', 'SettingsController', 'getTimezones');

// ============================================
// مسارات صحية (Health)
// ============================================
$router->get('/api/health', 'HealthController', 'check');
$router->get('/api/health/detailed', 'HealthController', 'detailed', ['AuthMiddleware']);
$router->get('/api/ping', 'HealthController', 'ping');

// ============================================
// مسارات التوثيق (Documentation)
// ============================================
$router->get('/api/docs', 'DocumentationController', 'index');
// ملحوظة: /api/docs/openapi.json كانت مسجّلة قبل كده لدالة openapi غير
// موجودة أصلاً في DocumentationController - اتشالت عشان متسببش خطأ فادح.
// لو محتاجينها فعليًا (مواصفة OpenAPI JSON للمطورين)، تقدر تتبنى بسهولة
// بنفس منطق تجميع الـ routes الموجود بالفعل في DocumentationController::api().

// ============================================
// دمج الموديولات (2026-07-14)
// ============================================

// السوشيال ميديا
$router->get('/api/social/connections', 'SocialMediaController', 'listConnections', ['AuthMiddleware']);
$router->get('/api/social/posts', 'SocialMediaController', 'listPosts', ['AuthMiddleware']);
$router->post('/api/social/posts', 'SocialMediaController', 'createPost', ['AuthMiddleware']);
$router->post('/api/social/generate-caption', 'SocialMediaController', 'generateCaption', ['AuthMiddleware']);
$router->get('/api/social/calendar', 'SocialMediaController', 'getCalendar', ['AuthMiddleware']);

// Creative Studio
$router->get('/api/creative-studio/media', 'CreativeStudioController', 'listMedia', ['AuthMiddleware']);
$router->post('/api/creative-studio/media', 'CreativeStudioController', 'requestMedia', ['AuthMiddleware']);
$router->get('/api/creative-studio/video-scripts', 'CreativeStudioController', 'listVideoScripts', ['AuthMiddleware']);
$router->post('/api/creative-studio/video-scripts', 'CreativeStudioController', 'requestVideoScript', ['AuthMiddleware']);
$router->post('/api/creative-studio/video', 'CreativeStudioController', 'requestVideo', ['AuthMiddleware']);
$router->post('/api/creative-studio/enhance-prompt', 'CreativeStudioController', 'enhancePrompt', ['AuthMiddleware']);

// مساعد التسويق الذكي
$router->post('/api/marketing-assistant/run', 'MarketingAssistantController', 'run', ['AuthMiddleware']);
$router->get('/api/marketing-assistant/history', 'MarketingAssistantController', 'history', ['AuthMiddleware']);

// White-Label
$router->get('/api/agency/list', 'AgencyController', 'list', ['AuthMiddleware']);
$router->post('/api/agency/create', 'AgencyController', 'create', ['AuthMiddleware']);
$router->get('/api/agency/{id}/clients', 'AgencyController', 'listClients', ['AuthMiddleware']);
$router->post('/api/agency/{id}/clients', 'AgencyController', 'addClient', ['AuthMiddleware']);
$router->delete('/api/agency/{id}/clients/{clientId}', 'AgencyController', 'removeClient', ['AuthMiddleware']);

// إدارة الإعلانات
$router->get('/api/ads/campaigns', 'AdsController', 'list', ['AuthMiddleware']);
$router->get('/api/ads/campaigns/search', 'AdsController', 'searchCampaigns', ['AuthMiddleware']);
$router->get('/api/ads/campaigns/{id}', 'AdsController', 'getCampaign', ['AuthMiddleware']);
$router->post('/api/ads/campaigns', 'AdsController', 'create', ['AuthMiddleware']);
$router->post('/api/ads/campaigns/ai-generate', 'AdsController', 'aiGenerateCampaign', ['AuthMiddleware']);
$router->get('/api/ads/campaigns/{id}/copies', 'AdsController', 'listCopies', ['AuthMiddleware']);
$router->post('/api/ads/campaigns/{id}/generate-copies', 'AdsController', 'generateCopies', ['AuthMiddleware']);
$router->patch('/api/ads/copies/{id}/approve', 'AdsController', 'approveCopy', ['AuthMiddleware']);
$router->patch('/api/ads/copies/{id}/reject', 'AdsController', 'rejectCopy', ['AuthMiddleware']);
$router->get('/api/ads/meta/status', 'AdsController', 'getMetaConnectionStatus', ['AuthMiddleware']);
$router->post('/api/ads/meta/choose-account', 'AdsController', 'chooseMetaAdAccount', ['AuthMiddleware']);
$router->post('/api/ads/meta/sync', 'AdsController', 'syncMetaCampaigns', ['AuthMiddleware']);
$router->post('/api/ads/meta/disconnect', 'AdsController', 'disconnectMeta', ['AuthMiddleware']);

$router->get('/api/ads/google/status', 'AdsController', 'getGoogleAdsConnectionStatus', ['AuthMiddleware']);
$router->get('/api/ads/connections/status', 'AdsController', 'getConnectionsStatus', ['AuthMiddleware']);
$router->post('/api/ads/google/choose-account', 'AdsController', 'chooseGoogleAdsAccount', ['AuthMiddleware']);
$router->post('/api/ads/google/sync', 'AdsController', 'syncGoogleAdsCampaigns', ['AuthMiddleware']);
$router->post('/api/ads/google/disconnect', 'AdsController', 'disconnectGoogleAds', ['AuthMiddleware']);

$router->get('/api/ads/autopilot/settings', 'AdsController', 'getAutopilotSettings', ['AuthMiddleware']);
$router->post('/api/ads/autopilot/settings', 'AdsController', 'saveAutopilotSettings', ['AuthMiddleware']);
$router->get('/api/ads/autopilot/pending', 'AdsController', 'listPendingActions', ['AuthMiddleware']);
$router->post('/api/ads/autopilot/pending/{id}/approve', 'AdsController', 'approvePendingAction', ['AuthMiddleware']);
$router->post('/api/ads/autopilot/pending/{id}/reject', 'AdsController', 'rejectPendingAction', ['AuthMiddleware']);
$router->get('/api/ads/autopilot/logs', 'AdsController', 'listOptimizationLogs', ['AuthMiddleware']);
$router->post('/api/ads/autopilot/logs/{id}/rollback', 'AdsController', 'rollbackOptimizationLog', ['AuthMiddleware']);
$router->post('/api/ads/autopilot/run', 'AdsController', 'runAutopilotNow', ['AuthMiddleware']);

$router->post('/api/ads/copilot/ask', 'AdsController', 'askCopilot', ['AuthMiddleware']);

$router->post('/api/ads/campaigns/{id}/keywords/generate', 'AdsController', 'generateKeywords', ['AuthMiddleware']);
$router->get('/api/ads/campaigns/{id}/keywords', 'AdsController', 'listKeywords', ['AuthMiddleware']);
$router->post('/api/ads/keywords/{id}/assign-group', 'AdsController', 'assignKeywordToGroup', ['AuthMiddleware']);

$router->post('/api/ads/campaigns/{id}/ad-groups', 'AdsController', 'createAdGroup', ['AuthMiddleware']);
$router->get('/api/ads/campaigns/{id}/ad-groups', 'AdsController', 'listAdGroups', ['AuthMiddleware']);
$router->post('/api/ads/ad-groups/{id}/status', 'AdsController', 'updateAdGroupStatus', ['AuthMiddleware']);
$router->delete('/api/ads/ad-groups/{id}', 'AdsController', 'deleteAdGroup', ['AuthMiddleware']);

$router->post('/api/ads/market-research', 'AdsController', 'marketResearch', ['AuthMiddleware']);
$router->get('/api/ads/market-research/history', 'AdsController', 'marketResearchHistory', ['AuthMiddleware']);

$router->post('/api/ads/campaigns/{id}/status', 'AdsController', 'updateCampaignStatus', ['AuthMiddleware']);
$router->post('/api/ads/campaigns/{id}/publish', 'AdsController', 'publishCampaign', ['AuthMiddleware']);
$router->post('/api/ads/campaigns/{id}/toggle-status', 'AdsController', 'toggleCampaignStatus', ['AuthMiddleware']);
$router->post('/api/ads/campaigns/{id}/cancel', 'AdsController', 'cancelCampaign', ['AuthMiddleware']);
$router->post('/api/ads/campaigns/{id}/update-budget', 'AdsController', 'updateCampaignBudget', ['AuthMiddleware']);
$router->delete('/api/ads/campaigns/{id}', 'AdsController', 'deleteCampaign', ['AuthMiddleware']);
$router->post('/api/ads/campaigns/bulk-status', 'AdsController', 'bulkUpdateCampaignStatus', ['AuthMiddleware']);
$router->post('/api/ads/campaigns/{id}/landing-page/analyze', 'AdsController', 'analyzeLandingPage', ['AuthMiddleware']);

$router->post('/api/ads/campaigns/{id}/utm-links', 'AdsController', 'createUtmLink', ['AuthMiddleware']);
$router->get('/api/ads/campaigns/{id}/utm-links', 'AdsController', 'listUtmLinks', ['AuthMiddleware']);

$router->get('/api/ads/dashboard/summary', 'AdsController', 'getDashboardSummary', ['AuthMiddleware']);
$router->get('/api/ads/reports', 'AdsController', 'getReport', ['AuthMiddleware']);
$router->get('/api/ads/reports/trend', 'AdsController', 'getReportTrend', ['AuthMiddleware']);
$router->get('/api/ads/reports/comparison', 'AdsController', 'getCampaignComparison', ['AuthMiddleware']);

$router->post('/api/ads/competitors/{id}/analyze', 'AdsController', 'analyzeAdsCompetitor', ['AuthMiddleware']);
$router->get('/api/ads/competitors/{id}/insights', 'AdsController', 'listAdsCompetitorInsights', ['AuthMiddleware']);
$router->get('/api/ads/competitors', 'AdsController', 'listMyCompetitors', ['AuthMiddleware']);

$router->get('/api/ads/team', 'AdsController', 'listTeamMembers', ['AuthMiddleware']);
$router->post('/api/ads/team', 'AdsController', 'addTeamMember', ['AuthMiddleware']);
$router->post('/api/ads/team/{id}/role', 'AdsController', 'updateTeamMemberRole', ['AuthMiddleware']);
$router->post('/api/ads/team/{id}/remove', 'AdsController', 'removeTeamMember', ['AuthMiddleware']);

// CRM
$router->get('/api/crm/overview', 'CrmController', 'overview', ['AuthMiddleware']);

// ============================================
// دمج الموديولات (2026-07-14 - الدفعة الثانية)
// ============================================

// تتبع المنافسين المستمر (يسدّ فجوة /ai/competitors القديمة الفاضية)
// ملاحظة: /api/ai/competitors [POST] محجوز فعليًا لميزة أخرى غير مفعّلة
// (analyzeCompetitors - يتطلب require_competitor_analysis)، فاستُخدمت
// مسارات مميزة هنا لتفادي أي تعارض معها.
$router->get('/api/ai/competitors', 'AIController', 'listCompetitors', ['AuthMiddleware']);
$router->post('/api/ai/competitors/add', 'AIController', 'createCompetitor', ['AuthMiddleware']);
$router->post('/api/ai/competitors/{id}/analyze', 'AIController', 'analyzeCompetitor', ['AuthMiddleware']);

// الكلمات المفتاحية المتابَعة (يسدّ فجوة /ai/keywords القديمة الفاضية)
// نفس الملاحظة: /api/ai/keywords [POST] محجوز لـ analyzeKeywords الأصلية
$router->get('/api/ai/keywords', 'AIController', 'listKeywords', ['AuthMiddleware']);
$router->post('/api/ai/keywords/add', 'AIController', 'createKeyword', ['AuthMiddleware']);
$router->delete('/api/ai/keywords/{id}', 'AIController', 'deleteKeyword', ['AuthMiddleware']);
$router->post('/api/ai/keywords/bulk-add', 'AIController', 'bulkAddKeywords', ['AuthMiddleware']);
// تحليل SEO/AEO/GEO حقيقي بنفس محرك /api/ai/analyze (يستهلك نفس الرصيد) +
// اكتشاف كلمات جديدة، ومزامنة الترتيب الفعلي من Google Search Console
$router->post('/api/ai/keywords/discover', 'AIController', 'discoverKeywords', ['AuthMiddleware']);
$router->post('/api/ai/keywords/sync-gsc', 'AIController', 'syncSearchConsoleKeywords', ['AuthMiddleware']);

// محتوى Google Business Profile وجدولته
$router->get('/api/gbp/content', 'GoogleBusinessContentController', 'listContent', ['AuthMiddleware']);
$router->post('/api/gbp/content', 'GoogleBusinessContentController', 'generate', [
    'AuthMiddleware',
    // Round 7 (2026-08-14 - Production Finalization): كان بيستهلك AI
    // credits من غير فحص رصيد - نفس نمط باقي endpoints الـ AI في المشروع
    'SubscriptionMiddleware:require_ai_credits'
]);
// GBP Module Upgrade (2026-08-11, Round 6): Posts Edit/Delete/Cancel
$router->put('/api/gbp/content/{id}', 'GoogleBusinessContentController', 'updateContent', ['AuthMiddleware']);
$router->delete('/api/gbp/content/{id}', 'GoogleBusinessContentController', 'deleteContent', ['AuthMiddleware']);
$router->post('/api/gbp/content/{id}/schedule/{scheduleId}/cancel', 'GoogleBusinessContentController', 'cancelSchedule', ['AuthMiddleware']);
$router->post('/api/gbp/content/{id}/schedule', 'GoogleBusinessContentController', 'schedule', ['AuthMiddleware']);
$router->get('/api/gbp/location', 'GoogleBusinessContentController', 'getLocation', ['AuthMiddleware']);
$router->post('/api/gbp/location', 'GoogleBusinessContentController', 'saveLocation', ['AuthMiddleware']);

// GBP Module Upgrade (2026-08-09/10): Setup Wizard/Connection Center/Sync/Profile/Photos/Insights/AI/Attributes
$router->get('/api/gbp/status', 'GbpProfileController', 'status', ['AuthMiddleware']);
$router->get('/api/gbp/health', 'GbpProfileController', 'health', ['AuthMiddleware', 'AdminMiddleware']);
$router->get('/api/gbp/competitors', 'GbpProfileController', 'competitors', ['AuthMiddleware']);
$router->get('/api/gbp/analytics', 'GbpProfileController', 'analytics', ['AuthMiddleware']);
$router->get('/api/gbp/risk-signals', 'GbpProfileController', 'riskSignals', ['AuthMiddleware']);
$router->get('/api/gbp/share-of-voice', 'GbpProfileController', 'shareOfVoice', ['AuthMiddleware']);
$router->get('/api/gbp/reply-rules', 'GbpProfileController', 'listReplyRules', ['AuthMiddleware']);
$router->post('/api/gbp/reply-rules', 'GbpProfileController', 'createReplyRule', ['AuthMiddleware']);
$router->put('/api/gbp/reply-rules/{id}', 'GbpProfileController', 'updateReplyRule', ['AuthMiddleware']);
$router->delete('/api/gbp/reply-rules/{id}', 'GbpProfileController', 'deleteReplyRule', ['AuthMiddleware']);
$router->post('/api/gbp/reply-rules/apply/{review_id}', 'GbpProfileController', 'applyReplyRules', ['AuthMiddleware']);
$router->post('/api/gbp/sync/{website_id}', 'GbpProfileController', 'sync', ['AuthMiddleware']);
$router->get('/api/gbp/profile', 'GbpProfileController', 'getProfile', ['AuthMiddleware']);
$router->post('/api/gbp/profile', 'GbpProfileController', 'updateProfile', ['AuthMiddleware']);
$router->get('/api/gbp/attributes', 'GbpProfileController', 'getAttributes', ['AuthMiddleware']);
$router->post('/api/gbp/attributes', 'GbpProfileController', 'updateAttributes', ['AuthMiddleware']);
$router->get('/api/gbp/photos', 'GbpProfileController', 'listPhotos', ['AuthMiddleware']);
$router->post('/api/gbp/photos', 'GbpProfileController', 'uploadPhoto', ['AuthMiddleware']);
$router->delete('/api/gbp/photos/{id}', 'GbpProfileController', 'deletePhoto', ['AuthMiddleware']);
$router->post('/api/gbp/photos/{id}/primary', 'GbpProfileController', 'setPrimaryPhoto', ['AuthMiddleware']);
$router->get('/api/gbp/insights', 'GbpProfileController', 'insights', ['AuthMiddleware']);
$router->get('/api/gbp/ai-insights', 'GbpProfileController', 'aiInsights', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_ai_credits'
]);
$router->get('/api/gbp/recommendations', 'GbpProfileController', 'recommendations', ['AuthMiddleware']);
$router->get('/api/review-requests', 'ReviewRequestController', 'listRequests', ['AuthMiddleware']);
$router->post('/api/review-requests', 'ReviewRequestController', 'createRequest', ['AuthMiddleware']);
$router->get('/api/review-requests/stats', 'ReviewRequestController', 'getStats', ['AuthMiddleware']);
$router->get('/api/review-requests/analytics', 'ReviewRequestController', 'getAnalytics', ['AuthMiddleware']);
$router->get('/api/review-requests/channel-status', 'ReviewRequestController', 'getChannelStatus', ['AuthMiddleware']);
$router->get('/api/review-requests/destinations', 'ReviewRequestController', 'getDestinations', ['AuthMiddleware']);
$router->get('/api/review-requests/crm-contacts', 'ReviewRequestController', 'getCrmContacts', ['AuthMiddleware']);
$router->get('/api/review-requests/smart-timing', 'ReviewRequestController', 'getSmartTiming', ['AuthMiddleware']);
$router->get('/api/review-requests/templates', 'ReviewRequestController', 'getTemplates', ['AuthMiddleware']);
$router->post('/api/review-requests/templates', 'ReviewRequestController', 'createTemplate', ['AuthMiddleware']);
$router->delete('/api/review-requests/templates/{id}', 'ReviewRequestController', 'deleteTemplate', ['AuthMiddleware']);
$router->get('/api/review-requests/export', 'ReviewRequestController', 'exportCsv', ['AuthMiddleware']);
$router->post('/api/review-requests/ai-assist', 'ReviewRequestController', 'aiAssist', ['AuthMiddleware']);
$router->get('/api/review-requests/settings', 'ReviewRequestController', 'getSettings', ['AuthMiddleware']);
$router->put('/api/review-requests/settings', 'ReviewRequestController', 'saveSettings', ['AuthMiddleware']);
$router->post('/api/review-requests/{id}/opt-out', 'ReviewRequestController', 'optOut', ['AuthMiddleware']);
$router->post('/api/review-requests/{id}/retry', 'ReviewRequestController', 'retryRequest', ['AuthMiddleware']);
$router->put('/api/review-requests/{id}', 'ReviewRequestController', 'updateRequest', ['AuthMiddleware']);
$router->get('/api/review-requests/{id}', 'ReviewRequestController', 'getRequest', ['AuthMiddleware']);
$router->get('/api/ai-assistant/conversations', 'AiAssistantController', 'listConversations', ['AuthMiddleware']);
$router->post('/api/ai-assistant/conversations', 'AiAssistantController', 'createConversation', ['AuthMiddleware']);
$router->get('/api/ai-assistant/conversations/{id}/messages', 'AiAssistantController', 'getMessages', ['AuthMiddleware']);
$router->post('/api/ai-assistant/conversations/{id}/messages', 'AiAssistantController', 'sendMessage', ['AuthMiddleware']);
$router->delete('/api/ai-assistant/conversations/{id}', 'AiAssistantController', 'deleteConversation', ['AuthMiddleware']);
$router->post('/api/ai-assistant/conversations/{id}/regenerate', 'AiAssistantController', 'regenerateMessage', ['AuthMiddleware']);
$router->patch('/api/ai-assistant/conversations/{id}', 'AiAssistantController', 'renameConversation', ['AuthMiddleware']);
$router->get('/api/website-builder/state', 'WebsiteBuilderController', 'getState', ['AuthMiddleware']);
$router->post('/api/website-builder/answer', 'WebsiteBuilderController', 'submitAnswer', ['AuthMiddleware']);
$router->post('/api/website-builder/reset', 'WebsiteBuilderController', 'resetWizard', ['AuthMiddleware']);
$router->post('/api/website-builder/generate', 'WebsiteBuilderController', 'generateSite', ['AuthMiddleware']);
$router->get('/api/website-builder/my-websites', 'WebsiteBuilderController', 'myWebsites', ['AuthMiddleware']);
$router->get('/api/website-builder/{id}', 'WebsiteBuilderController', 'getWebsite', ['AuthMiddleware']);
$router->put('/api/website-builder/{id}', 'WebsiteBuilderController', 'updateWebsite', ['AuthMiddleware']);
$router->post('/api/website-builder/{id}/tours', 'WebsiteBuilderController', 'addTour', ['AuthMiddleware']);
$router->put('/api/website-builder/{id}/tours/{tourSlug}', 'WebsiteBuilderController', 'updateTour', ['AuthMiddleware']);
$router->delete('/api/website-builder/{id}/tours/{tourSlug}', 'WebsiteBuilderController', 'deleteTour', ['AuthMiddleware']);
$router->post('/api/website-builder/{id}/publish', 'WebsiteBuilderController', 'publish', ['AuthMiddleware']);
$router->get('/api/site-dashboard/{id}/overview', 'SiteDashboardController', 'overview', ['AuthMiddleware']);
$router->get('/api/site-dashboard/{id}/templates', 'SiteDashboardController', 'templates', ['AuthMiddleware']);
$router->put('/api/site-dashboard/{id}/design', 'SiteDashboardController', 'updateDesign', ['AuthMiddleware']);
$router->put('/api/site-dashboard/{id}/seo', 'SiteDashboardController', 'updateSeo', ['AuthMiddleware']);
$router->get('/api/site-dashboard/{id}/reviews', 'SiteDashboardController', 'reviews', ['AuthMiddleware']);
$router->put('/api/site-dashboard/{id}/reviews/{reviewId}', 'SiteDashboardController', 'updateReview', ['AuthMiddleware']);
$router->get('/api/site-dashboard/{id}/leads', 'SiteDashboardController', 'leads', ['AuthMiddleware']);
$router->put('/api/site-dashboard/{id}/leads/{leadId}', 'SiteDashboardController', 'updateLead', ['AuthMiddleware']);

// CRM - Leads/Contacts
$router->get('/api/crm/leads', 'CrmController', 'listLeads', ['AuthMiddleware']);
$router->post('/api/crm/leads', 'CrmController', 'createLead', ['AuthMiddleware']);
$router->post('/api/crm/leads/{id}/status', 'CrmController', 'updateLeadStatus', ['AuthMiddleware']);
$router->get('/api/crm/pipeline-stages', 'CrmController', 'listPipelineStages', ['AuthMiddleware']);
$router->get('/api/crm/deals', 'CrmController', 'listDeals', ['AuthMiddleware']);
$router->post('/api/crm/deals', 'CrmController', 'createDeal', ['AuthMiddleware']);
$router->post('/api/crm/deals/{id}/stage', 'CrmController', 'updateDealStage', ['AuthMiddleware']);
$router->get('/api/crm/leads/search', 'CrmApiController', 'searchLeads', ['AuthMiddleware']);
$router->get('/api/crm/leads/export', 'CrmApiController', 'exportLeads', ['AuthMiddleware']);
$router->get('/api/crm/deals/search', 'CrmApiController', 'searchDeals', ['AuthMiddleware']);
$router->get('/api/crm/deals/export', 'CrmApiController', 'exportDeals', ['AuthMiddleware']);

// ============================================================
// موديول AI CRM - نقاط API إضافية (CrmApiController) - بند 41/45
// ============================================================
// Companies
$router->get('/api/crm/companies', 'CrmApiController', 'listCompanies', ['AuthMiddleware']);
$router->get('/api/crm/companies/search', 'CrmApiController', 'searchCompanies', ['AuthMiddleware']);
$router->post('/api/crm/companies', 'CrmApiController', 'createCompany', ['AuthMiddleware']);
$router->put('/api/crm/companies/{id}', 'CrmApiController', 'updateCompany', ['AuthMiddleware']);

// Contacts
$router->get('/api/crm/contacts', 'CrmApiController', 'listContacts', ['AuthMiddleware']);
// إصلاح المرحلة 8: مسارات GET الحرفية (export/search) لازم تُسجَّل قبل
// GET /api/crm/contacts/{id} - الـRouter بيطابق بترتيب التسجيل (أول Pattern
// يطابق يكسب)، وPattern الـ{id} بيطابق أي جزء واحد بعد /contacts/ بما فيها
// "export"/"search" حرفيًا. كانت /api/crm/contacts/export (بند 20 من
// المرحلة 1) فعليًا مسار ميت (Shadowed) من غير ما يُكتشف - تم اكتشافه
// وإصلاحه الآن أثناء إضافة /search.
$router->get('/api/crm/contacts/export', 'CrmApiController', 'exportContacts', ['AuthMiddleware']);
$router->get('/api/crm/contacts/search', 'CrmApiController', 'searchContacts', ['AuthMiddleware']);
$router->get('/api/crm/contacts/{id}', 'CrmApiController', 'getContact', ['AuthMiddleware']);
$router->post('/api/crm/contacts', 'CrmApiController', 'createContact', ['AuthMiddleware']);
$router->put('/api/crm/contacts/{id}', 'CrmApiController', 'updateContact', ['AuthMiddleware']);
$router->get('/api/crm/contacts/{id}/duplicates', 'CrmApiController', 'contactDuplicates', ['AuthMiddleware']);
$router->post('/api/crm/contacts/merge', 'CrmApiController', 'mergeContacts', ['AuthMiddleware']);
$router->get('/api/crm/contacts/{id}/360', 'CrmApiController', 'customer360', ['AuthMiddleware']);

// Leads - عمليات إضافية
$router->post('/api/crm/leads/{id}/assign', 'CrmApiController', 'assignLead', ['AuthMiddleware']);
$router->post('/api/crm/leads/{id}/convert', 'CrmApiController', 'convertLead', ['AuthMiddleware']);
$router->post('/api/crm/leads/{id}/archive', 'CrmApiController', 'archiveLead', ['AuthMiddleware']);

// Deals - عمليات إضافية
$router->put('/api/crm/deals/{id}', 'CrmApiController', 'updateDeal', ['AuthMiddleware']);
$router->delete('/api/crm/deals/{id}', 'CrmApiController', 'deleteDeal', ['AuthMiddleware']);
$router->get('/api/crm/deals/at-risk', 'CrmApiController', 'dealsAtRisk', ['AuthMiddleware']);

// Pipelines متعددة
$router->get('/api/crm/pipelines', 'CrmApiController', 'listPipelines', ['AuthMiddleware']);
$router->post('/api/crm/pipelines', 'CrmApiController', 'createPipeline', ['AuthMiddleware']);
$router->get('/api/crm/pipelines/{id}/stages', 'CrmApiController', 'pipelineStages', ['AuthMiddleware']);
$router->post('/api/crm/pipelines/{id}/stages', 'CrmApiController', 'createPipelineStage', ['AuthMiddleware']);

// Tasks / Follow-ups
$router->get('/api/crm/tasks', 'CrmApiController', 'listTasks', ['AuthMiddleware']);
$router->get('/api/crm/tasks/search', 'CrmApiController', 'searchTasks', ['AuthMiddleware']);
$router->get('/api/crm/tasks/export', 'CrmApiController', 'exportTasks', ['AuthMiddleware']);
$router->get('/api/crm/tasks/overdue', 'CrmApiController', 'overdueTasks', ['AuthMiddleware']);
$router->post('/api/crm/tasks', 'CrmApiController', 'createTask', ['AuthMiddleware']);
$router->post('/api/crm/tasks/{id}/status', 'CrmApiController', 'updateTaskStatus', ['AuthMiddleware']);

// Notes
$router->post('/api/crm/notes', 'CrmApiController', 'createNote', ['AuthMiddleware']);

// Appointments
$router->get('/api/crm/appointments', 'CrmApiController', 'listAppointments', ['AuthMiddleware']);
$router->get('/api/crm/appointments/search', 'CrmApiController', 'searchAppointments', ['AuthMiddleware']);
$router->post('/api/crm/appointments', 'CrmApiController', 'createAppointment', ['AuthMiddleware']);
$router->post('/api/crm/appointments/{id}/status', 'CrmApiController', 'updateAppointmentStatus', ['AuthMiddleware']);

// Dashboard / Reports
$router->get('/api/crm/dashboard/stats', 'CrmApiController', 'dashboardStats', ['AuthMiddleware']);

// Global Search
$router->get('/api/crm/search', 'CrmApiController', 'globalSearch', ['AuthMiddleware']);

// Lead Sources قابلة للتخصيص
$router->get('/api/crm/lead-sources', 'CrmApiController', 'listLeadSources', ['AuthMiddleware']);
$router->post('/api/crm/lead-sources', 'CrmApiController', 'createLeadSource', ['AuthMiddleware']);

// Import / Export
$router->post('/api/crm/contacts/import/preview', 'CrmApiController', 'importPreview', ['AuthMiddleware']);
$router->post('/api/crm/contacts/import/commit', 'CrmApiController', 'importCommit', ['AuthMiddleware']);
$router->post('/api/crm/contacts/import/commit-async', 'CrmApiController', 'importCommitAsync', ['AuthMiddleware']);
$router->get('/api/crm/contacts/import/status/{id}', 'CrmApiController', 'importBatchStatus', ['AuthMiddleware']);

// المرحلة 8: Segments (بند 19) - استكمال لهذه القائمة
$router->get('/api/crm/segments', 'CrmApiController', 'listSegments', ['AuthMiddleware']);
$router->post('/api/crm/segments', 'CrmApiController', 'createSegment', ['AuthMiddleware']);
$router->delete('/api/crm/segments/{id}', 'CrmApiController', 'deleteSegment', ['AuthMiddleware']);
$router->get('/api/crm/segments/{id}/run', 'CrmApiController', 'runSegment', ['AuthMiddleware']);

// ============================================================
// موديول AI CRM - المرحلة 2 (AI Lead Scoring / Next Best Action /
// Forecasting / AI Sales Assistant / AI Summary) - بند 8/9/10/25/27
// ============================================================
$router->post('/api/crm/leads/{id}/score', 'CrmApiController', 'scoreLead', ['AuthMiddleware']);
$router->get('/api/crm/leads/{id}/next-best-action', 'CrmApiController', 'leadNextBestAction', ['AuthMiddleware']);
$router->get('/api/crm/deals/{id}/next-best-action', 'CrmApiController', 'dealNextBestAction', ['AuthMiddleware']);
$router->get('/api/crm/forecast', 'CrmApiController', 'forecast', ['AuthMiddleware']);
// نقطتا الـAPI التاليتان فقط هما اللي بيستدعوا GeminiClient فعليًا (توليد
// نص) - لذلك مربوطتين بنفس بوابة استهلاك AI Credits المستخدمة في باقي
// ميزات الذكاء الاصطناعي بالمشروع (راجع /api/ai/* أعلاه في نفس الملف).
$router->post('/api/crm/assistant/ask', 'CrmApiController', 'assistantAsk', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_ai_credits'
]);
$router->get('/api/crm/contacts/{id}/ai-summary', 'CrmApiController', 'contactAiSummary', [
    'AuthMiddleware',
    'SubscriptionMiddleware:require_ai_credits'
]);

// ============================================================
// المرحلة 3: Automation Workflows + WhatsApp/Email Communication (بند 12/15/16/17/36)
// ============================================================
$router->get('/api/crm/automation/rules', 'CrmApiController', 'listAutomationRules', ['AuthMiddleware']);
$router->get('/api/crm/automation/templates', 'CrmApiController', 'automationTemplates', ['AuthMiddleware']);
$router->get('/api/crm/automation/schema', 'CrmApiController', 'automationSchema', ['AuthMiddleware']);
$router->post('/api/crm/automation/rules', 'CrmApiController', 'createAutomationRule', ['AuthMiddleware']);
$router->put('/api/crm/automation/rules/{id}', 'CrmApiController', 'updateAutomationRule', ['AuthMiddleware']);
$router->post('/api/crm/automation/rules/from-template', 'CrmApiController', 'createAutomationRuleFromTemplate', ['AuthMiddleware']);
$router->post('/api/crm/automation/rules/{id}/toggle', 'CrmApiController', 'toggleAutomationRule', ['AuthMiddleware']);
$router->delete('/api/crm/automation/rules/{id}', 'CrmApiController', 'deleteAutomationRule', ['AuthMiddleware']);

$router->get('/api/crm/conversations', 'CrmApiController', 'listConversations', ['AuthMiddleware']);
$router->get('/api/crm/conversations/{id}/messages', 'CrmApiController', 'conversationMessages', ['AuthMiddleware']);
$router->post('/api/crm/contacts/{id}/send-whatsapp', 'CrmApiController', 'sendWhatsApp', ['AuthMiddleware']);
$router->post('/api/crm/contacts/{id}/send-email', 'CrmApiController', 'sendEmail', ['AuthMiddleware']);
$router->post('/api/crm/contacts/{id}/send-sms', 'CrmApiController', 'sendSms', ['AuthMiddleware']);
$router->get('/api/crm/communication/status', 'CrmApiController', 'communicationStatus', ['AuthMiddleware']);

// المرحلة 5: Team & Roles/Permissions (بند 30)
$router->get('/api/crm/team', 'CrmApiController', 'listTeam', ['AuthMiddleware']);
$router->post('/api/crm/team', 'CrmApiController', 'addTeamMember', ['AuthMiddleware']);
$router->put('/api/crm/team/{id}', 'CrmApiController', 'updateTeamMemberRole', ['AuthMiddleware']);
$router->delete('/api/crm/team/{id}', 'CrmApiController', 'removeTeamMember', ['AuthMiddleware']);

// المرحلة 12 (G1) - Message Templates
$router->get('/api/crm/templates', 'CrmApiController', 'listTemplates', ['AuthMiddleware']);
$router->get('/api/crm/templates/variables', 'CrmApiController', 'templateVariables', ['AuthMiddleware']);
$router->post('/api/crm/templates', 'CrmApiController', 'createTemplate', ['AuthMiddleware']);
$router->put('/api/crm/templates/{id}', 'CrmApiController', 'updateTemplate', ['AuthMiddleware']);
$router->delete('/api/crm/templates/{id}', 'CrmApiController', 'deleteTemplate', ['AuthMiddleware']);
$router->post('/api/crm/templates/{id}/render', 'CrmApiController', 'renderTemplate', ['AuthMiddleware']);

// المرحلة 12 (G4) - Win/Loss Analysis + Sales Goals
$router->get('/api/crm/reports/win-loss', 'CrmApiController', 'winLossReport', ['AuthMiddleware']);
$router->get('/api/crm/reports/sales-goals', 'CrmApiController', 'salesGoalsReport', ['AuthMiddleware']);
$router->post('/api/crm/reports/sales-goals', 'CrmApiController', 'setSalesGoal', ['AuthMiddleware']);
$router->delete('/api/crm/reports/sales-goals/{id}', 'CrmApiController', 'deleteSalesGoal', ['AuthMiddleware']);

// المرحلة 12 (G2) - Custom Fields
$router->get('/api/crm/custom-fields', 'CrmApiController', 'listCustomFields', ['AuthMiddleware']);
$router->post('/api/crm/custom-fields', 'CrmApiController', 'createCustomField', ['AuthMiddleware']);
$router->put('/api/crm/custom-fields/{id}', 'CrmApiController', 'updateCustomField', ['AuthMiddleware']);
$router->delete('/api/crm/custom-fields/{id}', 'CrmApiController', 'deleteCustomField', ['AuthMiddleware']);
$router->get('/api/crm/entities/{entityType}/{id}/custom-fields', 'CrmApiController', 'getEntityCustomFields', ['AuthMiddleware']);
$router->post('/api/crm/entities/{entityType}/{id}/custom-fields', 'CrmApiController', 'setEntityCustomFields', ['AuthMiddleware']);

// Webhook عام (بدون AuthMiddleware عمدًا - Meta هي اللي بتنادي عليه، راجع
// تعليق CrmWhatsAppWebhookController للتفاصيل الأمنية).
$router->get('/webhooks/crm/whatsapp', 'CrmWhatsAppWebhookController', 'verify', []);
$router->post('/webhooks/crm/whatsapp', 'CrmWhatsAppWebhookController', 'receive', []);
$router->post('/webhooks/crm/sms', 'CrmSmsWebhookController', 'receive', []);
$router->post('/webhooks/crm/email-inbound', 'CrmEmailWebhookController', 'receive', []);

// ============================================
// Consolidated Multi-Phase Module (2026-08-08) - إضافات جديدة فقط
// ============================================
// Phase 5 (Auto-Apply)
$router->post('/api/website-optimizer/fixes/{id}/apply-auto', 'WebsiteOptimizerController', 'applyFixAutomatically', ['AuthMiddleware']);
// Phase 13 (Auto Pilot)
$router->post('/api/website-optimizer/auto-pilot-mode', 'WebsiteOptimizerController', 'setAutoPilotMode', ['AuthMiddleware']);
$router->get('/api/website-optimizer/auto-pilot-log', 'WebsiteOptimizerController', 'getAutoPilotLog', ['AuthMiddleware']);
$router->post('/api/website-optimizer/auto-pilot-log/{id}/rollback', 'WebsiteOptimizerController', 'rollbackChange', ['AuthMiddleware']);
// Phase 14 (SEO Strategy Agent)
$router->post('/api/seo-strategy/generate', 'SeoStrategyController', 'generate', ['AuthMiddleware']);
$router->get('/api/seo-strategy/latest', 'SeoStrategyController', 'getLatestPlan', ['AuthMiddleware']);
$router->post('/api/seo-strategy/tasks/{id}/status', 'SeoStrategyController', 'updateTaskStatus', ['AuthMiddleware']);
// Phase 15 (Executive Dashboard)
$router->get('/api/executive-dashboard', 'ExecutiveDashboardController', 'getDashboard', ['AuthMiddleware']);
// Phase 16 (Onboarding Wizard)
$router->post('/api/onboarding/complete', 'OnboardingController', 'complete', ['AuthMiddleware']);
$router->get('/api/onboarding/status', 'OnboardingController', 'status', ['AuthMiddleware']);
// Phase 11 (AI CEO Advisor)
$router->post('/api/executive/ceo-advisor/ask', 'ExecutiveExtrasController', 'askCeoAdvisor', ['AuthMiddleware']);
// Phase 12 (Action Center)
$router->get('/api/action-center', 'ActionCenterController', 'list', ['AuthMiddleware']);
// Phase 9 (Google Business Agent) - درجة اكتمال بروفايل Google Business
$router->get('/api/reputation/google/profile-completeness', 'ReputationController', 'getProfileCompleteness', ['AuthMiddleware']);
// Phase 6 (Keyword Intelligence)
$router->post('/api/ai/keywords/enrich', 'AIController', 'enrichKeywords', ['AuthMiddleware']);
// Phase 8 (Content Agent)
$router->post('/api/ai/tour-page/generate', 'AIController', 'generateTourPageDraft', ['AuthMiddleware']);
$router->post('/api/ai/tour-page/apply', 'AIController', 'applyTourPage', ['AuthMiddleware']);
// Phase 10 (Backlink/Outreach Agent)
$router->get('/api/outreach/prospects', 'OutreachController', 'listProspects', ['AuthMiddleware']);
$router->post('/api/outreach/prospects', 'OutreachController', 'addProspect', ['AuthMiddleware']);
$router->post('/api/outreach/prospects/{id}/status', 'OutreachController', 'updateProspectStatus', ['AuthMiddleware']);
$router->post('/api/outreach/emails/generate', 'OutreachController', 'generateEmail', ['AuthMiddleware']);
$router->get('/api/outreach/emails', 'OutreachController', 'listEmails', ['AuthMiddleware']);
$router->post('/api/outreach/emails/{id}/edit', 'OutreachController', 'editEmail', ['AuthMiddleware']);
$router->post('/api/outreach/emails/{id}/approve', 'OutreachController', 'approveEmail', ['AuthMiddleware']);
$router->post('/api/outreach/emails/{id}/send', 'OutreachController', 'sendEmail', ['AuthMiddleware']);

// ============================================
// AI Chat & Customer Communication Platform (2026-08-08)
// Unified Inbox / AI Sales Agent / Knowledge Base / Leads /
// Follow-up Automation / Analytics / Messenger+Instagram+Email
// ============================================
// Unified Inbox - المحادثات
$router->get('/api/ai-chat/websites/{id}/conversations', 'ChatInboxController', 'index', ['AuthMiddleware']);
$router->get('/api/ai-chat/websites/{id}/conversations/{conversationId}', 'ChatInboxController', 'show', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/conversations/{conversationId}/reply', 'ChatInboxController', 'reply', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/conversations/{conversationId}/handoff', 'ChatInboxController', 'handoff', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/conversations/{conversationId}/resume-ai', 'ChatInboxController', 'resumeAI', ['AuthMiddleware']);
$router->put('/api/ai-chat/websites/{id}/conversations/{conversationId}', 'ChatInboxController', 'update', ['AuthMiddleware']);
$router->get('/api/ai-chat/websites/{id}/conversations/{conversationId}/reply-suggestions', 'ChatInboxController', 'suggestReplies', ['AuthMiddleware']);
// Knowledge Base
$router->get('/api/ai-chat/websites/{id}/knowledge-base', 'AiKnowledgeBaseController', 'index', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/knowledge-base', 'AiKnowledgeBaseController', 'store', ['AuthMiddleware']);
$router->get('/api/ai-chat/websites/{id}/knowledge-base/preview', 'AiKnowledgeBaseController', 'preview', ['AuthMiddleware']);
$router->put('/api/ai-chat/websites/{id}/knowledge-base/{entryId}', 'AiKnowledgeBaseController', 'update', ['AuthMiddleware']);
$router->delete('/api/ai-chat/websites/{id}/knowledge-base/{entryId}', 'AiKnowledgeBaseController', 'destroy', ['AuthMiddleware']);
// Leads
$router->get('/api/ai-chat/websites/{id}/leads', 'AiLeadController', 'index', ['AuthMiddleware']);
$router->get('/api/ai-chat/websites/{id}/leads/{leadId}', 'AiLeadController', 'show', ['AuthMiddleware']);
$router->put('/api/ai-chat/websites/{id}/leads/{leadId}', 'AiLeadController', 'update', ['AuthMiddleware']);
// Follow-up Automation
$router->get('/api/ai-chat/websites/{id}/followup-settings', 'AiFollowupSettingsController', 'show', ['AuthMiddleware']);
$router->put('/api/ai-chat/websites/{id}/followup-settings', 'AiFollowupSettingsController', 'update', ['AuthMiddleware']);
// AI Analytics
$router->get('/api/ai-chat/websites/{id}/analytics', 'AiAnalyticsController', 'index', ['AuthMiddleware']);
// Learning Loop (Zendesk/Fin): فجوات المعرفة المقترحة + إدارتها + إعادة مسح
$router->get('/api/ai-chat/websites/{id}/learning/gaps', 'AiLearningController', 'gaps', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/learning/gaps/{gapId}/status', 'AiLearningController', 'updateGapStatus', ['AuthMiddleware']);
$router->post('/api/ai-chat/websites/{id}/learning/gaps/scan', 'AiLearningController', 'scan', ['AuthMiddleware']);
// ربط قنوات Messenger/Instagram (بيحتاج AuthMiddleware - بيكلم Meta Graph API)
$router->post('/api/chat/connect/messenger', 'ChatController', 'connectMessenger', ['AuthMiddleware']);
$router->post('/api/chat/connect/instagram', 'ChatController', 'connectInstagram', ['AuthMiddleware']);
// Webhooks للقنوات الجديدة - من غير AuthMiddleware (بتتيجي من Meta/SMTP servers)
$router->get('/api/chat/webhook/messenger/{website_id}', 'ChatController', 'verifyMessengerWebhook');
$router->get('/api/chat/webhook/instagram/{website_id}', 'ChatController', 'verifyInstagramWebhook');
$router->post('/api/chat/webhook/messenger/{website_id}', 'ChatController', 'messengerWebhook');
$router->post('/api/chat/webhook/instagram/{website_id}', 'ChatController', 'instagramWebhook');
$router->post('/api/chat/webhook/email/{website_id}', 'ChatController', 'emailWebhook');
