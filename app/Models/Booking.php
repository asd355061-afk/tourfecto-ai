<?php

/** Tourfecto - Booking Model (Booking Engine - Phase 2) @version 1.0.0 */
class Booking extends Model
{
    protected $table = 'bookings';
    protected $fillable = [
        'booking_reference', 'user_id', 'product_id', 'customer_id',
        'customer_name', 'customer_phone', 'customer_email',
        'start_date', 'start_time', 'adults_count', 'children_count',
        'total_amount', 'currency', 'status', 'source', 'notes',
    ];

    /** حجوزات الحساب، بفلترة اختيارية بالحالة والتاريخ */
    public function forUser(int $userId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $sql = 'SELECT * FROM bookings WHERE user_id = ?';
        $params = [$userId];

        if (!empty($filters['status'])) {
            $sql .= ' AND status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['product_id'])) {
            $sql .= ' AND product_id = ?';
            $params[] = (int) $filters['product_id'];
        }
        if (!empty($filters['from_date'])) {
            $sql .= ' AND start_date >= ?';
            $params[] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $sql .= ' AND start_date <= ?';
            $params[] = $filters['to_date'];
        }

        $sql .= ' ORDER BY start_date DESC, id DESC LIMIT ' . max(1, (int) $perPage)
            . ' OFFSET ' . max(0, ((int) $page - 1) * (int) $perPage);

        return $this->db->query($sql, $params);
    }

    /** حجز واحد مملوك للحساب (يمنع أي حساب يشوف حجز حساب تاني) */
    public function findOwned(int $userId, int $bookingId): ?Booking
    {
        $rows = $this->db->query(
            'SELECT * FROM bookings WHERE id = ? AND user_id = ? LIMIT 1',
            [$bookingId, $userId]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];

        return $model;
    }

    public function findByReference(string $reference): ?Booking
    {
        $rows = $this->db->query(
            'SELECT * FROM bookings WHERE booking_reference = ? LIMIT 1',
            [$reference]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];

        return $model;
    }

    /** إحصاءات سريعة للوحة التحكم */
    public function dashboardStats(int $userId): array
    {
        $rows = $this->db->query(
            "SELECT status, COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS total
             FROM bookings WHERE user_id = ? GROUP BY status",
            [$userId]
        );

        $stats = [
            'pending' => 0, 'confirmed' => 0, 'cancelled' => 0,
            'completed' => 0, 'no_show' => 0, 'total_revenue' => 0.0,
        ];
        foreach ($rows as $row) {
            $stats[$row['status']] = (int) $row['count'];
            if (in_array($row['status'], ['confirmed', 'completed'], true)) {
                $stats['total_revenue'] += (float) $row['total'];
            }
        }

        return $stats;
    }
}
