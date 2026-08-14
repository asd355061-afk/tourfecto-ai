<?php
/**
 * Tourfecto - Wallet Controller
 * إيداع رصيد (تحويل بنكي/PayPal يدوي، تأكيد عن طريق واتساب)، وموافقة
 * الأدمن، والاشتراك التلقائي من الرصيد.
 * @version 1.0.0
 */
class WalletController extends Controller {
    /** @var WalletService */
    private $service;

    public function __construct() {
        parent::__construct();
        $this->service = new WalletService();
    }

    /** GET /api/wallet/balance */
    public function getBalance(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        try {
            $balance = $this->service->getBalance((int) $this->user['id']);
            $settings = $this->service->getPaymentSettings();

            return $this->success([
                'balance' => $balance,
                'payment_info' => [
                    'iban' => $settings['iban'] ?? '',
                    'iban_bank_name' => $settings['iban_bank_name'] ?? '',
                    'iban_account_name' => $settings['iban_account_name'] ?? '',
                    'paypal_email' => $settings['paypal_email'] ?? '',
                    'whatsapp_number' => $settings['whatsapp_number'] ?? '',
                ],
            ]);
        } catch (Exception $e) {
            Logger::error('Wallet getBalance Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الرصيد', 500);
        }
    }

    /** GET /api/wallet/history */
    public function getHistory(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        try {
            $history = $this->service->getHistory((int) $this->user['id']);
            return $this->success(['transactions' => array_map(fn($t) => $t->toArray(), $history)]);
        } catch (Exception $e) {
            Logger::error('Wallet getHistory Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب السجل', 500);
        }
    }

    /** POST /api/wallet/deposit */
    public function requestDeposit(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        // تصحيح: عمود amount في قاعدة البيانات DECIMAL(10,2) (أقصى قيمة
        // 99999999.99). من غير هذا التحقق، مبلغ كبير زي 10000000000 كان
        // بيعدي التحقق الأساسي (numeric) ويوصل للـ DB فيرجع خطأ SQL خام
        // للمستخدم (SQLSTATE[22003] Numeric value out of range).
        if (!$this->validate(['amount' => 'required|numeric|min:1|max:99999999.99', 'payment_method' => 'required'])) {
            return $this->error($this->getErrors()['amount'][0] ?? 'بيانات ناقصة', 422);
        }

        try {
            $tx = $this->service->requestDeposit(
                (int) $this->user['id'],
                (float) $this->get('amount'),
                (string) $this->get('payment_method'),
                (string) $this->get('note', '')
            );
            return $this->success(['transaction' => $tx->toArray()], 'تم تسجيل طلب الإيداع - هيتراجع بعد تأكيد استلام التحويل', 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/wallet/subscribe - الاشتراك التلقائي الفوري من الرصيد
     * (بدون أي تدخل بشري لو الرصيد كافي).
     */
    public function subscribeWithBalance(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!$this->validate(['plan_key' => 'required', 'plan_type' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }

        // مفتاح idempotency اختياري من الفرونت (UUID مولّد وقت الضغطة) -
        // بيمنع تكرار الخصم لو نفس الطلب اتبعت مرتين (دبل-كليك/ريتراي شبكة).
        $idempotencyKey = $this->get('idempotency_key');
        $idempotencyKey = is_string($idempotencyKey) && $idempotencyKey !== '' ? $idempotencyKey : null;

        $result = $this->service->subscribeWithBalance(
            (int) $this->user['id'],
            (string) $this->get('plan_key'),
            (string) $this->get('plan_type'),
            $idempotencyKey
        );

        if (!$result['success']) {
            return $this->error($result['error'], 402, $result);
        }

        $message = !empty($result['is_plan_change'])
            ? (($result['charged'] ?? 0) > 0
                ? 'تم تفعيل الباقة الجديدة وخصم فرق السعر $' . $result['charged'] . ' ✔'
                : 'تم تفعيل الباقة الجديدة ✔')
            : 'تم تفعيل الاشتراك فورًا من رصيدك ✔';

        return $this->success($result, $message);
    }

    // ============================================
    // لوحة الأدمن
    // ============================================

    /** GET /api/admin/wallet/pending */
    public function listPendingDeposits(array $params = []): array {
        try {
            $pending = $this->service->getPendingDeposits();
            $result = [];
            foreach ($pending as $tx) {
                $user = (new User())->find((int) $tx->getAttribute('user_id'));
                $result[] = array_merge($tx->toArray(), [
                    'user_email' => $user ? $user->getAttribute('email') : '-',
                    'user_company' => $user ? $user->getAttribute('company_name') : '-',
                ]);
            }
            return $this->success(['deposits' => $result]);
        } catch (Exception $e) {
            Logger::error('Admin listPendingDeposits Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الطلبات', 500);
        }
    }

    /** GET /api/admin/wallet/stats */
    public function getAdminStats(array $params = []): array {
        try {
            return $this->success(['stats' => $this->service->getAdminStats()]);
        } catch (Exception $e) {
            Logger::error('Admin getAdminStats Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الإحصائيات', 500);
        }
    }

    /** GET /api/admin/wallet/mrr-trend?days=30 */
    public function getMrrTrend(array $params = []): array {
        try {
            $days = (int) $this->get('days', 30);
            $days = max(7, min(365, $days));
            return $this->success(['trend' => $this->service->getMrrTrend($days), 'days' => $days]);
        } catch (Exception $e) {
            Logger::error('Admin getMrrTrend Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب تاريخ الإيراد', 500);
        }
    }

    /** POST /api/admin/wallet/{id}/approve */
    public function approveDeposit(array $params = []): array {
        try {
            $tx = $this->service->approveDeposit((int) ($params['id'] ?? 0), (int) $this->user['id'], (string) $this->get('note', ''));
            $this->log('Admin Approved Deposit', ['transaction_id' => $tx->getAttribute('id')]);
            return $this->success(['transaction' => $tx->toArray()], 'تمت الموافقة على الإيداع');
        } catch (Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/admin/wallet/{id}/reject */
    public function rejectDeposit(array $params = []): array {
        try {
            $tx = $this->service->rejectDeposit((int) ($params['id'] ?? 0), (int) $this->user['id'], (string) $this->get('note', ''));
            $this->log('Admin Rejected Deposit', ['transaction_id' => $tx->getAttribute('id')]);
            return $this->success(['transaction' => $tx->toArray()], 'تم رفض الطلب');
        } catch (Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** GET /api/admin/wallet/settings */
    public function getPaymentSettingsAdmin(array $params = []): array {
        try {
            return $this->success(['settings' => $this->service->getPaymentSettings()]);
        } catch (Exception $e) {
            return $this->error('تعذر جلب الإعدادات', 500);
        }
    }

    /** PUT /api/admin/wallet/settings */
    public function updatePaymentSettingsAdmin(array $params = []): array {
        try {
            $allowed = ['iban', 'iban_bank_name', 'iban_account_name', 'paypal_email', 'whatsapp_number'];
            foreach ($allowed as $key) {
                if ($this->get($key) !== null) {
                    $this->service->updatePaymentSetting($key, (string) $this->get($key));
                }
            }
            $this->log('Admin Updated Wallet Settings', []);
            return $this->success([], 'تم تحديث بيانات الدفع');
        } catch (Exception $e) {
            Logger::error('Admin updatePaymentSettingsAdmin Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التحديث', 500);
        }
    }

    /** GET /api/admin/wallet/usage-pricing */
    public function listUsagePricingAdmin(array $params = []): array {
        try {
            return $this->success(['pricing' => $this->service->getAllUsagePricingForAdmin()]);
        } catch (Exception $e) {
            Logger::error('Admin listUsagePricingAdmin Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب التسعير', 500);
        }
    }

    /** PUT /api/admin/wallet/usage-pricing/{id} */
    public function updateUsagePricingAdmin(array $params = []): array {
        try {
            $this->service->updateUsagePricing(
                (int) ($params['id'] ?? 0),
                (float) $this->get('price', 0),
                (bool) $this->get('is_active', true)
            );
            $this->log('Admin Updated Usage Pricing', ['id' => $params['id'] ?? null]);
            return $this->success([], 'تم تحديث التسعير');
        } catch (Exception $e) {
            Logger::error('Admin updateUsagePricingAdmin Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التحديث', 500);
        }
    }

    /** POST /api/admin/users/{id}/add-balance - إضافة رصيد مباشر لعميل معيّن */
    public function adminAddBalance(array $params = []): array {
        if (($this->user['role'] ?? '') !== 'super_admin' && ($this->user['role'] ?? '') !== 'admin') {
            return $this->error('غير مصرح', 403);
        }
        $userId = (int) ($params['id'] ?? 0);
        if (!$userId) return $this->error('عميل غير موجود', 404);

        // تصحيح: نفس حد عمود amount في قاعدة البيانات DECIMAL(10,2)
        if (!$this->validate(['amount' => 'required|numeric|max:99999999.99'])) {
            return $this->error($this->getErrors()['amount'][0] ?? 'المبلغ غير صالح', 422);
        }

        try {
            $tx = $this->service->adminAddBalance(
                $userId, (float) $this->get('amount', 0), (int) $this->user['id'], (string) $this->get('note', '')
            );
            $this->log('Admin Added Balance', ['user_id' => $userId, 'amount' => $this->get('amount')]);
            return $this->success(['transaction' => $tx->toArray(), 'new_balance' => $this->service->getBalance($userId)], 'تم إضافة الرصيد');
        } catch (Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/admin/wallet/cards/generate - توليد دفعة بطاقات شحن جديدة */
    public function generateCards(array $params = []): array {
        if (($this->user['role'] ?? '') !== 'super_admin' && ($this->user['role'] ?? '') !== 'admin') {
            return $this->error('غير مصرح', 403);
        }
        try {
            $cards = $this->service->generateRechargeCards(
                (int) $this->get('count', 0), (float) $this->get('value', 0),
                (int) $this->user['id'], (string) $this->get('batch_label', '')
            );
            $this->log('Admin Generated Recharge Cards', ['count' => count($cards)]);
            return $this->success(['cards' => $cards], 'تم توليد ' . count($cards) . ' بطاقة');
        } catch (Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** GET /api/admin/wallet/cards */
    public function listCards(array $params = []): array {
        try {
            $cards = (new WalletRechargeCard())->where([], ['created_at' => 'DESC'], 200);
            return $this->success(['cards' => array_map(fn($c) => $c->toArray(), $cards)]);
        } catch (Exception $e) {
            Logger::error('Admin listCards Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب البطاقات', 500);
        }
    }

    /** POST /api/wallet/redeem-card - العميل يشحن بطاقة */
    public function redeemCard(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!$this->validate(['code' => 'required'])) return $this->error('اكتب كود البطاقة', 422);

        try {
            $result = $this->service->redeemCard((int) $this->user['id'], (string) $this->get('code'));
            $this->log('Wallet Card Redeemed', ['amount' => $result['value']]);
            return $this->success($result, 'تم شحن $' . number_format($result['value'], 2) . ' لرصيدك بنجاح 🎉');
        } catch (Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
