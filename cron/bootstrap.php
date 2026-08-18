<?php

/**
 * Tourfecto - CLI Bootstrap لسكريبتات الـ Cron
 * @version 1.0.0
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden: هذا السكريبت لسطر الأوامر فقط (cron)، مش للمتصفح.');
}
define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('TOURFECTO_ROOT', ROOT_PATH);
define('TOURFECTO_STORAGE', ROOT_PATH . '/storage');
require_once ROOT_PATH . '/vendor/autoload.php';
if (file_exists(ROOT_PATH . '/.env')) {
    try {
        $dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH);
        $dotenv->load();
    } catch (Throwable $e) {
        fwrite(STDERR, 'Failed to load .env: ' . $e->getMessage() . PHP_EOL);
    }
}
require_once APP_PATH . '/Config/app.php';
require_once APP_PATH . '/Config/constants.php';
require_once APP_PATH . '/Config/database.php';
require_once APP_PATH . '/Config/encryption.php';
if (file_exists(APP_PATH . '/Config/gemini.php')) {
    require_once APP_PATH . '/Config/gemini.php';
}
if (file_exists(APP_PATH . '/Config/whatsapp.php')) {
    require_once APP_PATH . '/Config/whatsapp.php';
}
foreach (['/Config/openai.php', '/Config/deepseek.php', '/Config/kimi.php'] as $aiProviderConfig) {
    if (file_exists(APP_PATH . $aiProviderConfig)) {
        require_once APP_PATH . $aiProviderConfig;
    }
}
foreach (glob(APP_PATH . '/Jobs/*.php') as $jobFile) {
    require_once $jobFile;
}
$optionalJobDependencyFiles = [
    APP_PATH . '/Services/SocialMedia/MetaSocialAPI.php',
    APP_PATH . '/Services/AI/VeoClient.php',
    // GBP Module Upgrade (2026-08-10) - GbpBackgroundSyncJob محتاج
    // الكلاسات دي في سياق الـ Cron/Queue Worker (مختلف عن
    // public_html/index.php اللي بيخدم طلبات الـ web فقط).
    APP_PATH . '/Services/GoogleBusiness/GbpSetupStatusService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpSyncService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpProfileService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpMediaUploadHandler.php',
    APP_PATH . '/Services/GoogleBusiness/GbpPhotoService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpInsightsService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpAIInsightsService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpAuditLogger.php',
    // GBP Reputation Intelligence (2026-08-15) - apply_reply_rules.php محتاجهم
    APP_PATH . '/Services/GoogleBusiness/GbpReputationAnalyticsService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpReplyRuleService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpCompetitorBenchmarkService.php',
    // GBP Local SEO Audit (2026-08-15, Tier 3) - تستخدمه أدوات تقارير الكرون
    APP_PATH . '/Services/GoogleBusiness/GbpLocalSeoAuditService.php',
    // AI Chat Platform (2026-08-08) - process_ai_followups.php محتاج
    // FollowUpAutomationService + ChatManager + UnifiedInboxService
    // (بالترتيب: ChatManager بينادي new UnifiedInboxService في
    // __construct، وUnifiedInboxService بينادي new AiChatConversation).
    // Providers لازم تتحمّل قبل AIProviderManager (الـ interface أولًا).
    APP_PATH . '/Services/Chat/ChatManager.php',
    APP_PATH . '/Services/Chat/UnifiedInboxService.php',
    APP_PATH . '/Services/Chat/MessengerAPI.php',
    APP_PATH . '/Services/Chat/InstagramAPI.php',
    APP_PATH . '/Services/Chat/EmailChannelAPI.php',
    APP_PATH . '/Models/AiChatConversation.php',
    APP_PATH . '/Services/AI/Providers/AIProviderInterface.php',
    APP_PATH . '/Services/AI/Providers/OpenAICompatibleProvider.php',
    APP_PATH . '/Services/AI/Providers/OpenAIProvider.php',
    APP_PATH . '/Services/AI/Providers/DeepSeekProvider.php',
    APP_PATH . '/Services/AI/Providers/KimiProvider.php',
    APP_PATH . '/Services/AI/Providers/GeminiProvider.php',
    APP_PATH . '/Services/AI/Providers/AIProviderManager.php',
    APP_PATH . '/Services/AI/KnowledgeBaseService.php',
    APP_PATH . '/Services/AI/AIConversationEngine.php',
    APP_PATH . '/Services/AI/LeadScoringService.php',
    APP_PATH . '/Services/AI/FollowUpAutomationService.php',
    APP_PATH . '/Services/AI/LearningLoopService.php',
    APP_PATH . '/Models/AiFollowup.php',
    APP_PATH . '/Models/AiFollowupRule.php',
    APP_PATH . '/Models/AiLead.php',
    APP_PATH . '/Models/AiCustomerMemory.php',
    APP_PATH . '/Models/AiKnowledgeBase.php',
    APP_PATH . '/Models/AiUsageLog.php',
    // AI Revenue Intelligence (2026-08-15): SendRevenueDigestJob +
    // RecomputeRevenueInsightsJob بيتنفذوا من process_queue.php (الـ queue
    // worker) فمحتاجين كل خدمات الموديول متحمّلة في سياق الـ Cron/Worker -
    // مختلف عن public_html/index.php اللي بيخدم الـ web. الترتيب: الـ Gateway
    // الأول، وبعده الـ Services، وبعده الـ Mailer (الـ Jobs بتنادي new).
    APP_PATH . '/Services/RevenueIntelligence/RevenueDataGateway.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueOverviewService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueForecastService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueAnomalyService.php',
    APP_PATH . '/Services/RevenueIntelligence/CustomerRevenueService.php',
    APP_PATH . '/Services/RevenueIntelligence/PipelineRevenueService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueInsightService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueActionService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueCacheService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueAssistantService.php',
    APP_PATH . '/Services/RevenueIntelligence/ExecutiveSummaryService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueInsightPersister.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueRetentionService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueCopilotService.php',
    // v1.5.0: Subscriptions (MRR/ARR/NRR/GRR) + Forecast/Attribution + Benchmarks/Churn + Stripe mapper
    APP_PATH . '/Services/RevenueIntelligence/BizSubscriptionService.php',
    APP_PATH . '/Services/RevenueIntelligence/DealLevelForecastService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueBenchmarkService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueChurnService.php',
    APP_PATH . '/Services/RevenueIntelligence/StripeRevenueMapper.php',
    // v1.6.0: Dashboard personalization + Stripe webhook
    APP_PATH . '/Services/RevenueIntelligence/RevenueDashboardService.php',
    APP_PATH . '/Services/RevenueIntelligence/StripeWebhookService.php',
    APP_PATH . '/Services/Mailer.php',
    APP_PATH . '/Models/User.php',
    // Competitor Intelligence (2026-08-16) - MonitorCompetitorJob + SendCompetitorAlertEmailJob
    // بينتعاملوا من process_queue.php (الـ queue worker) ومحتاجين موديول CI كامل
    // متحمّل في سياق الـ Cron/Worker - مختلف عن public_html/index.php اللي بيخدم
    // الـ web. الترتيب نفس index.php: الـ Interface الأول، وبعده الخدمات اللي
    // بتدور على بعضها، وبعده الـ Models.
    APP_PATH . '/Core/Contracts/CompetitorDiscoverySourceInterface.php',
    APP_PATH . '/Services/CompetitorIntelligence/SsrfGuard.php',
    APP_PATH . '/Services/CompetitorIntelligence/WebsiteSnapshotFetcher.php',
    APP_PATH . '/Services/CompetitorIntelligence/ChangeDetectionService.php',
    APP_PATH . '/Services/CompetitorIntelligence/MonitoringEngine.php',
    APP_PATH . '/Services/CompetitorIntelligence/SitemapMonitor.php',
    APP_PATH . '/Services/CompetitorIntelligence/NullDiscoverySource.php',
    APP_PATH . '/Services/CompetitorIntelligence/WebsiteOnboardingDiscoverySource.php',
    APP_PATH . '/Services/CompetitorIntelligence/GooglePlacesDiscoverySource.php',
    APP_PATH . '/Services/CompetitorIntelligence/CompetitorDiscoveryService.php',
    APP_PATH . '/Services/CompetitorIntelligence/CompetitorTrackingService.php',
    APP_PATH . '/Services/CompetitorIntelligence/BenchmarkingService.php',
    APP_PATH . '/Services/CompetitorIntelligence/ThreatOpportunityService.php',
    APP_PATH . '/Services/CompetitorIntelligence/AlertService.php',
    APP_PATH . '/Services/CompetitorIntelligence/AICompetitiveAnalyst.php',
    APP_PATH . '/Services/CompetitorIntelligence/ReportService.php',
    APP_PATH . '/Services/CompetitorIntelligence/CiPermissions.php',
    APP_PATH . '/Services/CompetitorIntelligence/CompetitorAnalysisService.php',
    APP_PATH . '/Models/CiDiscoveryCandidate.php',
    APP_PATH . '/Models/CiSnapshot.php',
    APP_PATH . '/Models/CiChange.php',
    APP_PATH . '/Models/CiWatchlistItem.php',
    APP_PATH . '/Models/CiAlert.php',
    APP_PATH . '/Models/CiScorecard.php',
    APP_PATH . '/Models/CiInsight.php',
    APP_PATH . '/Models/CiReport.php',
    APP_PATH . '/Models/CiUserPreference.php',
    APP_PATH . '/Models/Competitor.php',
    APP_PATH . '/Models/CompetitorRecommendation.php',
];
foreach ($optionalJobDependencyFiles as $depFile) {
    if (file_exists($depFile)) {
        require_once $depFile;
    }
}

// AI Chat Platform (2026-08-08/09): نفس المشكلة بالظبط مع كود cron
// follow-up automation (cron/process_ai_followups.php). سكريبت الكرون
// بيعتمد على FollowUpAutomationService + كل Models/Services بتاعته، ولو
// السيرفر معندوش composer dump-autoload حديث، أي كلاس جديد مش هيبقى
// محمّل فكان الكرون بيسكت في صمت (class_exists بيفشل ويخرج بسلام).
// الترتيب مهم: الـModels الأول، بعدين الـServices اللي بتعتمد عليها.
foreach ([
    // Models
    APP_PATH . '/Models/AiKnowledgeBase.php',
    APP_PATH . '/Models/AiChatConversation.php',
    APP_PATH . '/Models/AiCustomerMemory.php',
    APP_PATH . '/Models/AiLead.php',
    APP_PATH . '/Models/AiFollowup.php',
    APP_PATH . '/Models/AiFollowupRule.php',
    APP_PATH . '/Models/AiCustomTag.php',
    APP_PATH . '/Models/AiUsageLog.php',
    // AI Providers (بند 20) - Interface أولاً
    APP_PATH . '/Services/AI/Providers/AIProviderInterface.php',
    APP_PATH . '/Services/AI/Providers/OpenAICompatibleProvider.php',
    APP_PATH . '/Services/AI/Providers/GeminiProvider.php',
    APP_PATH . '/Services/AI/Providers/OpenAIProvider.php',
    APP_PATH . '/Services/AI/Providers/DeepSeekProvider.php',
    APP_PATH . '/Services/AI/Providers/KimiProvider.php',
    APP_PATH . '/Services/AI/Providers/AIProviderManager.php',
    // Services (بترتيب الاعتماد)
    APP_PATH . '/Services/AI/KnowledgeBaseService.php',
    APP_PATH . '/Services/AI/BusinessHoursService.php',
    APP_PATH . '/Services/Chat/UnifiedInboxService.php',
    APP_PATH . '/Services/AI/AIConversationEngine.php',
    APP_PATH . '/Services/AI/LeadScoringService.php',
    APP_PATH . '/Services/AI/FollowUpAutomationService.php',
    APP_PATH . '/Services/AI/AiAnalyticsService.php',
    APP_PATH . '/Services/AI/AiReplySuggestionsService.php',
    APP_PATH . '/Services/Chat/MessengerAPI.php',
    APP_PATH . '/Services/Chat/InstagramAPI.php',
    APP_PATH . '/Services/Chat/EmailChannelAPI.php',
] as $aiChatClassFile) {
    if (file_exists($aiChatClassFile)) {
        require_once $aiChatClassFile;
    }
}
