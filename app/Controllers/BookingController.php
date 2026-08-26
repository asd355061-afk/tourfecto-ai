<?php

/**
 * Tourfecto - Booking Controller (Booking Engine - Phase 2)
 * @version 1.0.0  @date 2026-08-21
 */
class BookingController extends Controller
{
    private BookingEngine $engine;
    private InventoryService $inventory;

    public function __construct()
    {
        parent::__construct();
        $this->engine = new BookingEngine();
        $this->inventory = new InventoryService();
    }

    /** GET /api/bookings */
    public function index(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $userId = (int) ($this->getUser()['id'] ?? 0);
        $page = max(1, (int) $this->get('page', 1));
        $perPage = min(100, max(1, (int) $this->get('per_page', 20)));

        $filters = array_filter([
            'status' => $this->get('status'),
            'product_id' => $this->get('product_id'),
            'from_date' => $this->get('from_date'),
            'to_date' => $this->get('to_date'),
        ]);

        $bookings = (new Booking())->forUser($userId, $filters, $page, $perPage);

        return $this->success(['bookings' => $bookings, 'page' => $page, 'per_page' => $perPage]);
    }

    /** GET /api/bookings/{id} */
    public function show(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $userId = (int) ($this->getUser()['id'] ?? 0);
        $booking = (new Booking())->findOwned($userId, (int) ($params['id'] ?? 0));

        if (!$booking) {
            return $this->error('الحجز غير موجود', 404);
        }

        return $this->success(['booking' => $booking->toArray()]);
    }

    /** POST /api/bookings */
    public function store(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        if (!$this->validate([
            'product_id' => 'required|numeric',
            'start_date' => 'required',
            'customer_name' => 'required',
        ])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        $userId = (int) ($this->getUser()['id'] ?? 0);

        try {
            $result = $this->engine->createBooking($userId, $this->all());

            return $this->success($result, 'تم إنشاء الحجز بنجاح', 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/bookings/{id}/confirm */
    public function confirm(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $userId = (int) ($this->getUser()['id'] ?? 0);

        try {
            $this->engine->confirmBooking($userId, (int) ($params['id'] ?? 0));

            return $this->success([], 'تم تأكيد الحجز');
        } catch (Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/bookings/{id}/cancel */
    public function cancel(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $userId = (int) ($this->getUser()['id'] ?? 0);
        $reason = (string) $this->get('reason', '');

        try {
            $this->engine->cancelBooking($userId, (int) ($params['id'] ?? 0), $reason);

            return $this->success([], 'تم إلغاء الحجز');
        } catch (Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/bookings/{id}/checkout */
    public function checkout(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $userId = (int) ($this->getUser()['id'] ?? 0);
        $bookingId = (int) ($params['id'] ?? 0);
        $successUrl = (string) $this->get('success_url', '');
        $cancelUrl = (string) $this->get('cancel_url', '');
        $gateway = (string) $this->get('gateway', '');

        try {
            $gatewayService = $this->resolvePaymentGateway($gateway);
            $result = $gatewayService->createCheckoutSession($userId, $bookingId, $successUrl, $cancelUrl);
            $result['gateway'] = $gatewayService->key();

            return $this->success($result, 'تم إنشاء رابط الدفع');
        } catch (Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/webhook/booking/stripe - بدون Auth (التوثيق بالتوقيع) */
    public function stripeWebhook(array $params = []): array
    {
        $payload = file_get_contents('php://input') ?: '';
        $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        try {
            $result = (new StripeCheckoutService())->handleWebhook($payload, $signature);

            return $this->success(['handled' => $result['handled'], 'event' => $result['event']]);
        } catch (Exception $e) {
            $code = $e->getCode() === 401 ? 401 : 500;
            http_response_code($code);
            return $this->error($e->getMessage(), $code);
        }
    }

    /** POST /api/webhook/booking/paymob - بدون Auth (التوثيق بـ hmac query param) */
    public function paymobWebhook(array $params = []): array
    {
        $payload = file_get_contents('php://input') ?: '';
        $hmac = (string) ($params['hmac'] ?? '');

        try {
            $result = (new PaymobGateway())->handleWebhook($payload, $hmac);

            return $this->success(['handled' => $result['handled'], 'event' => $result['event']]);
        } catch (Exception $e) {
            $code = $e->getCode() === 401 ? 401 : 500;
            http_response_code($code);
            return $this->error($e->getMessage(), $code);
        }
    }

    /**
     * اختيار بوابة الدفع للـ checkout - Stripe و Paymob قابلين للتبديل
     * من نفس النقطة. `$requested` لو واضح (stripe|paymob) بيستخدمها
     * جبرًا؛ غير كده بيختار أول بوابة مفعّلة بترتيب Stripe ثم Paymob.
     *
     * @return StripeCheckoutService|PaymobGateway
     */
    private function resolvePaymentGateway(string $requested = ''): object
    {
        $stripe = new StripeCheckoutService();
        $paymob = new PaymobGateway();
        $requested = strtolower(trim($requested));

        if ($requested === 'paymob') {
            if ($paymob->isConfigured()) {
                return $paymob;
            }
            throw new Exception('بوابة الدفع Paymob غير مُفعّلة');
        }
        if ($requested === 'stripe') {
            if ($stripe->isConfigured()) {
                return $stripe;
            }
            throw new Exception('بوابة الدفع Stripe غير مُفعّلة');
        }

        return $stripe->isConfigured() ? $stripe : $paymob;
    }

    /** GET /api/bookings/dashboard */
    public function dashboard(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $userId = (int) ($this->getUser()['id'] ?? 0);
        $stats = (new Booking())->dashboardStats($userId);

        return $this->success(['stats' => $stats]);
    }

    /** GET /api/inventory/{productId}/calendar?from=&to= */
    public function calendar(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $productId = (int) ($params['productId'] ?? 0);
        $from = (string) $this->get('from', date('Y-m-d'));
        $to = (string) $this->get('to', date('Y-m-d', strtotime('+30 days')));

        $calendar = (new InventoryDay())->calendar($productId, $from, $to);

        return $this->success(['calendar' => $calendar]);
    }

    /** POST /api/inventory/{productId} { date, capacity, price_override?, is_blocked? } */
    public function setInventory(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        if (!$this->validate(['date' => 'required', 'capacity' => 'required|numeric'])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        $userId = (int) ($this->getUser()['id'] ?? 0);
        $productId = (int) ($params['productId'] ?? 0);

        try {
            $this->inventory->setDay(
                $userId,
                $productId,
                (string) $this->get('date'),
                (int) $this->get('capacity'),
                $this->get('price_override') !== null ? (float) $this->get('price_override') : null,
                (bool) $this->get('is_blocked', false)
            );

            return $this->success([], 'تم تحديث التوفر');
        } catch (Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
