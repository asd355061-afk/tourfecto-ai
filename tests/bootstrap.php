<?php

/**
 * Tourfecto - Test Bootstrap
 * تهيئة بيئة الاختبار لـ PHPUnit
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

// ============================================
// 1. تعريف ثوابت الاختبار
// ============================================
define('TOURFECTO_ROOT', dirname(__DIR__));
define('TOURFECTO_APP', TOURFECTO_ROOT . '/app');
define('TOURFECTO_STORAGE', TOURFECTO_ROOT . '/storage');
define('TOURFECTO_TESTS', __DIR__);
define('TOURFECTO_TEST_ENV', 'testing');
define('TOURFECTO_TEST_DB', 'tourfecto_test');

// ============================================
// 1.5. تعريف دالة env() قبل تحميل ملفات التكوين
// ملفات التكوين (Config/app.php, database.php) تستدعي env() مباشرة،
// لكن الدالة معرّفة في app/Helpers/functions.php اللي بتتحمّل بعدهم
// في الترتيب الأصلي - فكان bootstrap ده بيفشل دايمًا بـ
// "Call to undefined function env()". تعريف محايد هنا يحل المشكلة
// ويخلي phpunit.xml يشتغل فعليًا (يرجع قيم متغيرات البيئة الحقيقية
// إن وجدت، وإلا الافتراضي من phpunit.xml).
// ============================================
if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        $real = getenv($key);
        return $real === false ? $default : $real;
    }
}

// ============================================
// 2. تحميل ملفات التكوين
// ============================================
require_once TOURFECTO_APP . '/Config/app.php';
require_once TOURFECTO_APP . '/Config/database.php';
require_once TOURFECTO_APP . '/Config/gemini.php';
require_once TOURFECTO_APP . '/Config/whatsapp.php';
require_once TOURFECTO_APP . '/Config/openai.php';
require_once TOURFECTO_APP . '/Config/deepseek.php';
require_once TOURFECTO_APP . '/Config/kimi.php';
require_once TOURFECTO_APP . '/Config/encryption.php';
require_once TOURFECTO_APP . '/Config/constants.php';

// ============================================
// 3. تحميل الكلاسات الأساسية
// ============================================
require_once TOURFECTO_APP . '/Core/Database.php';
require_once TOURFECTO_APP . '/Core/Encryption.php';
require_once TOURFECTO_APP . '/Core/Logger.php';
require_once TOURFECTO_APP . '/Core/Validator.php';
require_once TOURFECTO_APP . '/Core/Cache.php';
require_once TOURFECTO_APP . '/Core/Router.php';
require_once TOURFECTO_APP . '/Core/Controller.php';
require_once TOURFECTO_APP . '/Core/Model.php';

// ============================================
// 4. تحميل النماذج (Models)
// ============================================
require_once TOURFECTO_APP . '/Models/User.php';
require_once TOURFECTO_APP . '/Models/Subscription.php';
require_once TOURFECTO_APP . '/Models/Website.php';
require_once TOURFECTO_APP . '/Models/AIReport.php';
require_once TOURFECTO_APP . '/Models/Review.php';
require_once TOURFECTO_APP . '/Models/ChatMessage.php';
require_once TOURFECTO_APP . '/Models/BotSetting.php';
require_once TOURFECTO_APP . '/Models/ApiUsageLog.php';

// ============================================
// 5. تحميل خدمات التطبيق
// ============================================
require_once TOURFECTO_APP . '/Services/AI/TourfectoAIEngine.php';
require_once TOURFECTO_APP . '/Services/AI/GeminiClient.php';
require_once TOURFECTO_APP . '/Services/AI/PromptBuilder.php';
require_once TOURFECTO_APP . '/Services/AI/ResponseParser.php';
require_once TOURFECTO_APP . '/Services/AI/SemanticCache.php';

require_once TOURFECTO_APP . '/Services/Reputation/ReputationManager.php';
require_once TOURFECTO_APP . '/Services/Reputation/SentimentAnalyzer.php';
require_once TOURFECTO_APP . '/Services/Reputation/ReplyGenerator.php';

require_once TOURFECTO_APP . '/Services/Chat/ChatManager.php';
require_once TOURFECTO_APP . '/Services/Chat/MessageProcessor.php';
require_once TOURFECTO_APP . '/Services/Chat/AutoReplyEngine.php';
require_once TOURFECTO_APP . '/Services/Chat/ApprovalSystem.php';

require_once TOURFECTO_APP . '/Services/Subscription/SubscriptionValidator.php';
require_once TOURFECTO_APP . '/Services/Subscription/UsageTracker.php';
require_once TOURFECTO_APP . '/Services/Subscription/BillingManager.php';
require_once TOURFECTO_APP . '/Services/PlanFeature.php';

require_once TOURFECTO_APP . '/Services/Security/GDPRCompliance.php';
require_once TOURFECTO_APP . '/Services/Security/DataEncryption.php';
require_once TOURFECTO_APP . '/Services/Security/RateLimiter.php';
require_once TOURFECTO_APP . '/Services/Security/CSRFProtection.php';

// ============================================
// 6. تحميل الوسائط (Middleware)
// ============================================
require_once TOURFECTO_APP . '/Middleware/AuthMiddleware.php';
require_once TOURFECTO_APP . '/Middleware/SubscriptionMiddleware.php';
require_once TOURFECTO_APP . '/Middleware/RateLimitMiddleware.php';
require_once TOURFECTO_APP . '/Middleware/CORSMiddleware.php';
require_once TOURFECTO_APP . '/Middleware/LoggingMiddleware.php';

// ============================================
// 7. تحميل المتحكمات (Controllers)
// ============================================
require_once TOURFECTO_APP . '/Controllers/AIController.php';
require_once TOURFECTO_APP . '/Controllers/ReputationController.php';
require_once TOURFECTO_APP . '/Controllers/ChatController.php';
require_once TOURFECTO_APP . '/Controllers/SubscriptionController.php';
require_once TOURFECTO_APP . '/Controllers/WebhookController.php';
require_once TOURFECTO_APP . '/Controllers/DashboardController.php';

// ============================================
// 8. تحميل دوال المساعدة
// ============================================
require_once TOURFECTO_APP . '/Helpers/functions.php';
require_once TOURFECTO_APP . '/Helpers/validation.php';
require_once TOURFECTO_APP . '/Helpers/formatting.php';
require_once TOURFECTO_APP . '/Helpers/array_helper.php';

// ============================================
// 9. تحميل الاستثناءات (Exceptions)
// ============================================
require_once TOURFECTO_APP . '/Exceptions/DatabaseException.php';
require_once TOURFECTO_APP . '/Exceptions/APIException.php';
require_once TOURFECTO_APP . '/Exceptions/AuthException.php';
require_once TOURFECTO_APP . '/Exceptions/SubscriptionException.php';
require_once TOURFECTO_APP . '/Exceptions/ValidationException.php';

// ============================================
// 10. تحميل بيانات الاختبار (Fixtures)
// ============================================
require_once TOURFECTO_TESTS . '/Fixtures/FixtureLoader.php';

// ============================================
// 11. إنشاء مجلدات الاختبار
// ============================================
$testDirs = [
    TOURFECTO_STORAGE . '/logs',
    TOURFECTO_STORAGE . '/cache/test',
    TOURFECTO_STORAGE . '/uploads/temp',
    TOURFECTO_STORAGE . '/backups/test'
];

foreach ($testDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ============================================
// 12. إعداد قاعدة بيانات الاختبار
// ============================================
function setupTestDatabase(): void
{
    $db = Database::getInstance();

    try {
        // إنشاء قاعدة بيانات الاختبار
        $sql = "CREATE DATABASE IF NOT EXISTS " . TOURFECTO_TEST_DB . " 
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $db->query($sql);

        // استخدام قاعدة بيانات الاختبار
        $sql = "USE " . TOURFECTO_TEST_DB;
        $db->query($sql);

        echo "✅ Test database created/selected: " . TOURFECTO_TEST_DB . "\n";

    } catch (Exception $e) {
        echo "❌ Failed to setup test database: " . $e->getMessage() . "\n";
    }
}

// ============================================
// 13. تحميل مخطط قاعدة البيانات
// ============================================
function loadDatabaseSchema(): void
{
    $db = Database::getInstance();
    $schemaFile = TOURFECTO_ROOT . '/database/schema.sql';

    if (!file_exists($schemaFile)) {
        echo "❌ Schema file not found: " . $schemaFile . "\n";
        return;
    }

    try {
        // قراءة ملف الـ SQL
        $sql = file_get_contents($schemaFile);

        // إزالة كتل DELIMITER (الإجراءات المخزنة والمشغلات) - الاختبارات
        // لا تحتاجها وتكسر التقسيم العادي على الفاصلة المنقوطة.
        $sql = preg_replace('/DELIMITER\s*\/\/.*?DELIMITER\s*;/s', '', $sql);

        // تقسيم الاستعلامات
        $queries = explode(';', $sql);

        foreach ($queries as $query) {
            $query = trim($query);
            if (empty($query)) {
                continue;
            }
            // تجاهل إنشاء/اختيار قاعدة بيانات معينة - الاختبارات تشتغل
            // على قاعدة بيانات الاختبار المحددة في phpunit.xml.
            if (strpos($query, 'CREATE DATABASE') !== false
                || preg_match('/^USE\s+`?[a-z0-9_]+`?$/i', $query)) {
                continue;
            }

            // SET AUTOCOMMIT = 0 في السكيما (مخصصة لتصدير البيانات يدويًا)
            // بتعطّل الـ autocommit على اتصال الاختبار المشترك كله، فيفتح
            // معاملة ضمنية تفضل معلقة - وأي beginTransaction() بعدها بيفشل
            // بـ "There is already an active transaction". نتجاهلها هنا
            // ونعيد تفعيل الـ autocommit بعد نهاية التحميل.
            if (stripos($query, 'SET AUTOCOMMIT') === 0) {
                continue;
            }

            $db->query($query);
        }

        // إعادة تفعيل الـ autocommit بعد تحميل السكيما - الاختبارات بتعتمد
        // على سلوك الـ autocommit الافتراضي (كل استعلام يثبت لوحده).
        $db->query('SET AUTOCOMMIT = 1');

        echo "✅ Database schema loaded successfully\n";

    } catch (Exception $e) {
        echo "❌ Failed to load schema: " . $e->getMessage() . "\n";
    }
}

// ============================================
// 13.5. تطبيق الميجريشنز المطلوبة للاختبارات (بعد السكيما الأساسية)
// سكيما المخطط الأساسي (schema.sql) بتغطي الجداول القديمة، لكن بعض
// اختبارات الـ integration بتحتاج جداول من ميجريشنز أحدث (booking engine,
// wallet, tax_rules...). هنطّبقهم على قاعدة بيانات الاختبار بس (idempotent).
// ============================================
function applyTestMigrations(): void
{
    $db = Database::getInstance();

    $migrations = [
        '2026_08_08_000009_create_crm_products_and_deal_items_tables.sql',
        '2026_08_21_000001_create_booking_engine_tables.sql',
        '2026_08_12_000047_create_payment_transactions_table.sql',
        '2026_08_21_000002_add_booking_payment_link.sql',
        '2026_07_22_000023_create_wallet_system.sql',
        '2026_07_27_000035_create_wallet_recharge_cards.sql',
        '2026_08_12_000049_create_tax_rules_table.sql',
        '2026_07_22_000022_create_plan_pricing_display_table.sql',
    ];

    $migrationsDir = TOURFECTO_ROOT . '/database/migrations';

    foreach ($migrations as $file) {
        $path = $migrationsDir . '/' . $file;
        if (!file_exists($path)) {
            continue;
        }
        try {
            $sql = file_get_contents($path);
            $queries = explode(';', $sql);
            foreach ($queries as $query) {
                $query = trim($query);
                if (empty($query)) {
                    continue;
                }
                $db->query($query);
            }
        } catch (Exception $e) {
            echo "⚠️ Migration {$file} skipped: " . $e->getMessage() . "\n";
        }
    }

    echo "✅ Test migrations applied successfully\n";
}

// ============================================
// 14. تحميل بيانات الاختبار الافتراضية
// ============================================
function loadTestFixtures(): void
{
    try {
        $loader = new FixtureLoader();
        $loader->loadAll(true);
        echo "✅ Test fixtures loaded successfully\n";

    } catch (Exception $e) {
        echo "❌ Failed to load fixtures: " . $e->getMessage() . "\n";
    }
}

// ============================================
// 15. دوال مساعدة للاختبارات
// ============================================

/**
 * إنشاء مستخدم اختباري
 * @param array $overrides - بيانات مخصصة
 * @return int
 */
function createTestUser(array $overrides = []): int
{
    $db = Database::getInstance();

    $defaults = [
        'company_name' => 'Test Company',
        'email' => 'test_' . uniqid() . '@example.com',
        'password' => password_hash('Test@123', PASSWORD_ARGON2ID),
        'phone' => '+966500000001',
        'country' => 'SA',
        'language' => 'ar',
        'timezone' => 'Asia/Riyadh',
        'role' => 'user',
        'is_active' => 1,
        'email_verified' => 1
    ];

    $data = array_merge($defaults, $overrides);

    $sql = "INSERT INTO users (
        company_name, email, password, phone, country, language,
        timezone, role, is_active, email_verified
    ) VALUES (
        :company_name, :email, :password, :phone, :country, :language,
        :timezone, :role, :is_active, :email_verified
    )";

    return (int) $db->query($sql, $data);
}

/**
 * إنشاء موقع اختباري
 * @param int $userId
 * @param array $overrides
 * @return int
 */
function createTestWebsite(int $userId, array $overrides = []): int
{
    $db = Database::getInstance();

    $defaults = [
        'user_id' => $userId,
        'main_url' => 'https://test-' . uniqid() . '.com',
        'company_name' => 'Test Travel Agency',
        'industry' => 'tourism',
        'target_language' => 'ar',
        'target_country' => 'SA',
        'is_verified' => 1
    ];

    $data = array_merge($defaults, $overrides);

    $sql = "INSERT INTO websites (
        user_id, main_url, company_name, industry, target_language,
        target_country, is_verified
    ) VALUES (
        :user_id, :main_url, :company_name, :industry, :target_language,
        :target_country, :is_verified
    )";

    return (int) $db->query($sql, $data);
}

/**
 * إنشاء اشتراك اختباري
 * @param int $userId
 * @param string $plan
 * @param array $overrides
 * @return int
 */
function createTestSubscription(int $userId, string $plan = 'starter', array $overrides = []): int
{
    $db = Database::getInstance();

    $plans = [
        'starter' => ['ai_credits' => 50, 'chat_credits' => 100, 'review_credits' => 10, 'competitor_limit' => 5, 'price' => 49.00],
        'professional' => ['ai_credits' => 200, 'chat_credits' => 500, 'review_credits' => 50, 'competitor_limit' => 20, 'price' => 99.00],
        'enterprise' => ['ai_credits' => 1000, 'chat_credits' => 2000, 'review_credits' => 200, 'competitor_limit' => 100, 'price' => 299.00]
    ];

    $planData = $plans[$plan] ?? $plans['starter'];

    $defaults = [
        'user_id' => $userId,
        'plan_name' => $plan,
        'plan_type' => 'monthly',
        'status' => 'active',
        'price' => $planData['price'],
        'currency' => 'USD',
        'ai_credits' => $planData['ai_credits'],
        'ai_credits_used' => 0,
        'chat_credits' => $planData['chat_credits'],
        'chat_credits_used' => 0,
        'review_credits' => $planData['review_credits'],
        'review_credits_used' => 0,
        'competitor_analysis_limit' => $planData['competitor_limit'],
        'competitor_analysis_used' => 0,
        'auto_pilot' => 0,
        'start_date' => date('Y-m-d H:i:s'),
        'expiry_date' => date('Y-m-d H:i:s', strtotime('+1 month'))
    ];

    $data = array_merge($defaults, $overrides);

    $sql = "INSERT INTO subscriptions (
        user_id, plan_name, plan_type, status, price, currency,
        ai_credits, ai_credits_used, chat_credits, chat_credits_used,
        review_credits, review_credits_used, competitor_analysis_limit,
        competitor_analysis_used, auto_pilot, start_date, expiry_date
    ) VALUES (
        :user_id, :plan_name, :plan_type, :status, :price, :currency,
        :ai_credits, :ai_credits_used, :chat_credits, :chat_credits_used,
        :review_credits, :review_credits_used, :competitor_analysis_limit,
        :competitor_analysis_used, :auto_pilot, :start_date, :expiry_date
    )";

    return (int) $db->query($sql, $data);
}

/**
 * تنظيف بيانات الاختبار
 * @param string $table
 * @param string $condition
 */
function cleanTestData(string $table, string $condition = '1=1'): void
{
    $db = Database::getInstance();
    $sql = "DELETE FROM {$table} WHERE {$condition}";
    $db->query($sql);
}

/**
 * الحصول على وقت التنفيذ
 * @return float
 */
function getExecutionTime(): float
{
    static $startTime;
    if ($startTime === null) {
        $startTime = microtime(true);
        return 0;
    }
    $endTime = microtime(true);
    $duration = $endTime - $startTime;
    $startTime = null;
    return $duration;
}

/**
 * تنسيق وقت التنفيذ
 * @param float $time
 * @return string
 */
function formatExecutionTime(float $time): string
{
    if ($time < 1) {
        return round($time * 1000, 2) . 'ms';
    }
    return round($time, 2) . 's';
}

/**
 * إنشاء Request محاكي
 * @param string $method
 * @param string $uri
 * @param array $data
 * @param array $headers
 * @return array
 */
function createMockRequest(string $method, string $uri, array $data = [], array $headers = []): array
{
    return [
        'method' => $method,
        'uri' => $uri,
        'data' => $data,
        'headers' => array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ], $headers),
        'ip' => '127.0.0.1',
        'user_agent' => 'PHPUnit Test'
    ];
}

/**
 * محاكاة الاستجابة
 * @param array $data
 * @param int $statusCode
 * @return array
 */
function createMockResponse(array $data = [], int $statusCode = 200): array
{
    return [
        'success' => $statusCode >= 200 && $statusCode < 300,
        'data' => $data,
        'status_code' => $statusCode,
        'headers' => ['Content-Type' => 'application/json']
    ];
}

// ============================================
// 16. تشغيل تهيئة الاختبار
// ============================================
echo "\n🧪 Tourfecto Test Bootstrap\n";
echo "============================\n\n";

// إعداد قاعدة البيانات
setupTestDatabase();
loadDatabaseSchema();
applyTestMigrations();
loadTestFixtures();

echo "\n✅ Bootstrap completed successfully!\n";
echo "📝 " . date('Y-m-d H:i:s') . "\n\n";

// ============================================
// 17. تعريف ثوابت إضافية للاختبار
// ============================================
define('TEST_USER_ID', 1);
define('TEST_WEBSITE_ID', 1);
define('TEST_SUBSCRIPTION_ID', 1);
