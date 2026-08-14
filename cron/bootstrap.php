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
];
foreach ($optionalJobDependencyFiles as $depFile) {
    if (file_exists($depFile)) {
        require_once $depFile;
    }
}
