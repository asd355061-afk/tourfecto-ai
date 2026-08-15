<?php
/**
 * Tourfecto - نقطة الدخول الرئيسية
 * @version 1.0.1
 *
 * ملاحظة: هذا الملف تمت إعادة بنائه بالكامل بتاريخ 2026-07-09
 * لأن النسخة الأصلية استُبدلت بالخطأ بأمر Terminal (heredoc) بدلاً من كود PHP فعلي،
 * مما كان يعطّل الموقع بالكامل.
 */

// ============================================
// 1. المسارات الأساسية
// ============================================
define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('TOURFECTO_ROOT', ROOT_PATH);
define('TOURFECTO_STORAGE', ROOT_PATH . '/storage');

// ============================================
// 2. تحميل Composer Autoload
// ============================================
require_once ROOT_PATH . '/vendor/autoload.php';

// ============================================
// 2.1. كلاسات جديدة مش مسجّلة لسه في classmap بتاع Composer
// (السيرفر ده مفيهوش Terminal/SSH لتشغيل composer dump-autoload،
// فبنحمّلها يدويًا هنا لحد ما تتسجل تلقائيًا في مرة رفع لاحقة عادية)
// كل واحد منهم محاط بـ file_exists عمدًا: لو ملف لسه ما اترفعش، الميزة
// دي بس هي اللي هتتعطل (Controller not found) - الموقع كله يفضل شغال
// بدل ما يقع بالكامل بـ 500 خام قبل حتى ما يوصل لأي try/catch.
// ============================================
$optionalNewClassFiles = [
    APP_PATH . '/Services/SearchConsole/GoogleSearchConsoleAPI.php',
    APP_PATH . '/Controllers/SearchConsoleController.php',
    APP_PATH . '/Services/Publishing/ContentFormatter.php',
    APP_PATH . '/Services/Publishing/WordPressPublisher.php',
    APP_PATH . '/Services/Publishing/CustomApiPublisher.php',
    APP_PATH . '/Controllers/IntegrationsController.php',
    APP_PATH . '/Services/AvatarUploadHandler.php',
    APP_PATH . '/Models/ContactSubmission.php',
    APP_PATH . '/Services/Mailer.php',
    APP_PATH . '/Models/PasswordResetToken.php',
    APP_PATH . '/Models/EmailVerificationToken.php',
    APP_PATH . '/Services/SocialMedia/MetaSocialAPI.php',
    APP_PATH . '/Services/Ads/MetaAdsAPI.php',
    APP_PATH . '/Services/Ads/GoogleAdsAPI.php',
    APP_PATH . '/Services/OAuth/MetaOAuthClient.php',
    APP_PATH . '/Models/SubscriptionPlan.php',
    APP_PATH . '/Models/WalletTransaction.php',
    APP_PATH . '/Models/WalletRechargeCard.php',
    APP_PATH . '/Services/Subscription/WalletService.php',
    APP_PATH . '/Controllers/WalletController.php',
    APP_PATH . '/Models/FaqItem.php',
    APP_PATH . '/Models/ReviewRequest.php',
    APP_PATH . '/Models/ReviewRequestSettings.php',
    APP_PATH . '/Models/ReviewRequestOptOut.php',
    APP_PATH . '/Models/ReviewRequestTemplate.php',
    APP_PATH . '/Services/Reputation/ReviewRequestService.php',
    APP_PATH . '/Controllers/ReviewRequestController.php',
    APP_PATH . '/Models/SystemSetting.php',
    APP_PATH . '/Services/System/SystemSettingsService.php',
    APP_PATH . '/Services/SiteLogoUploadHandler.php',
    APP_PATH . '/Models/FeatureFlag.php',
    APP_PATH . '/Models/UserFeatureOverride.php',
    APP_PATH . '/Services/System/FeatureFlagService.php',
    APP_PATH . '/Models/AiConversation.php',
    APP_PATH . '/Models/AiMessage.php',
    APP_PATH . '/Services/AiAssistantService.php',
    APP_PATH . '/Controllers/AiAssistantController.php',
    APP_PATH . '/Models/GeneratedWebsite.php',
    APP_PATH . '/Services/WebsiteBuilderService.php',
    APP_PATH . '/Controllers/WebsiteBuilderController.php',
    APP_PATH . '/Controllers/ServicesController.php',
    // Partner API (المرحلة 2 من خطة API Gateway - 2026-08-06)
    APP_PATH . '/Models/PartnerApiKey.php',
    APP_PATH . '/Middleware/PartnerAuthMiddleware.php',
    APP_PATH . '/Controllers/PartnerController.php',
    APP_PATH . '/Controllers/PartnerKeyController.php',
    APP_PATH . '/Security/Csrf.php',
    // JWT Gateway (المرحلة 2): الكلاسات دي بتتنادى من AuthController::login()
    // (issueJwtTokenPair) لكنها مش موجودة في classmap القديم بتاع composer
    // (السيرفر مفيهوش SSH لتشغيل composer dump-autoload)، فكانت أي محاولة
    // دخول ناجحة (بعد ما تعدّي فحص الباسورد) بتقع بخطأ فادح "Class not found"
    // غير ملتقط بـ catch(Exception) في login()، فيرجع للفرونت إند رد مش JSON
    // خالص، وده اللي بيظهر للمستخدم كـ "تعذر الاتصال بالخادم".
    APP_PATH . '/Core/Security/JwtService.php',
    APP_PATH . '/Models/RefreshToken.php',
    // Creative Studio: توليد فيديو قصير بالذكاء الاصطناعي (Veo) + نشر
    // الوسائط مباشرة على السوشيال ميديا (2026-08-07)
    APP_PATH . '/Services/AI/VeoClient.php',
    APP_PATH . '/Jobs/GenerateVideoJob.php',
    // AI Chat Platform: الكلاس ده بينادى من ChatManager::__construct()
    // لكنه مش مسجّل في classmap القديم بتاع composer (السيرفر مفيهوش SSH
    // لتشغيل composer dump-autoload)، فكانت أي زيارة لصفحة /chat بتقع
    // بـ Fatal Error "Class UnifiedInboxService not found" وتظهر للمستخدم
    // كصفحة "حصل خطأ غير متوقع" (2026-08-09).
    APP_PATH . '/Services/Chat/UnifiedInboxService.php',
    // UnifiedInboxService::__construct() بينادي new AiChatConversation()
    // على طول - فبرضه لازم يتحمّل هنا، وإلا هيقع بنفس النوع من الخطأ
    // بس على كلاس تاني (AiChatConversation not found) حتى بعد ما نضيف
    // UnifiedInboxService نفسه (2026-08-09).
    APP_PATH . '/Models/AiChatConversation.php',
    // تلات قنوات شات دول بيتنادوا جوه ChatManager (Messenger/Instagram/
    // Email) ومش مسجّلين في الـ classmap برضه - مش هيوقعوا صفحة /chat
    // نفسها (بيتحمّلوا وقت الاستخدام الفعلي بس)، بس بنضيفهم هنا زي بقيّة
    // كلاسات الشات عشان نقفل الباب ده تمامًا.
    APP_PATH . '/Services/Chat/MessengerAPI.php',
    APP_PATH . '/Services/Chat/InstagramAPI.php',
    APP_PATH . '/Services/Chat/EmailChannelAPI.php',
    // AI Chat Platform: Providers + Services + Models + Controllers
    // (2026-08-08) - نفس المبدأ: كلاسات جديدة مش في classmap قديم بتاع
    // composer، فلازم تتحمّل يدويًا. الترتيب مهم: الـ Interface أولًا،
    // وبعده OpenAICompatibleProvider (abstract)، وبعده المزودين اللي
    // بيمدّوه، وبعدين AIProviderManager اللي بيعمل `new` عليهم.
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
    APP_PATH . '/Services/AI/AiAnalyticsService.php',
    APP_PATH . '/Services/AI/AiReplySuggestionsService.php',
    // Learning Loop (2026-08-16): Resolution Learning + Knowledge Gaps.
    // لازم يتحمّل قبل AiLearningController وأي Controller بيستخدمه.
    APP_PATH . '/Services/AI/LearningLoopService.php',
    APP_PATH . '/Models/AiUsageLog.php',
    APP_PATH . '/Models/AiKnowledgeBase.php',
    APP_PATH . '/Models/AiCustomerMemory.php',
    APP_PATH . '/Models/AiLead.php',
    APP_PATH . '/Models/AiFollowup.php',
    APP_PATH . '/Models/AiFollowupRule.php',
    APP_PATH . '/Models/AiCustomTag.php',
    APP_PATH . '/Controllers/ChatInboxController.php',
    APP_PATH . '/Controllers/AiKnowledgeBaseController.php',
    APP_PATH . '/Controllers/AiLeadController.php',
    APP_PATH . '/Controllers/AiFollowupSettingsController.php',
    APP_PATH . '/Controllers/AiAnalyticsController.php',
    APP_PATH . '/Controllers/AiLearningController.php',
    // OTA Integration: نفس المشكلة بالظبط - الكنترولر ده مش مسجّل في
    // classmap القديم، فأي طلب لـ /api/ota/status كان بيرمي
    // "Controller OTAController not found" (2026-08-09).
    APP_PATH . '/Controllers/OTAController.php',
    // ============================================
    // Competitor Intelligence (موديول موحّد جديد - 2026-08-09)
    // كل الكلاسات دي جديدة على المشروع ومش موجودة في classmap القديم
    // بتاع composer، فلازم تتحمّل يدويًا هنا زي بقيّة الكلاسات فوق،
    // وإلا هتقع صفحة /competitor-intelligence أو أي API بتاعها بنفس
    // نوع الخطأ اللي كان بيحصل في /chat.
    // ملحوظة على الترتيب: الـ Interface لازم يتحمّل قبل أي كلاس بيعمله
    // implements (GooglePlacesDiscoverySource, NullDiscoverySource,
    // WebsiteOnboardingDiscoverySource).
    // ============================================
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
    APP_PATH . '/Models/CiDiscoveryCandidate.php',
    APP_PATH . '/Models/CiSnapshot.php',
    APP_PATH . '/Models/CiChange.php',
    APP_PATH . '/Models/CiWatchlistItem.php',
    APP_PATH . '/Models/CiAlert.php',
    APP_PATH . '/Models/CiScorecard.php',
    APP_PATH . '/Models/CiInsight.php',
    APP_PATH . '/Models/CiReport.php',
    APP_PATH . '/Models/CiUserPreference.php',
    APP_PATH . '/Jobs/MonitorCompetitorJob.php',
    APP_PATH . '/Jobs/SendCompetitorAlertEmailJob.php',
    // Profile Center Phase 9 (2026-08-10): Data Export
    APP_PATH . '/Jobs/ExportUserDataJob.php',
    APP_PATH . '/Controllers/CompetitorIntelligenceController.php',
    // TOURFECTO AI REVENUE INTELLIGENCE (2026-08-09) - موديول مستقل، شوف CHANGELOG.md
    APP_PATH . '/Models/RevaiForecast.php',
    APP_PATH . '/Models/RevaiInsight.php',
    APP_PATH . '/Models/RevaiAiQuery.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueDataGateway.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueOverviewService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueForecastService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueAnomalyService.php',
    APP_PATH . '/Services/RevenueIntelligence/CustomerRevenueService.php',
    APP_PATH . '/Services/RevenueIntelligence/PipelineRevenueService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueInsightService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueActionService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueAssistantService.php',
    APP_PATH . '/Services/RevenueIntelligence/ExecutiveSummaryService.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueInsightPersister.php',
    APP_PATH . '/Services/RevenueIntelligence/RevenueCacheService.php',
    APP_PATH . '/Controllers/RevenueIntelligenceController.php',
    APP_PATH . '/Jobs/RecomputeRevenueInsightsJob.php',
    // ============================================
    // Tourfecto Account & Workspace Settings Center (Phases 1-8, 2026-08-09)
    // نفس المبدأ بالظبط زي كل الكلاسات فوق: كلاسات جديدة مش موجودة في
    // classmap القديم بتاع composer، فلازم تتحمّل يدويًا هنا وإلا هيقع
    // "Class not found" على أي endpoint بيستخدمها.
    // ============================================
    APP_PATH . '/Models/UserApiKey.php',
    APP_PATH . '/Models/AuditLog.php',
    APP_PATH . '/Models/WorkspaceInvite.php',
    APP_PATH . '/Services/WorkspacePermissions.php',
    APP_PATH . '/Controllers/WorkspaceController.php',
    // 2FA حقيقي (TOTP) في Settings Center (Phase 9 - 2026-08-11)
    APP_PATH . '/Services/TotpService.php',
    // ============================================
    // CRM Module (Contacts/Companies/Tasks/Pipelines/Automation/Segments)
    // نفس المبدأ بالظبط زي كل الكلاسات فوق: كلاسات جديدة مش موجودة في
    // classmap القديم بتاع composer، فلازم تتحمّل يدويًا هنا وإلا هتقع
    // "Class not found" على /crm وكل الـ API بتاعه (2026-08-12).
    // ============================================
    APP_PATH . '/Models/CrmContact.php',
    APP_PATH . '/Models/CrmCompany.php',
    APP_PATH . '/Models/CrmLead.php',
    APP_PATH . '/Models/CrmLeadSource.php',
    APP_PATH . '/Models/CrmDeal.php',
    APP_PATH . '/Models/CrmPipeline.php',
    APP_PATH . '/Models/CrmPipelineStage.php',
    APP_PATH . '/Models/CrmTask.php',
    APP_PATH . '/Models/CrmNote.php',
    APP_PATH . '/Models/CrmMeeting.php',
    APP_PATH . '/Models/CrmMessage.php',
    APP_PATH . '/Models/CrmConversation.php',
    APP_PATH . '/Models/CrmSegment.php',
    APP_PATH . '/Models/CrmAutomationRule.php',
    APP_PATH . '/Models/CrmTeamMember.php',
    APP_PATH . '/Services/Crm/CrmPermissionService.php',
    APP_PATH . '/Services/Crm/CrmContactService.php',
    APP_PATH . '/Services/Crm/CrmCompanyService.php',
    APP_PATH . '/Services/Crm/CrmLeadService.php',
    APP_PATH . '/Services/Crm/CrmDealService.php',
    APP_PATH . '/Services/Crm/CrmTaskService.php',
    APP_PATH . '/Services/Crm/CrmNoteService.php',
    APP_PATH . '/Services/Crm/CrmAppointmentService.php',
    APP_PATH . '/Services/Crm/CrmSegmentService.php',
    APP_PATH . '/Services/Crm/CrmAutomationService.php',
    APP_PATH . '/Services/Crm/CrmTeamService.php',
    APP_PATH . '/Services/Crm/CrmSearchService.php',
    APP_PATH . '/Services/Crm/CrmDashboardService.php',
    APP_PATH . '/Services/Crm/CrmCustomer360Service.php',
    APP_PATH . '/Services/Crm/CrmImportExportService.php',
    APP_PATH . '/Services/Crm/CrmForecastService.php',
    APP_PATH . '/Services/Crm/CrmLeadScoringService.php',
    APP_PATH . '/Services/Crm/CrmNextBestActionService.php',
    APP_PATH . '/Services/Crm/CrmAiAssistantService.php',
    APP_PATH . '/Services/Crm/CrmAiSummaryService.php',
    APP_PATH . '/Services/Crm/CrmEmailService.php',
    APP_PATH . '/Services/Crm/CrmSmsService.php',
    APP_PATH . '/Services/Crm/CrmWhatsAppService.php',
    APP_PATH . '/Controllers/CrmController.php',
    APP_PATH . '/Controllers/CrmApiController.php',
    APP_PATH . '/Controllers/CrmWhatsAppWebhookController.php',
    APP_PATH . '/Controllers/CrmSmsWebhookController.php',
    APP_PATH . '/Controllers/CrmEmailWebhookController.php',
    APP_PATH . '/Models/CrmImportBatch.php',
    APP_PATH . '/Services/Crm/CrmPaginationHelper.php',
    APP_PATH . '/Jobs/CrmImportContactsJob.php',
    // GBP Module Upgrade (2026-08-09/10) - Setup Wizard/Connection Center/
    // Sync/Profile/Photos/Insights/AI/Attributes. نفس السبب زي كل
    // الكلاسات فوق: مش مسجّلة في classmap القديم بتاع composer.
    APP_PATH . '/Services/GoogleBusiness/GbpSetupStatusService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpSyncService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpProfileService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpMediaUploadHandler.php',
    APP_PATH . '/Services/GoogleBusiness/GbpPhotoService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpInsightsService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpAIInsightsService.php',
    APP_PATH . '/Controllers/GbpProfileController.php',
    // GBP Module Upgrade (2026-08-14, Round 7): Audit Log
    APP_PATH . '/Services/GoogleBusiness/GbpAuditLogger.php',
    // GBP Module Upgrade (2026-08-14, Round 8): Health Check Service
    APP_PATH . '/Services/GoogleBusiness/GbpHealthCheckService.php',
    // GBP Competitive Benchmark (2026-08-15): مقارنة تنافسية مع المنافسين القريبين
    APP_PATH . '/Services/GoogleBusiness/GbpCompetitorBenchmarkService.php',
    // GBP Reputation Intelligence (2026-08-15): KPIs + اتجاهات + مخاطر + حصة ظهور
    APP_PATH . '/Services/GoogleBusiness/GbpReputationAnalyticsService.php',
    // Consolidated Multi-Phase Module (2026-08-08) - إضافات جديدة فقط.
    // ملحوظة: تعمّدنا استبعاد ملفات AI Orchestrator/Providers الجديدة
    // (AIOrchestrator/ModelRouter/TaskClassifier/BaseOpenAICompatibleProvider/
    // OpenAIProvider/DeepSeekProvider/KimiProvider/AIProviderInterface الجديدة)
    // لأنها بتعيد تعريف كلاسات موجودة بالفعل وشغّالة (Phase 2 القديم) بعقد
    // مختلف (generateContent بدل generateReply) - كانت هتكسر أي حاجة شغالة
    // عليها حاليًا. الخدمات اللي بتحتاج AIOrchestrator (ArticleGenerator،
    // CompetitorAnalysisService، SeoStrategyService، CeoAdvisorService،
    // OutreachEmailGenerator) مصمّمة بـfallback آمن لـGeminiClient القديم
    // (class_exists('AIOrchestrator') ? ... : new GeminiClient()) فهتشتغل
    // عادي زي ما هي دلوقتي، لحد ما موديول Orchestrator متوافق يتوفر.
    APP_PATH . '/Services/Admin/AIUsageStatsService.php',
    APP_PATH . '/Services/GoogleBusiness/GbpProfileScoreService.php',
    APP_PATH . '/Models/OutreachProspect.php',
    APP_PATH . '/Models/OutreachEmail.php',
    APP_PATH . '/Services/Outreach/OutreachEmailGenerator.php',
    APP_PATH . '/Controllers/OutreachController.php',
    APP_PATH . '/Services/CeoAdvisor/CeoAdvisorService.php',
    APP_PATH . '/Services/ActionCenter/ActionCenterService.php',
    APP_PATH . '/Controllers/ActionCenterController.php',
    APP_PATH . '/Models/SeoStrategyPlan.php',
    APP_PATH . '/Models/SeoStrategyTask.php',
    APP_PATH . '/Services/SeoStrategy/SeoStrategyService.php',
    APP_PATH . '/Controllers/SeoStrategyController.php',
    APP_PATH . '/Services/ExecutiveDashboard/ExecutiveDashboardService.php',
    APP_PATH . '/Controllers/ExecutiveDashboardController.php',
    APP_PATH . '/Controllers/OnboardingController.php',
    // Billing Center Merge (2026-08-09/10): SubscriptionController كان
    // فعليًا بينادي BillingProfile::forUser() و new UsageAlertService()
    // من قبل كده (محمي بـclass_exists) بس الملفات دي متبعتش لحد دلوقتي -
    // يعني الميزتين دول كانوا معطّلين بصمت. إضافة الكلاسين دول بس تفعّلهم.
    APP_PATH . '/Models/BillingProfile.php',
    APP_PATH . '/Services/Subscription/UsageAlertService.php',
    // ============================================
    // Business Control Center (Phases 1-7, 2026-08-14)
    // نفس المبدأ زي كل الكلاسات فوق: كلاسات جديدة مش موجودة في classmap
    // القديم بتاع composer، فلازم تتحمّل يدويًا هنا وإلا هتقع "Class not
    // found" على أي API من /api/business/* (نفس سبب بيقية الموديولات).
    // الترتيب مهم: الـModels قبل الـServices قبل الـControllers (كل
    // Controller بينادي Services، وكل Service بينادي Models).
    // ============================================
    APP_PATH . '/Models/Business.php',
    APP_PATH . '/Models/BusinessLocation.php',
    APP_PATH . '/Models/BusinessService.php',
    APP_PATH . '/Models/BusinessTargetMarket.php',
    APP_PATH . '/Models/BusinessAiContext.php',
    APP_PATH . '/Models/BusinessBrandSettings.php',
    APP_PATH . '/Services/BusinessServiceManager.php',
    APP_PATH . '/Services/BusinessLocationService.php',
    APP_PATH . '/Services/BusinessContextService.php',
    APP_PATH . '/Services/BusinessReadinessService.php',
    APP_PATH . '/Controllers/BusinessController.php',
    APP_PATH . '/Controllers/BusinessLocationController.php',
    APP_PATH . '/Controllers/BusinessServiceController.php',
    APP_PATH . '/Controllers/BusinessTargetMarketController.php',
    APP_PATH . '/Controllers/BusinessAiContextController.php',
    APP_PATH . '/Controllers/BusinessBrandSettingsController.php',
];
foreach ($optionalNewClassFiles as $classFile) {
    if (file_exists($classFile)) {
        require_once $classFile;
    }
}

// ============================================
// 2.2. Helper Functions العامة (event(), container(), listen(), enqueue())
// إصلاح اكتُشف أثناء Settings Center (Phase 11): طبقة
// Container/EventDispatcher كلها (app/Core/Container.php،
// app/Core/Events/*) كانت موجودة ومسجّلة في composer classmap فعليًا
// (يعني الكلاسات نفسها شغالة)، لكن app/Helpers/enterprise_helpers.php
// اللي بيعرّف الدوال العامة المختصرة (event()، container()...) عمره
// ما كان بيتحمّل من أي مكان - يعني أي نداء event('...') في أي مكان
// في الكود كله كان هيرمي Fatal Error "Call to undefined function"
// فورًا. بعد إصلاح التحميل ده، أي موديول (GBP، Revenue Intelligence،
// Reputation...) بقى يقدر يستخدم الأحداث بشكل حقيقي.
// ============================================
if (file_exists(APP_PATH . '/Helpers/enterprise_helpers.php')) {
    require_once APP_PATH . '/Helpers/enterprise_helpers.php';
}

// ============================================
// 3. تحميل متغيرات البيئة (.env)
// ============================================
if (file_exists(ROOT_PATH . '/.env')) {
    try {
        $dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH);
        $dotenv->load();
    } catch (Throwable $e) {
        error_log('Failed to load .env: ' . $e->getMessage());
    }
}

// ============================================
// 4. تحميل ملفات الإعدادات (الترتيب مهم)
// ============================================
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
if (file_exists(APP_PATH . '/Config/revenue_intelligence_events.php')) {
    require_once APP_PATH . '/Config/revenue_intelligence_events.php';
}
// AI Chat Platform - AI Provider Abstraction (بند 20): كل ملف اختياري،
// المزود يُعتبر غير مهيّأ تلقائيًا لو مفتاحه فاضي في .env.
foreach (['/Config/openai.php', '/Config/deepseek.php', '/Config/kimi.php'] as $aiProviderConfig) {
    if (file_exists(APP_PATH . $aiProviderConfig)) {
        require_once APP_PATH . $aiProviderConfig;
    }
}

// ============================================
// 5. بدء الجلسة (Session)
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    if (!is_dir(TOURFECTO_STORAGE . '/sessions')) {
        @mkdir(TOURFECTO_STORAGE . '/sessions', 0755, true);
    }
    // تصحيح أمني: كوكي الجلسة مكانش عليها SameSite خالص، يعني أي موقع
    // خارجي كان يقدر يخلي متصفح المستخدم المسجّل دخول يبعت طلبات نيابة
    // عنه من غير ما يحس (CSRF) - زي تغيير باسورد أو حذف موقع بدون علمه.
    // SameSite=Lax بيمنع الغالبية العظمى من هجمات CSRF من غير ما يكسر
    // أي استخدام عادي للموقع (لسه بيشتغل عادي لو المستخدم فتح رابط من
    // إيميل أو واتساب مثلاً).
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ============================================
// 6. رؤوس أمان أساسية
// ============================================
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(self)');
// CSP متوازن: بيمنع تحميل سكربتات/إطارات من مصادر خارجية غير موثوقة
// (defense-in-depth ضد XSS)، لكن سايبين 'unsafe-inline' مفعّلة لأن
// الموقع فيه كمية كبيرة من <script>/<style> جوه الصفحات نفسها (heredoc)
// - تشديدها أكتر من كده يحتاج نقل كل ده لملفات خارجية أول (خطوة تانية).
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: https:; font-src 'self' data: https:; connect-src 'self' https:; frame-src 'self' https:; frame-ancestors 'self'; base-uri 'self'; object-src 'none';");
if (!empty($_SERVER['HTTPS'])) {
    // HSTS: يجبر المتصفح يستخدم HTTPS بس لمدة سنة - آمن بس لو الموقع
    // شغّال على SSL دايمًا (بيبقى مفعّل تلقائيًا بس على طلبات https).
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ============================================
// دوال مساعدة محلية لهذا الملف (يجب تعريفها قبل الاستخدام)
// ============================================

/**
 * إرسال الاستجابة كـ JSON بشكل موحّد - لكن لو الخطأ 404/500 وكان طلب
 * تصفح صفحة عادي (مش نداء API)، نعرض صفحة خطأ HTML احترافية بدل JSON
 * خام - كان أي رابط غلط أو صفحة اتمسحت بيوري الزائر نص JSON مباشر،
 * أسوأ انطباع ممكن يحصل لزائر جديد.
 */
function send_response(array $data, ?int $httpCode = null): void {
    // بند خاص بـ AI Chat Platform: بعض الـWebhooks الخارجية (مثال: Meta
    // hub.challenge verification handshake الخاص بـMessenger/Instagram)
    // تتطلب استجابة نصية خام حرفية، وليست JSON مغلَّفة. مفتاح محجوز جديد
    // (_raw_text) يتيح هذا فقط عند وجوده صراحة - لا يغيّر أي استجابة
    // حالية لأي Controller آخر لا يستخدم هذا المفتاح إطلاقًا.
    if (array_key_exists('_raw_text', $data)) {
        if (!headers_sent()) {
            http_response_code($httpCode ?? 200);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo (string) $data['_raw_text'];
        return;
    }

    if ($httpCode === null) {
        $httpCode = $data['code'] ?? (($data['success'] ?? true) ? 200 : 400);
    }

    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $isApiRequest = strpos($requestUri, '/api/') === 0;
    $wantsJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

    if (in_array($httpCode, [404, 500], true) && !$isApiRequest && !$wantsJson) {
        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo render_error_page($httpCode, $data['error'] ?? default_error_message($httpCode));
        return;
    }

    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** صفحة خطأ HTML بسيطة ومتّسقة مع هوية الموقع - بدل JSON خام */
function render_error_page(int $code, string $message): string {
    $appName = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
    $title = $code === 404 ? 'الصفحة مش موجودة' : 'حصل خطأ غير متوقع';
    $emoji = $code === 404 ? '🧭' : '⚠️';
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} | {$appName}</title>
    <link rel="icon" type="image/png" href="/assets/icons/favicon-32.png">
    <style>
        body{font-family:'IBM Plex Sans Arabic',sans-serif;background:#060A13;color:#F2F4F8;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;text-align:center;padding:24px;}
        .box{max-width:420px;}
        .emoji{font-size:52px;margin-bottom:18px;}
        h1{font-size:22px;margin:0 0 10px;}
        p{color:#8996AC;font-size:14px;margin:0 0 28px;line-height:1.7;}
        a{display:inline-block;background:#EFB05E;color:#0b0f1a;padding:13px 30px;border-radius:30px;text-decoration:none;font-weight:700;font-size:14px;}
    </style>
</head>
<body>
    <div class="box">
        <div class="emoji">{$emoji}</div>
        <h1>{$title}</h1>
        <p>{$safeMessage}</p>
        <a href="/">ارجع للصفحة الرئيسية</a>
    </div>
</body>
</html>
HTML;
}

/**
 * رسالة خطأ عامة آمنة حسب كود الحالة (لا تكشف تفاصيل داخلية في الإنتاج)
 */
function default_error_message(int $code): string {
    $messages = [
        400 => 'طلب غير صالح',
        401 => 'غير مصرح بالوصول',
        403 => 'ممنوع الوصول لهذا المورد',
        404 => 'الصفحة أو المورد غير موجود',
        405 => 'طريقة الطلب غير مسموحة',
        429 => 'عدد الطلبات كبير جدًا، حاول لاحقًا',
        500 => 'حدث خطأ غير متوقع في الخادم',
    ];
    return $messages[$code] ?? 'حدث خطأ غير متوقع';
}

// ============================================
// 7. تشغيل الميدل وير العام (Global Middleware)
// ============================================
try {
    $globalMiddleware = ['CORSMiddleware', 'LoggingMiddleware', 'VisitorTrackingMiddleware'];
    if (defined('RATE_LIMIT_ENABLED') && RATE_LIMIT_ENABLED) {
        $globalMiddleware[] = 'RateLimitMiddleware';
    }

    foreach ($globalMiddleware as $middlewareClass) {
        if (!class_exists($middlewareClass)) {
            continue;
        }
        $middlewareObj = new $middlewareClass();
        $result = $middlewareObj->handle();
        if ($result !== null) {
            send_response($result);
            exit;
        }
    }
} catch (Throwable $e) {
    if (class_exists('Logger')) {
        Logger::error('Global middleware error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }
}

// ============================================
// 8. تجهيز الراوتر وتحميل المسارات
// ============================================
$router = new Router();

require_once APP_PATH . '/routes/web.php';
require_once APP_PATH . '/routes/api.php';

// ============================================
// 9. تحديد الطلب الحالي (Method + Path)
// ============================================
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

// إزالة سلاش زائد في النهاية (عدا الجذر نفسه)
if ($path !== '/' && substr($path, -1) === '/') {
    $path = rtrim($path, '/');
}

// ============================================
// 9.1. API Versioning (المرحلة 2 من خطة API Gateway - 2026-08-06)
// ============================================
// كل الـ 418 مسار الحالية معرّفة بصيغة /api/... من غير رقم نسخة.
// بدل ما نعيد كتابتهم كلهم بصيغة /api/v1/... (تغيير ضخم عالي الخطورة
// على نظام شغّال فعليًا)، بنعمل alias: أي طلب يوصل لـ /api/v1/xxx
// بيتعامل معاه بالظبط زي /api/xxx (نفس الـ Controller ونفس الـ Middleware)
// - وده كافي دلوقتي عشان أي عميل جديد (موبايل/شريك) يبدأ يستخدم مسارات
// versioned من أول يوم، من غير ما نكسر أي حاجة شغالة بالفعل على /api/...
// القديمة. لو حصل تغيير غير متوافق (breaking change) في شكل رد أي
// endpoint مستقبلاً، وقتها فقط تتفرّع نسخة v2 فعلية بمنطق مختلف.
$isVersionedApiRequest = false;
if (preg_match('#^/api/v(\d+)/(.*)$#', $path, $vMatches)) {
    $isVersionedApiRequest = true;
    $apiVersion = (int) $vMatches[1];
    $path = '/api/' . $vMatches[2];
}

// ============================================
// 10. تنفيذ التوجيه (Dispatch) ومعالجة الأخطاء
// ============================================
try {
    $result = $router->dispatch($requestMethod, $path);
    send_response($result);

} catch (Exception $e) {
    $code = $e->getCode();
    $code = ($code >= 400 && $code < 600) ? $code : 500;

    if ($code >= 500 && class_exists('Logger')) {
        Logger::error('Unhandled Exception', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'path' => $path,
            'method' => $requestMethod
        ]);
    }

    $payload = [
        'success' => false,
        'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : default_error_message($code),
        'code' => $code
    ];

    if (defined('APP_DEBUG') && APP_DEBUG) {
        $payload['debug'] = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }

    send_response($payload, $code);

} catch (Throwable $e) {
    // أي خطأ PHP فادح آخر (TypeError, Error...) يُلتقط هنا بدل أن يظهر كصفحة بيضاء
    if (class_exists('Logger')) {
        Logger::error('Fatal Error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'path' => $path,
            'method' => $requestMethod
        ]);
    }

    send_response([
        'success' => false,
        'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'حدث خطأ غير متوقع في الخادم',
        'code' => 500
    ], 500);
}