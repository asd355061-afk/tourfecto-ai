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
];
foreach ($optionalJobDependencyFiles as $depFile) {
    if (file_exists($depFile)) {
        require_once $depFile;
    }
}
