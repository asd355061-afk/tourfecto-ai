<?php

/**
 * Tourfecto - Stripe Checkout Service (Booking Engine - Phase 2)
 *
 * مسؤول عن كامل دورة حياة الدفع لحجز:
 *   1) createCheckoutSession(): بياخد حجز pending ويبني Stripe Checkout
 *      Session على المبلغ الفعلي للحجز (مش سعر عميل/كوبون) ويسجّل معاملة
 *      pending في payment_transactions قبل ما يبعت العميل للبوابة.
 *   2) handleWebhook(): بيتحقق من توقيع الـ Webhook من Stripe (HMAC)
 *      وبيلغي/يأكد الحجز وبيحدّث حالة المعاملة - لو التوقيع غلط بيرفض.
 *
 * ملاحظات:
 *   - بدون Stripe SDK - اتصال مباشر بـ REST API (نفس نهج بقية التكاملات).
 *   - الأمان: المفاتيح من .env (STRIPE_API_KEY / STRIPE_WEBHOOK_SECRET) -
 *     مش بتتخزن ولا بتتسجّل في اللوجات.
 *   - كل الأوامر idempotent: إعادة تسليم نفس الـ Webhook مش هتعمل حجز
 *     مزدوج ولا هتفشل (بتتحقق من حالة المعاملة أولاً).
 *
 * @version 1.0.0  @date 2026-08-21
 */
class StripeCheckoutService
{
    private const STRIPE_API_BASE = 'https://api.stripe.com/v1';

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** هل Stripe مُفعّل ومفاتيحه موجودة؟ */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey()) && strpos($this->apiKey(), 'your-') !== 0;
    }

    /**
     * إنشاء جلسة Checkout للحجز.
     *
     * @param int    $userId     صاحب الحساب (يُستخدم لفرض الملكية)
     * @param int    $bookingId  معرّف الحجز
     * @param string $successUrl الرابط اللي بيرجع له العميل بعد الدفع الناجح
     * @param string $cancelUrl  الرابط اللي بيرجع له العميل لو ألغى
     *
     * @return array { checkout_url, session_id }
     * @throws Exception لو Stripe غير مُفعّل، الحجز غير موجود/مش مملوك،
     *                   الحجز مش pending، أو فشل إنشاء الجلسة.
     */
    public function createCheckoutSession(int $userId, int $bookingId, string $successUrl, string $cancelUrl): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('بوابة الدفع Stripe غير مُفعّلة. أضف STRIPE_API_KEY و STRIPE_ENABLED في .env');
        }
        if ($successUrl === '' || $cancelUrl === '') {
            throw new Exception('success_url و cancel_url مطلوبين');
        }

        $booking = (new Booking())->findOwned($userId, $bookingId);
        if (!$booking) {
            throw new Exception('الحجز غير موجود');
        }
        if ($booking->status !== 'pending') {
            throw new Exception('لا يمكن الدفع لحجز بحالة ' . $booking->status);
        }

        $amountMinor = $this->toMinorUnits((float) $booking->total_amount, $booking->currency);
        $reference   = $booking->booking_reference;
        $idempotency = 'booking-checkout-' . $bookingId . '-' . $reference;

        // افحص إن في معاملة pending مسجّلة بالفعل لنفس الحجز/المرجع (idempotent)
        $existing = $this->db->query(
            'SELECT * FROM payment_transactions
             WHERE idempotency_key = ? AND status IN ("pending","processing") LIMIT 1',
            [$idempotency]
        );
        if (!empty($existing)) {
            $stored = json_decode($existing[0]['metadata'] ?? '{}', true);
            if (!empty($stored['checkout_url'])) {
                return ['checkout_url' => $stored['checkout_url'], 'session_id' => $existing[0]['gateway_transaction_id']];
            }
        }

        // 1) سجّل المعاملة محليًا (pending) قبل أي اتصال بالبوابة
        $internalId = 'BTX-' . strtoupper(bin2hex(random_bytes(12)));
        $paymentId  = $this->db->query(
            'INSERT INTO payment_transactions
                (internal_transaction_id, user_id, amount, currency, payment_method,
                 gateway, status, reference, booking_id, metadata, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $internalId, $userId, $booking->total_amount, $booking->currency, 'card',
                'stripe', 'pending', $reference, $bookingId,
                json_encode(['booking_reference' => $reference]), $idempotency,
            ]
        );

        // 2) أنشئ الجلسة عند Stripe
        $session = $this->request('POST', '/checkout/sessions', [
            'mode'                 => 'payment',
            'success_url'          => $successUrl,
            'cancel_url'           => $cancelUrl,
            'client_reference_id'  => $reference,
            'customer_email'       => $booking->customer_email,
            'metadata[booking_id]' => (string) $bookingId,
            'line_items[0][quantity]'                              => '1',
            'line_items[0][price_data][currency]'                  => $booking->currency,
            'line_items[0][price_data][unit_amount]'               => (string) $amountMinor,
            'line_items[0][price_data][product_data][name]'        => 'Booking ' . $reference,
        ]);

        if (empty($session['id']) || empty($session['url'])) {
            $this->markPaymentFailed($paymentId, 'Stripe returned invalid session payload');
            throw new Exception('تعذر إنشاء جلسة الدفع - استجابة غير صالحة من Stripe');
        }

        // 3) اربط session_id + رابط الدفع بالمعاملة وحدّث الميتاداتا
        $this->db->query(
            'UPDATE payment_transactions
             SET gateway_transaction_id = ?, status = ?, metadata = ?
             WHERE id = ?',
            [$session['id'], 'pending', json_encode([
                'booking_reference' => $reference,
                'checkout_url'      => $session['url'],
            ]), $paymentId]
        );

        return [
            'checkout_url' => $session['url'],
            'session_id'   => $session['id'],
        ];
    }

    /**
     * التحقق من توقيع Webhook (HMAC-SHA256 مع STRIPE_WEBHOOK_SECRET)
     * ونافذة زمنية 5 دقايق ضد هجمات replay.
     */
    public function verifyWebhookSignature(string $payload, string $signatureHeader): bool
    {
        $secret = $this->webhookSecret();
        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[$k] = $v;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';
        if ($timestamp === '' || $signature === '') {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $signed = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signed, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * معالجة Webhook من Stripe - تأكيد/إلغاء الحجز وتحديث المعاملة.
     *
     * @return array { handled: bool, event: string, message?: string }
     */
    public function handleWebhook(string $payload, string $signatureHeader): array
    {
        if (!$this->verifyWebhookSignature($payload, $signatureHeader)) {
            throw new Exception('Invalid Stripe webhook signature', 401);
        }

        $event  = json_decode($payload, true);
        $type   = $event['type'] ?? '';
        $object = $event['data']['object'] ?? [];

        switch ($type) {
            case 'checkout.session.completed':
                return $this->handleSessionCompleted($object);

            case 'checkout.session.async_payment_succeeded':
                return $this->handleSessionCompleted($object);

            case 'checkout.session.async_payment_failed':
            case 'checkout.session.expired':
                return $this->handleSessionFailed($object);

            default:
                return ['handled' => false, 'event' => $type];
        }
    }

    /**
     * جلسة اكتملت: المبلغ اتقبض فعليًا → فعّل الحجز وسجّل المعاملة succeeded.
     */
    private function handleSessionCompleted(array $session): array
    {
        $sessionId = $session['id'] ?? '';
        $reference = $session['client_reference_id'] ?? '';
        if ($sessionId === '') {
            throw new Exception('Webhook session missing id', 400);
        }

        $tx = $this->findBySessionId($sessionId);
        if (!$tx) {
            // ممكن Stripe توصّل الإيفنت قبل ما التحديث المحلي يلحق؟ البحث بالمرجع
            if ($reference !== '') {
                $tx = $this->findByReference($reference);
            }
            if (!$tx) {
                return ['handled' => false, 'event' => 'checkout.session.completed', 'message' => 'no local transaction'];
            }
        }

        // Idempotent: لو المعاملة succeeded قبل كده، ما تعيدش التأكيد
        if (in_array($tx['status'], ['succeeded', 'refunded'], true)) {
            return ['handled' => true, 'event' => 'checkout.session.completed', 'message' => 'already handled'];
        }

        $bookingId = (int) ($tx['booking_id'] ?? 0);
        if ($bookingId > 0) {
            (new BookingEngine())->confirmBookingFromPayment($bookingId);
        }

        $this->db->query(
            'UPDATE payment_transactions SET status = ?, gateway_transaction_id = ? WHERE id = ?',
            ['succeeded', $sessionId, $tx['id']]
        );

        return ['handled' => true, 'event' => 'checkout.session.completed', 'booking_id' => $bookingId];
    }

    /**
     * الجلسة فشلت/انتهت صلاحيتها: خفّض حالة المعاملة، الحجز يفضل pending
     * عشان العميل يقدر يعيد المحاولة بجلسة جديدة.
     */
    private function handleSessionFailed(array $session): array
    {
        $sessionId = $session['id'] ?? '';
        $tx = $this->findBySessionId($sessionId);
        if ($tx && !in_array($tx['status'], ['succeeded', 'refunded'], true)) {
            $this->db->query(
                'UPDATE payment_transactions SET status = ? WHERE id = ?',
                ['failed', $tx['id']]
            );
        }
        return ['handled' => true, 'event' => 'checkout.session.failed'];
    }

    private function findBySessionId(string $sessionId): ?array
    {
        $rows = $this->db->query(
            'SELECT * FROM payment_transactions WHERE gateway_transaction_id = ? LIMIT 1',
            [$sessionId]
        );
        return $rows[0] ?? null;
    }

    private function findByReference(string $reference): ?array
    {
        $rows = $this->db->query(
            "SELECT * FROM payment_transactions WHERE reference = ? AND booking_id IS NOT NULL ORDER BY id DESC LIMIT 1",
            [$reference]
        );
        return $rows[0] ?? null;
    }

    private function markPaymentFailed(int $paymentId, string $reason): void
    {
        $this->db->query(
            'UPDATE payment_transactions SET status = ?, metadata = ? WHERE id = ?',
            ['failed', json_encode(['error' => $reason]), $paymentId]
        );
    }

    /**
     * طلب HTTP مباشر لـ Stripe (form-encoded مثل ما Stripe بيستقبل).
     * @throws Exception على أخطاء الاتصال أو رفض Stripe (بدون تسريب التفاصيل).
     */
    private function request(string $method, string $path, array $formParams): array
    {
        $ch = curl_init(self::STRIPE_API_BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => http_build_query($formParams),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey(),
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            throw new Exception('تعذر الاتصال ببوابة الدفع - حاول تاني');
        }

        $decoded = json_decode((string) $response, true) ?? [];

        if ($httpCode >= 400) {
            $this->logWarning('Stripe API error', [
                'path'   => $path,
                'status' => $httpCode,
                'code'   => $decoded['error']['code'] ?? null,
            ]);
            $message = $decoded['error']['message'] ?? 'Stripe API error';
            throw new Exception('بوابة الدفع رفضت الطلب: ' . $message);
        }

        return $decoded;
    }

    private function apiKey(): string
    {
        return defined('STRIPE_API_KEY') ? (string) constant('STRIPE_API_KEY') : (string) (getenv('STRIPE_API_KEY') ?: '');
    }

    private function webhookSecret(): string
    {
        return defined('STRIPE_WEBHOOK_SECRET') ? (string) constant('STRIPE_WEBHOOK_SECRET') : (string) (getenv('STRIPE_WEBHOOK_SECRET') ?: '');
    }

    private function logWarning(string $message, array $context = []): void
    {
        if (class_exists('Logger')) {
            Logger::warning($message, $context);
        }
    }

    /**
     * تحويل المبلغ إلى أصغر وحدة نقدية (cents) مع مراعاة العملات صفرية
     * الكسور (JPY مثلاً) لتجنب مضاعفة خاطئة بـ 100.
     */
    private function toMinorUnits(float $amount, string $currency): int
    {
        $zeroDecimal = ['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'UGX', 'RWF', 'XOF', 'XAF', 'BIF', 'GNF', 'KMF'];
        if (in_array(strtoupper($currency), $zeroDecimal, true)) {
            return (int) round($amount);
        }
        return (int) round($amount * 100);
    }
}
