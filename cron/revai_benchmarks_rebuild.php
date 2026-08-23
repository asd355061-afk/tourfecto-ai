<?php

/**
 * Tourfecto - AI Revenue Intelligence: Benchmarks Rebuild (Cron)
 * @version 1.0.0
 *
 * v1.5.0 (Section C): إعادة بناء جدول `revai_benchmarks` من بيانات المنصة
 * الحقيقية المجهولة - بدون أي أرقام مخترعة.
 *
 * القاعدة الثابتة في الموديول: "الـAI يعتمد على بيانات المشروع الحقيقية
 * فقط؛ لا يخترع أرقامًا؛ إن لم تكن البيانات كافية: Not enough data".
 * لذلك هذا السكريبت يشتق median/quartiles (p25/p50/p75) من التوزيع
 * الفعلي لمؤشرات نمو الإيراد الشهري عبر كل الحسابات اللي عندها بيانات
 * إيراد حقيقية (سجل إيراد في آخر 60 يوم). لو عدد الحسابات المؤهلة أقل
 * من حد أدنى (افتراضي 10) => لا نكتب أي صف، ونترك الجدول كما هو:
 * يعني "Not enough data" على مستوى المنصة نفسها.
 *
 * السكريبت لا يلمس البيانات الشخصية: يعمل مجاميع (دوال تجميعية) فقط
 * ولا يقرأ/يكتب أي بيانات هوية مالك الحساب. صفوف الـbenchmarks لا
 * تحمل user_id إطلاقًا - بيانات منصية مجهولة صرفة.
 *
 * الجدولة (تُضاف يدويًا من لوحة التحكم، لا SSH):
 *   0 4 * * 1  php /path/to/project/cron/revai_benchmarks_rebuild.php
 * (أسبوعيًا الاثنين 4 صباحًا - مقياس تكلفة الخادم والملاءمة).
 *
 * كما في revenue_intelligence_scan.php: المستضيف لا يحتوي SSH لذلك
 * تُحمّل كلاسات الموديول يدويًا (list إضافة فقط) بدل الاعتماد على
 * classmap composer.
 */

define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('TOURFECTO_STORAGE', ROOT_PATH . '/storage');

require_once ROOT_PATH . '/vendor/autoload.php';

// كلاسات الموديول - إضافة فقط (no-op لو مش موجودة).
$requiredClassFiles = [
    APP_PATH . '/Services/RevenueIntelligence/RevenueDataGateway.php',
];
foreach ($requiredClassFiles as $classFile) {
    if (file_exists($classFile)) {
        require_once $classFile;
    }
}

if (file_exists(ROOT_PATH . '/.env')) {
    try {
        $dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH);
        $dotenv->load();
    } catch (Throwable $e) {
        error_log('Failed to load .env: ' . $e->getMessage());
    }
}

require_once APP_PATH . '/Config/app.php';
require_once APP_PATH . '/Config/constants.php';
require_once APP_PATH . '/Config/database.php';
require_once APP_PATH . '/Config/encryption.php';

/** كم عدد الحسابات الأدنى لتكوين benchmark منصي موثوق؟ */
const REVAI_BENCH_MIN_ACCOUNTS = 10;

try {
    $db = Database::getInstance();

    // 1) جمع نمو الإيراد الشهري (نسبة تغيّر MRR بين آخر شهرين) لكل حساب
    //    فيه بيانات إيراد حقيقية في آخر 60 يوم - من سجل الإيراد الفعلي.
    //    الصفقة هنا مجهولة: نستخدم فقط user_id كمفتاح للتجميع، لا أي
    //    اسم/بريد/بيانات هوية.
    $accounts = $db->query(
        "SELECT user_id,
                SUM(CASE WHEN MONTH(recorded_at) = MONTH(DATE_SUB(NOW(), INTERVAL 1 MONTH)) THEN amount END) AS last_month,
                SUM(CASE WHEN MONTH(recorded_at) = MONTH(NOW()) THEN amount END) AS this_month
         FROM rev_revenue_records
         WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
         GROUP BY user_id
         HAVING last_month IS NOT NULL AND last_month > 0"
    );

    if (!is_array($accounts) || count($accounts) < REVAI_BENCH_MIN_ACCOUNTS) {
        fwrite(STDOUT, 'revai_benchmarks_rebuild: insufficient real platform data (' . (is_array($accounts) ? count($accounts) : 0) . ' accounts < ' . REVAI_BENCH_MIN_ACCOUNTS . '). No rows written - "Not enough data" preserved.\n');
        exit(0);
    }

    $growthRates = [];
    foreach ($accounts as $acc) {
        $last = (float) ($acc['last_month'] ?? 0);
        $current = (float) ($acc['this_month'] ?? 0);
        if ($last <= 0) {
            continue;
        }
        $growthRates[] = round((($current - $last) / $last) * 100, 4);
    }

    sort($growthRates, SORT_NUMERIC);
    $count = count($growthRates);
    if ($count < REVAI_BENCH_MIN_ACCOUNTS) {
        fwrite(STDOUT, 'revai_benchmarks_rebuild: only ' . $count . ' valid growth rates. No rows written.\n');
        exit(0);
    }

    $percentile = static function (array $sorted, float $p): float {
        if (empty($sorted)) {
            return 0.0;
        }
        $index = (int) ceil(($p / 100) * count($sorted)) - 1;
        $index = max(0, min(count($sorted) - 1, $index));
        return $sorted[$index];
    };

    $p25 = $percentile($growthRates, 25);
    $p50 = $percentile($growthRates, 50);
    $p75 = $percentile($growthRates, 75);
    $asOf = gmdate('Y-m-d');

    // 2) إدراج/استبدال صف benchmark واحد (upsert على uniq_metric_asof).
    $db->query(
        "INSERT INTO revai_benchmarks
            (metric_key, metric_label, p25, p50, p75, basis, sample_size, as_of_date)
         VALUES (?, ?, ?, ?, ?, 'platform', ?, ?)
         ON DUPLICATE KEY UPDATE p25 = VALUES(p25), p50 = VALUES(p50),
             p75 = VALUES(p75), sample_size = VALUES(sample_size), basis = 'platform'",
        [
            'growth_percent_monthly',
            'Monthly Revenue Growth % (real platform aggregation)',
            $p25,
            $p50,
            $p75,
            $count,
            $asOf,
        ]
    );

    fwrite(STDOUT, sprintf(
        "revai_benchmarks_rebuild: wrote growth_percent_monthly for %s (p25=%.2f, p50=%.2f, p75=%.2f, n=%d).\n",
        $asOf,
        $p25,
        $p50,
        $p75,
        $count
    ));
} catch (Throwable $e) {
    fwrite(STDERR, 'revai_benchmarks_rebuild error: ' . $e->getMessage() . "\n");
    exit(1);
}
