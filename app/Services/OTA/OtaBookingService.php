<?php

/**
 * Tourfecto - OTA Booking Revenue Binding Service (Item 1c)
 * @version 1.0.0
 *
 * بيربط حجوزات OTA الناجحة (GetYourGuide / Viator) بنفس مسار
 * rev_revenue_records اللي شغّلنا في BookingEngine:
 * - نفس الجدول (rev_revenue_records) بنفس نمط الـ idempotency
 *   (user_id + source + reference_id) حتى لا يتكرر إيراد الحجز.
 * - نفس إشعار event('revenue.updated') لتفريغ كاش RevenueCacheService.
 * - Fail-safe بالكامل: أي خطأ يُسجَّل في Logger ولا يُرمى أبدًا، فلا
 *   يكسر تدفق مزامنة الحجوزات الرئيسي.
 *
 * المصادر المستخدمة: 'ota_booking' (إيراد حجز مؤكد) و 'ota_refund'
 * (تصحيح سالب عند إلغاء حجز كان مؤكدًا). المرجع هو معرّف الحجز
 * الرسمي من منصة OTA نفسها (booking_hash / booking_reference).
 *
 * ملاحظة: لا يوجد حاليًا Webhook/مزامنة OTA فعلية في الموديول،
 * فالمخدمة دي هي نقطة الربط اللي المفروض يُستدعى منها بعد أي تأكيد
 * حجز OTA ناجح (تُستدعى من طبقة المزامنة عند توفرها).
 */
class OtaBookingService
{
    /**
     * تسجيل إيراد حجز OTA مؤكد (idempotent + fail-safe).
     *
     * @param int    $userId          مالك الموقع
     * @param string $platform        'getyourguide' | 'viator'
     * @param string $bookingReference معرّف الحجز الرسمي من منصة OTA
     * @param float  $amount          إجمالي قيمة الحجز بالعملة
     * @param string $currency        العملة (افتراضي USD)
     * @param string|null $productName اسم المنتج/الجولة (اختياري)
     * @param string $category        تصنيف الإيراد (tours افتراضيًا)
     * @return bool true عند إدراج سجل جديد، false لو مكرر/غير صالح/فشل
     */
    public function recordBookingRevenue(
        int $userId,
        string $platform,
        string $bookingReference,
        float $amount,
        string $currency = 'USD',
        ?string $productName = null,
        string $category = 'tours'
    ): bool {
        $reference = trim($bookingReference);
        if ($reference === '' || $amount <= 0) {
            return false;
        }

        try {
            $db = Database::getInstance();

            // Idempotency: نفس الحجز مسجّل قبل كده؟ → لا مكرر
            $existing = $db->query(
                "SELECT id FROM rev_revenue_records
                 WHERE user_id = ? AND source = 'ota_booking' AND reference_id = ? LIMIT 1",
                [$userId, $reference]
            );
            if (!empty($existing)) {
                return false;
            }

            $db->query(
                "INSERT INTO rev_revenue_records
                    (user_id, source, product_name, category, reference_id, amount, currency, recorded_at, notes)
                 VALUES (?, 'ota_booking', ?, ?, ?, ?, ?, NOW(), ?)",
                [
                    $userId,
                    $productName !== null ? $productName : null,
                    $category,
                    $reference,
                    round($amount, 2),
                    $currency ?: 'USD',
                    'حجز OTA مؤكد (' . $platform . ') - ' . $reference,
                ]
            );

            if (function_exists('event')) {
                event('revenue.updated', ['user_id' => $userId, 'amount' => round($amount, 2), 'source' => 'ota_booking']);
            }

            return true;
        } catch (Exception $e) {
            if (class_exists('Logger')) {
                Logger::warning('OTA booking revenue record failed', [
                    'user_id' => $userId,
                    'platform' => $platform,
                    'reference' => $bookingReference,
                    'error' => $e->getMessage(),
                ]);
            }
            return false;
        }
    }

    /**
     * تسجيل تصحيح (استرداد) لحجز OTA كان مؤكدًا - مبلغ سالب.
     * نفس شروط BookingEngine: لازم في إيراد موجب أولًا، ولا يوجد
     * تصحيح سابق لنفس المرجع. Fail-safe (لا يرمي).
     *
     * @return bool true عند إدراج التصحيح، false لو مش مستوفي الشروط
     */
    public function recordBookingRefund(
        int $userId,
        string $platform,
        string $bookingReference,
        float $amount,
        string $currency = 'USD'
    ): bool {
        $reference = trim($bookingReference);
        if ($reference === '' || $amount <= 0) {
            return false;
        }

        try {
            $db = Database::getInstance();

            // لازم في إيراد موجب للحجز ده الأول
            $positive = $db->query(
                "SELECT id FROM rev_revenue_records
                 WHERE user_id = ? AND source = 'ota_booking' AND reference_id = ? AND amount > 0 LIMIT 1",
                [$userId, $reference]
            );
            if (empty($positive)) {
                return false;
            }

            // ولا تصحيح سابق لنفس المرجع
            $priorRefund = $db->query(
                "SELECT id FROM rev_revenue_records
                 WHERE user_id = ? AND source = 'ota_refund' AND reference_id = ? LIMIT 1",
                [$userId, $reference]
            );
            if (!empty($priorRefund)) {
                return false;
            }

            $refundAmount = round(-$amount, 2);
            $db->query(
                "INSERT INTO rev_revenue_records
                    (user_id, source, category, reference_id, amount, currency, recorded_at, notes)
                 VALUES (?, 'ota_refund', 'tours', ?, ?, ?, NOW(), ?)",
                [
                    $userId,
                    $reference,
                    $refundAmount,
                    $currency ?: 'USD',
                    'استرداد حجز OTA (' . $platform . ') - ' . $reference,
                ]
            );

            if (function_exists('event')) {
                event('revenue.updated', ['user_id' => $userId, 'amount' => $refundAmount, 'source' => 'ota_refund']);
            }

            return true;
        } catch (Exception $e) {
            if (class_exists('Logger')) {
                Logger::warning('OTA booking refund record failed', [
                    'user_id' => $userId,
                    'platform' => $platform,
                    'reference' => $bookingReference,
                    'error' => $e->getMessage(),
                ]);
            }
            return false;
        }
    }
}
