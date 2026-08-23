<?php

/**
 * Tourfecto - Booking Engine Integration Test (Phase 2)
 * بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة، وبيشتغل كامل لما
 * يكون فيه اتصال حقيقي بـ tourfecto_test بعد تشغيل الميجريشن الجديدة:
 *   database/migrations/2026_08_21_000001_create_booking_engine_tables.sql
 *
 * بيفحص:
 *   1) إنشاء حجز عند وجود سعة → pending + عداد inventory بيزيد بواحد.
 *   2) رفض الحجز لما السعة تخلص (رسالة واضحة، مفيش استثناء غير متوقع).
 *   3) الإلغاء بيفك مكان السعة تاني (booked_count بيرجع ينقص).
 *   4) منع تعديل capacity لأقل من booked_count الفعلي.
 * @version 1.0.0  @date 2026-08-21
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/BookingEngine.php';
require_once __DIR__ . '/../../app/Services/InventoryService.php';
require_once __DIR__ . '/../../app/Models/Booking.php';

final class BookingEngineIntegrationTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;
    private static int $testUserId = 0;
    private static int $testProductId = 0;

    private function db(): ?PDO
    {
        if (self::$dbChecked) {
            return self::$pdo;
        }
        self::$dbChecked = true;

        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) {
                foreach ([
                    $app . '/Config/app.php',
                    $app . '/Config/database.php',
                ] as $cfg) {
                    if (file_exists($cfg)) {
                        require_once $cfg;
                    }
                }
            }
            if (!class_exists('Database') && file_exists($app . '/Core/Database.php')) {
                require_once $app . '/Core/Database.php';
            }
            if (!class_exists('Logger') && file_exists($app . '/Core/Logger.php')) {
                require_once $app . '/Core/Logger.php';
            }
            if (!class_exists('Model') && file_exists($app . '/Core/Model.php')) {
                require_once $app . '/Core/Model.php';
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                self::$pdo = null;
                return null;
            }

            // تأكد إن جداول الحجز موجودة فعلاً (الميجريشن اتشغّلت)
            $tables = $conn->query("SHOW TABLES LIKE 'bookings'")->fetchAll();
            if (empty($tables)) {
                self::$pdo = null;
                return null;
            }

            self::$pdo = $conn;

            return self::$pdo;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function setUp(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            $this->markTestSkipped('DB غير متاحة أو ميجريشن Phase 2 لسه ما اتشغلتش - راجع تعليق أعلى الملف');
        }

        // مستخدم ومنتج تجريبيين - بيتنضّفوا في tearDown
        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (999001, 'phase2-test@tourfecto.test', 'x', 'Test', NOW())
                     ON DUPLICATE KEY UPDATE email = email");
        self::$testUserId = 999001;

        $pdo->exec("INSERT INTO crm_products (id, user_id, name, price, currency, is_active)
                     VALUES (999001, 999001, 'Test Tour', 100.00, 'USD', 1)
                     ON DUPLICATE KEY UPDATE user_id = user_id");
        self::$testProductId = 999001;

        $pdo->exec("DELETE FROM booking_status_history WHERE booking_id IN (SELECT id FROM bookings WHERE user_id = 999001)");
        $pdo->exec("DELETE FROM bookings WHERE user_id = 999001");
        $pdo->exec("DELETE FROM inventory WHERE product_id = 999001");
    }

    protected function tearDown(): void
    {
        $pdo = self::$pdo;
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM booking_status_history WHERE booking_id IN (SELECT id FROM bookings WHERE user_id = 999001)");
        $pdo->exec("DELETE FROM bookings WHERE user_id = 999001");
        $pdo->exec("DELETE FROM inventory WHERE product_id = 999001");
    }

    public function testBookingSucceedsWhenCapacityAvailable(): void
    {
        $inventory = new InventoryService();
        $inventory->setDay(self::$testUserId, self::$testProductId, '2026-12-01', 2);

        $engine = new BookingEngine();
        $result = $engine->createBooking(self::$testUserId, [
            'product_id' => self::$testProductId,
            'start_date' => '2026-12-01',
            'customer_name' => 'Test Customer',
            'adults_count' => 1,
        ]);

        $this->assertSame('pending', $result['status']);
        $this->assertNotEmpty($result['booking_reference']);

        $availability = $inventory->checkAvailability(self::$testProductId, '2026-12-01');
        $this->assertSame(1, $availability['remaining']);
    }

    public function testBookingRejectedWhenCapacityFull(): void
    {
        $inventory = new InventoryService();
        $inventory->setDay(self::$testUserId, self::$testProductId, '2026-12-02', 1);

        $engine = new BookingEngine();
        $engine->createBooking(self::$testUserId, [
            'product_id' => self::$testProductId,
            'start_date' => '2026-12-02',
            'customer_name' => 'First Customer',
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('السعة مكتملة');
        $engine->createBooking(self::$testUserId, [
            'product_id' => self::$testProductId,
            'start_date' => '2026-12-02',
            'customer_name' => 'Second Customer',
        ]);
    }

    public function testCancelReleasesInventorySlot(): void
    {
        $inventory = new InventoryService();
        $inventory->setDay(self::$testUserId, self::$testProductId, '2026-12-03', 1);

        $engine = new BookingEngine();
        $result = $engine->createBooking(self::$testUserId, [
            'product_id' => self::$testProductId,
            'start_date' => '2026-12-03',
            'customer_name' => 'Test Customer',
        ]);

        $before = $inventory->checkAvailability(self::$testProductId, '2026-12-03');
        $this->assertSame(0, $before['remaining']);

        $engine->cancelBooking(self::$testUserId, (int) $result['id'], 'اختبار الإلغاء');

        $after = $inventory->checkAvailability(self::$testProductId, '2026-12-03');
        $this->assertSame(1, $after['remaining']);
    }

    public function testCannotReduceCapacityBelowBookedCount(): void
    {
        $inventory = new InventoryService();
        $inventory->setDay(self::$testUserId, self::$testProductId, '2026-12-04', 3);

        $engine = new BookingEngine();
        $engine->createBooking(self::$testUserId, [
            'product_id' => self::$testProductId,
            'start_date' => '2026-12-04',
            'customer_name' => 'Test Customer',
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('لا يمكن تقليل السعة');
        $inventory->setDay(self::$testUserId, self::$testProductId, '2026-12-04', 0);
    }
}
