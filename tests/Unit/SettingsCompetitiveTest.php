<?php
/**
 * Tourfecto - Settings Center Competitive Upgrade Test
 * اختبارات منطق الترقيات التنافسية (Phase 15): صلاحية مفاتيح API
 * وإعادة توليد أكواد الطوارئ. كل المنطق هنا Pure Functions ثابتة -
 * بدون أي اتصال بقاعدة بيانات حقيقية (نفس مبدأ RevenueIntelligenceTest).
 *
 * @version 1.0.0
 */

// نستبدل الـ Model base بـ Stub خفيف (فيه نفس توقيع getAttribute/where فقط
// بما يكفي لتحميل الكلاس واختبار الـ static methods الخالصة - مش أي
// اتصال بقاعدة بيانات).
if (!class_exists('Model', false)) {
    class Model {
        protected $attributes = [];
        public function __construct(array $attributes = []) { $this->attributes = $attributes; }
        public function getAttribute(string $key) { return $this->attributes[$key] ?? null; }
        public function where(array $conditions, array $orderBy = [], int $limit = 0): array { return []; }
    }
}

require_once __DIR__ . '/../../app/Models/UserApiKey.php';
require_once __DIR__ . '/../../app/Services/TotpService.php';

class SettingsCompetitiveTest {

    private int $passed = 0;
    private int $failed = 0;

    public function runAll(): void {
        echo "\n⚙️  Settings Center Competitive Tests\n";
        echo "=====================================\n";

        $this->testApiKeyExpiry();
        $this->testRecoveryCodeRotation();

        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "⚙️  Settings Center Competitive Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  ✅ Passed: {$this->passed}\n";
        echo "  ❌ Failed: {$this->failed}\n";
        echo "  📝 Total: {$total}\n";
        echo "  📈 Success Rate: {$percentage}%\n\n";
    }

    private function assertTrue(bool $cond, string $label): void {
        if ($cond) {
            $this->passed++;
            echo "  ✅ {$label}\n";
        } else {
            $this->failed++;
            echo "  ❌ FAIL: {$label}\n";
        }
    }

    private function testApiKeyExpiry(): void {
        echo "\n--- API Key Expiry ---\n";

        // null / '' = لا ينتهي أبدًا
        $this->assertTrue(UserApiKey::isExpired(null) === false, 'isExpired(null) = false (never expires)');
        $this->assertTrue(UserApiKey::isExpired('') === false, 'isExpired("") = false (never expires)');

        // تاريخ مستقبلي = مش منتهي
        $future = date('Y-m-d H:i:s', time() + 86400);
        $this->assertTrue(UserApiKey::isExpired($future) === false, 'isExpired(future) = false');

        // تاريخ ماضي = منتهي (الحافة الزمنية بالضبط)
        $past = date('Y-m-d H:i:s', time() - 86400);
        $this->assertTrue(UserApiKey::isExpired($past) === true, 'isExpired(past) = true');

        // الحافة بالضبط: نفس الثانية الحالية تعتبر منتهية (<= time())
        $now = date('Y-m-d H:i:s', time());
        $this->assertTrue(UserApiKey::isExpired($now) === true, 'isExpired(now) = true (<= time())');
    }

    private function testRecoveryCodeRotation(): void {
        echo "\n--- Recovery Code Rotation ---\n";

        // توليد دفعتين من أكواد الطوارئ
        $oldSet = TotpService::generateRecoveryCodes(10);
        $newSet = TotpService::generateRecoveryCodes(10);

        // الدفعة الجديدة غير متطابقة مع القديمة (عشوائية حقيقية)
        $this->assertTrue($oldSet !== $newSet, 'New set differs from old set (real randomness)');

        // كل كود من الدفعة الجديدة بصيغة XXXX-XXXX صالحة
        $allValid = true;
        foreach ($newSet as $code) {
            if (preg_match('/^[0-9A-F]{4}-[0-9A-F]{4}$/', $code) !== 1) {
                $allValid = false;
            }
        }
        $this->assertTrue($allValid, 'All new codes match XXXX-XXXX format');

        // هاش الدفعة الجديدة بيحقق ضد الأكواد الجديدة (والتدوير سليم)
        $hashedNew = TotpService::hashRecoveryCodes($newSet);
        $index = TotpService::verifyRecoveryCode($hashedNew, $newSet[0]);
        $this->assertTrue($index === 0, 'New hashed set verifies new codes (index 0)');

        // الكود القديم لا يعمل مع الدفعة الجديدة = الإبطال الفعلي للقديم
        $oldIndex = TotpService::verifyRecoveryCode($hashedNew, $oldSet[0]);
        $this->assertTrue($oldIndex === null, 'Old code rejected by new set (old set invalidated)');

        // تطبيع الإدخال: أحرف صغيرة/مسافات تُقبل
        $normalized = TotpService::verifyRecoveryCode($hashedNew, ' ' . strtolower($newSet[1]) . ' ');
        $this->assertTrue($normalized === 1, 'Lowercase + whitespace input still verified (normalized)');
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    $test = new SettingsCompetitiveTest();
    $test->runAll();
}
