<?php

/**
 * Tourfecto - Booking Engine (Phase 2)
 * منطق الحجز الأساسي: فحص التوفر، إنشاء حجز آمن من تعارض التزامن
 * (SELECT ... FOR UPDATE داخل transaction)، تأكيد، إلغاء.
 *
 * ملاحظة دمج: النسخة دي بديل مباشر لملف BookingEngine.php المُرسل في
 * Phase 2 zip - نفس الفكرة والـ API تقريبًا، لكن معاد كتابتها بالكامل
 * لتستخدم كلاسات المشروع الحقيقية (Database::getInstance()، Model بدون
 * namespace) بدل namespaces وكلاسات (App\Core\Cache, App\Core\Encryption)
 * كانت هتفشل فورًا وقت أول تحميل (Class not found) لأن المشروع كله
 * classmap بدون namespaces - راجع رد الشات لتفاصيل الفحص.
 * @version 1.0.0  @date 2026-08-21
 */
class BookingEngine
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * إنشاء حجز جديد - آمن من التعارض (لو حجزين جم في نفس اللحظة على نفس
     * اليوم، واحد بس ينجح لو السعة خلصت).
     *
     * @throws Exception لو الخدمة مش موجودة/مش مملوكة للحساب، أو مفيش سعة
     */
    public function createBooking(int $userId, array $data): array
    {
        $productId = (int) ($data['product_id'] ?? 0);
        $startDate = (string) ($data['start_date'] ?? '');

        if ($productId <= 0 || $startDate === '') {
            throw new Exception('product_id و start_date مطلوبين');
        }

        return $this->db->transaction(function (Database $db) use ($userId, $productId, $startDate, $data) {
            // 1) تأكد إن الخدمة مملوكة للحساب فعلاً
            $product = $db->query(
                'SELECT id, name, price, currency FROM crm_products WHERE id = ? AND user_id = ? LIMIT 1',
                [$productId, $userId]
            );
            if (empty($product)) {
                throw new Exception('الخدمة غير موجودة أو غير مملوكة لهذا الحساب');
            }
            $product = $product[0];

            // 2) قفل صف التوفر لليوم ده (FOR UPDATE) - أي محاولة حجز تانية
            // لنفس اليوم/المنتج هتستنى لحد ما الـ transaction دي تخلص.
            $inventory = $db->query(
                'SELECT id, capacity, booked_count, price_override, is_blocked
                 FROM inventory WHERE product_id = ? AND date = ? LIMIT 1 FOR UPDATE',
                [$productId, $startDate]
            );

            if (empty($inventory)) {
                // مفيش صف توفر متسجل لليوم ده = يُعتبر غير متاح صراحةً
                // (سياسة آمنة: عدم وجود بيانات لا يعني سعة غير محدودة).
                throw new Exception('لا يوجد توفر مسجّل لهذا التاريخ');
            }
            $inv = $inventory[0];

            if ((int) $inv['is_blocked'] === 1) {
                throw new Exception('هذا التاريخ مُغلق للحجز');
            }
            if ((int) $inv['booked_count'] >= (int) $inv['capacity']) {
                throw new Exception('السعة مكتملة لهذا التاريخ');
            }

            // 3) احسب السعر: price_override (تسعير ديناميكي) لو موجود، وإلا سعر الخدمة الأساسي
            $unitPrice = $inv['price_override'] !== null ? (float) $inv['price_override'] : (float) $product['price'];
            $adults = max(1, (int) ($data['adults_count'] ?? 1));
            $children = max(0, (int) ($data['children_count'] ?? 0));
            $totalAmount = $data['total_amount'] ?? ($unitPrice * $adults);

            $reference = $this->generateReference();

            $bookingId = $db->query(
                'INSERT INTO bookings
                    (booking_reference, user_id, product_id, customer_id, customer_name,
                     customer_phone, customer_email, start_date, start_time,
                     adults_count, children_count, total_amount, currency, status, source, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $reference, $userId, $productId, $data['customer_id'] ?? null,
                    $data['customer_name'] ?? '', $data['customer_phone'] ?? null,
                    $data['customer_email'] ?? null, $startDate, $data['start_time'] ?? null,
                    $adults, $children, $totalAmount, $data['currency'] ?? $product['currency'],
                    'pending', $data['source'] ?? 'direct', $data['notes'] ?? null,
                ]
            );

            // 4) حدّث عداد الحجوزات في نفس الـ transaction (الصف لسه مقفول لينا)
            $db->query('UPDATE inventory SET booked_count = booked_count + 1 WHERE id = ?', [$inv['id']]);

            $db->query(
                'INSERT INTO booking_status_history (booking_id, from_status, to_status, changed_by_user_id, reason)
                 VALUES (?, NULL, ?, ?, ?)',
                [$bookingId, 'pending', $userId, 'إنشاء حجز جديد']
            );

            // 5) اربط الحجز بأول صفقة open لنفس الحساب لنفس العميل (إن وجدت).
            //    لا ينشئ صفقة جديدة - الربط بيخلّي تأكيد الحجز يرفع الصفقة لـ won.
            $this->linkOpenDealToBooking($db, $userId, $bookingId, $data);

            return [
                'id' => $bookingId,
                'booking_reference' => $reference,
                'status' => 'pending',
                'total_amount' => $totalAmount,
            ];
        });
    }

    public function confirmBooking(int $userId, int $bookingId): bool
    {
        $confirmed = $this->changeStatus($userId, $bookingId, 'confirmed', 'تأكيد الحجز');
        if ($confirmed) {
            // ارفع الصفقة المربوطة بالحجز لـ won (إن كانت open)
            $this->db->transaction(function (Database $db) use ($bookingId) {
                $this->markLinkedDealWon($db, $bookingId);
            });
        }
        return $confirmed;
    }

    /**
     * تأكيد الحجز بعد اكتمال الدفع - بيتنادى من Webhook الدفع (مفيش
     * جلسة مستخدم/صلاحيات مالك، فبنستخدمها داخليًا بس بعد التحقق من
     * توقيع البوابة في StripeCheckoutService). Idempotent: لو الحجز
     * confirmed فعلًا بيرجع true من غير ما يضيف تاريخ مكرر.
     */
    public function confirmBookingFromPayment(int $bookingId): bool
    {
        return $this->db->transaction(function (Database $db) use ($bookingId) {
            $rows = $db->query(
                'SELECT id, status FROM bookings WHERE id = ? LIMIT 1 FOR UPDATE',
                [$bookingId]
            );
            if (empty($rows)) {
                throw new Exception('الحجز غير موجود');
            }
            $fromStatus = $rows[0]['status'];
            if ($fromStatus === 'confirmed' || $fromStatus === 'completed') {
                return true;
            }
            if ($fromStatus === 'cancelled') {
                throw new Exception('لا يمكن تأكيد حجز ملغي بعد الدفع');
            }

            $db->query('UPDATE bookings SET status = ? WHERE id = ?', ['confirmed', $bookingId]);
            $db->query(
                'INSERT INTO booking_status_history (booking_id, from_status, to_status, changed_by_user_id, reason)
                 VALUES (?, ?, ?, ?, ?)',
                [$bookingId, $fromStatus, 'confirmed', null, 'تأكيد تلقائي بعد نجاح الدفع']
            );

            // ارفع الصفقة المربوطة بالحجز لـ won (إن كانت open)
            $this->markLinkedDealWon($db, $bookingId);

            return true;
        });
    }

    /** إلغاء حجز - بيفك حجز مكان التوفر تلقائيًا لو الحجز كان pending/confirmed */
    public function cancelBooking(int $userId, int $bookingId, string $reason = ''): bool
    {
        return $this->db->transaction(function (Database $db) use ($userId, $bookingId, $reason) {
            $rows = $db->query(
                'SELECT id, product_id, start_date, status FROM bookings WHERE id = ? AND user_id = ? LIMIT 1 FOR UPDATE',
                [$bookingId, $userId]
            );
            if (empty($rows)) {
                throw new Exception('الحجز غير موجود');
            }
            $booking = $rows[0];

            if (in_array($booking['status'], ['cancelled', 'completed'], true)) {
                throw new Exception('لا يمكن إلغاء حجز ملغي بالفعل أو مكتمل');
            }

            $db->query('UPDATE bookings SET status = ? WHERE id = ?', ['cancelled', $bookingId]);

            // فكّ مكان التوفر لو كان محجوز فعليًا
            $db->query(
                'UPDATE inventory SET booked_count = GREATEST(0, booked_count - 1)
                 WHERE product_id = ? AND date = ?',
                [$booking['product_id'], $booking['start_date']]
            );

            $db->query(
                'INSERT INTO booking_status_history (booking_id, from_status, to_status, changed_by_user_id, reason)
                 VALUES (?, ?, ?, ?, ?)',
                [$bookingId, $booking['status'], 'cancelled', $userId, $reason ?: 'إلغاء بواسطة الحساب']
            );

            return true;
        });
    }

    private function changeStatus(int $userId, int $bookingId, string $toStatus, string $reason): bool
    {
        return $this->db->transaction(function (Database $db) use ($userId, $bookingId, $toStatus, $reason) {
            $rows = $db->query(
                'SELECT id, status FROM bookings WHERE id = ? AND user_id = ? LIMIT 1 FOR UPDATE',
                [$bookingId, $userId]
            );
            if (empty($rows)) {
                throw new Exception('الحجز غير موجود');
            }
            $fromStatus = $rows[0]['status'];

            $db->query('UPDATE bookings SET status = ? WHERE id = ?', [$toStatus, $bookingId]);
            $db->query(
                'INSERT INTO booking_status_history (booking_id, from_status, to_status, changed_by_user_id, reason)
                 VALUES (?, ?, ?, ?, ?)',
                [$bookingId, $fromStatus, $toStatus, $userId, $reason]
            );

            return true;
        });
    }

    /** مرجع حجز فريد يظهر للعميل - مش تسلسلي عشان محدش يخمن عدد الحجوزات */
    private function generateReference(): string
    {
        return 'BK-' . strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * ربط الحجز بأول صفقة open لنفس الحساب ينتمي لها نفس العميل
     * (عبر customer_id / الإيميل / الهاتف). الربط بيمكّن تأكيد الحجز
     * من ترقية الصفقة لـ won. لا ينشئ صفقات جديدة ولا يعدّل غير
     * الصفقات اللي لسه booking_id بتاعها NULL.
     */
    private function linkOpenDealToBooking(Database $db, int $userId, int $bookingId, array $data): void
    {
        $contactId = $data['customer_id'] ?? null;
        $email = (string) ($data['customer_email'] ?? '');
        $phone = (string) ($data['customer_phone'] ?? '');

        if ($contactId === null && $email === '' && $phone === '') {
            return;
        }

        $sql = "SELECT d.id
                FROM crm_deals d
                LEFT JOIN crm_contacts c ON c.id = d.contact_id
                WHERE d.owner_user_id = ? AND d.status = 'open' AND d.booking_id IS NULL";
        $params = [$userId];

        $conds = [];
        if ($contactId !== null) {
            $conds[] = 'd.contact_id = ?';
            $params[] = (int) $contactId;
        }
        if ($email !== '') {
            $conds[] = 'c.email = ?';
            $params[] = $email;
        }
        if ($phone !== '') {
            $conds[] = 'c.phone = ?';
            $params[] = $phone;
        }
        if (empty($conds)) {
            return;
        }

        $sql .= ' AND (' . implode(' OR ', $conds) . ') ORDER BY d.created_at ASC LIMIT 1';
        $rows = $db->query($sql, $params);
        if (empty($rows)) {
            return;
        }

        $db->query('UPDATE crm_deals SET booking_id = ? WHERE id = ? AND status = ?', [
            $bookingId, $rows[0]['id'], 'open',
        ]);
    }

    /**
     * ترقية الصفقة المربوطة بالحجز لـ won عند تأكيد الحجز (يدوي أو
     * بعد الدفع). Idempotent: صفقات won/lost والإلغاءات مش بتتأثر.
     */
    private function markLinkedDealWon(Database $db, int $bookingId): void
    {
        $rows = $db->query(
            "SELECT id FROM crm_deals WHERE booking_id = ? AND status = 'open' LIMIT 1",
            [$bookingId]
        );
        if (empty($rows)) {
            return;
        }

        $db->query(
            "UPDATE crm_deals SET status = 'won', closed_at = COALESCE(closed_at, NOW()) WHERE id = ? AND status = 'open'",
            [$rows[0]['id']]
        );
    }
}
