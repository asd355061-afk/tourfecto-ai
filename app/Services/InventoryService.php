<?php

/**
 * Tourfecto - Inventory Service (Booking Engine - Phase 2)
 * إدارة السعة اليومية لكل خدمة/جولة + قواعد تسعير ديناميكي بسيطة وصريحة
 * (مش black box - كل اقتراح سعر له سبب واضح يترّاجع له الأدمن).
 * @version 1.0.0  @date 2026-08-21
 */
class InventoryService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** إنشاء/تحديث سعة يوم معين (upsert) - لازم الخدمة تكون مملوكة للحساب */
    public function setDay(int $userId, int $productId, string $date, int $capacity, ?float $priceOverride = null, bool $isBlocked = false): bool
    {
        $owned = $this->db->query(
            'SELECT id FROM crm_products WHERE id = ? AND user_id = ? LIMIT 1',
            [$productId, $userId]
        );
        if (empty($owned)) {
            throw new Exception('الخدمة غير موجودة أو غير مملوكة لهذا الحساب');
        }

        $existing = $this->db->query(
            'SELECT id, booked_count FROM inventory WHERE product_id = ? AND date = ? LIMIT 1',
            [$productId, $date]
        );

        if (!empty($existing)) {
            if ($capacity < (int) $existing[0]['booked_count']) {
                throw new Exception('لا يمكن تقليل السعة عن عدد الحجوزات الفعلية الحالية (' . $existing[0]['booked_count'] . ')');
            }
            $this->db->query(
                'UPDATE inventory SET capacity = ?, price_override = ?, is_blocked = ? WHERE id = ?',
                [$capacity, $priceOverride, $isBlocked ? 1 : 0, $existing[0]['id']]
            );

            return true;
        }

        $this->db->query(
            'INSERT INTO inventory (user_id, product_id, date, capacity, booked_count, price_override, is_blocked)
             VALUES (?, ?, ?, ?, 0, ?, ?)',
            [$userId, $productId, $date, $capacity, $priceOverride, $isBlocked ? 1 : 0]
        );

        return true;
    }

    /** فحص توفر سريع (بدون قفل - للعرض فقط، الحجز الفعلي بيقفل الصف في BookingEngine) */
    public function checkAvailability(int $productId, string $date): array
    {
        $rows = $this->db->query(
            'SELECT capacity, booked_count, is_blocked, price_override
             FROM inventory WHERE product_id = ? AND date = ? LIMIT 1',
            [$productId, $date]
        );

        if (empty($rows)) {
            return ['available' => false, 'remaining' => 0, 'reason' => 'no_inventory_set'];
        }

        $row = $rows[0];
        $remaining = max(0, (int) $row['capacity'] - (int) $row['booked_count']);

        return [
            'available' => $remaining > 0 && (int) $row['is_blocked'] === 0,
            'remaining' => $remaining,
            'price_override' => $row['price_override'] !== null ? (float) $row['price_override'] : null,
            'reason' => (int) $row['is_blocked'] === 1 ? 'blocked' : ($remaining <= 0 ? 'full' : null),
        ];
    }

    /**
     * اقتراح تسعير ديناميكي بسيط بقواعد صريحة (مش AI black box):
     * إشغال عالي + وقت قريب => رفع سعر، إشغال منخفض + وقت قريب => اقتراح خصم.
     * الاقتراح بيترجع للأدمن يوافق عليه يدويًا - مش بيتطبق تلقائيًا هنا.
     */
    public function suggestPrice(int $productId, string $date, float $basePrice): array
    {
        $availability = $this->checkAvailability($productId, $date);
        if (!$availability['available'] && $availability['reason'] !== 'full') {
            return ['suggested_price' => $basePrice, 'reason' => 'لا توجد بيانات كافية للاقتراح'];
        }

        $daysUntil = (strtotime($date) - strtotime(date('Y-m-d'))) / 86400;
        $occupancyRatio = 1 - ($availability['remaining'] ?? 0) / max(1, $availability['remaining'] + 1);

        if ($occupancyRatio >= 0.8 && $daysUntil <= 7 && $daysUntil >= 0) {
            return [
                'suggested_price' => round($basePrice * 1.12, 2),
                'reason' => 'إشغال مرتفع (80%+) وباقي 7 أيام أو أقل - رفع السعر 12%',
            ];
        }

        if ($occupancyRatio <= 0.2 && $daysUntil <= 3 && $daysUntil >= 0) {
            return [
                'suggested_price' => round($basePrice * 0.9, 2),
                'reason' => 'إشغال منخفض (20% أو أقل) وباقي 3 أيام أو أقل - خصم تحفيزي 10%',
            ];
        }

        return ['suggested_price' => $basePrice, 'reason' => 'لا يوجد تغيير مقترح'];
    }
}
