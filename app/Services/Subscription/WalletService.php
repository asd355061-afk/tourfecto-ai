<?php
/**
 * Tourfecto - Wallet Service
 * منطق المحفظة: طلب إيداع، موافقة الأدمن، الخصم التلقائي وقت الاشتراك.
 * الرصيد بيتحسب دايمًا من مجموع الحركات المكتملة (مش عمود منفصل) -
 * أضمن وأسهل في التدقيق، وميحصلش تضارب بيانات لو حصل خطأ في مكان.
 * @version 1.0.0
 */
class WalletService {
    /** @var Database */
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * الرصيد الحالي = مجموع الإيداعات المكتملة - مجموع الخصومات (المخزّنة كأرقام سالبة أصلاً)
     */
    public function getBalance(int $userId): float {
        $rows = $this->db->query(
            "SELECT COALESCE(SUM(amount), 0) AS balance FROM wallet_transactions WHERE user_id = ? AND status = 'completed'",
            [$userId]
        );
        return (float) ($rows[0]['balance'] ?? 0);
    }

    /**
     * طلب إيداع جديد - بيتسجّل كـ "pending" لحد ما الأدمن يوافق بعد ما
     * يستلم التحويل فعليًا (بنك/PayPal) ويتأكد عن طريق واتساب.
     */
    public function requestDeposit(int $userId, float $amount, string $paymentMethod, string $note = ''): WalletTransaction {
        if ($amount <= 0) {
            throw new Exception('المبلغ لازم يكون أكبر من صفر');
        }
        if (!in_array($paymentMethod, ['iban', 'paypal'], true)) {
            throw new Exception('طريقة دفع غير معروفة');
        }

        $tx = new WalletTransaction();
        $tx->fill([
            'user_id' => $userId,
            'type' => 'deposit',
            'amount' => $amount,
            'currency' => 'USD',
            'status' => 'pending',
            'payment_method' => $paymentMethod,
            'reference_note' => $note,
        ]);
        $tx->save();

        ActivityLog::record('wallet', 'wallet.deposit_requested', [
            'user_id' => $userId, 'subject_type' => 'wallet_transactions', 'subject_id' => (int) $tx->getAttribute('id'),
            'meta' => ['amount' => $amount, 'method' => $paymentMethod],
        ]);

        return $tx;
    }

    /** موافقة الأدمن على إيداع - بيحوّل حالته لـ completed فيدخل في حساب الرصيد */
    public function approveDeposit(int $transactionId, int $adminId, string $adminNote = ''): WalletTransaction {
        $tx = (new WalletTransaction())->find($transactionId);
        if (!$tx || $tx->getAttribute('type') !== 'deposit') {
            throw new Exception('طلب الإيداع غير موجود');
        }
        if ($tx->getAttribute('status') !== 'pending') {
            throw new Exception('الطلب ده اتعالج بالفعل');
        }

        $tx->setAttribute('status', 'completed');
        $tx->setAttribute('admin_note', $adminNote);
        $tx->setAttribute('approved_by', $adminId);
        $tx->setAttribute('approved_at', date('Y-m-d H:i:s'));
        $tx->save();

        $userId = (int) $tx->getAttribute('user_id');
        if (class_exists('Notification')) {
            Notification::notify($userId, 'wallet_deposit_approved', 'تم شحن رصيدك',
                'تمت الموافقة على إيداعك بمبلغ ' . $tx->getAttribute('amount') . '$ وبقى متاح في محفظتك.', '/subscription');
        }

        ActivityLog::record('wallet', 'wallet.deposit_approved', [
            'user_id' => $adminId, 'subject_type' => 'wallet_transactions', 'subject_id' => $transactionId,
        ]);

        return $tx;
    }

    /** رفض طلب إيداع (لو الفلوس ما وصلتش فعليًا مثلاً) */
    public function rejectDeposit(int $transactionId, int $adminId, string $adminNote = ''): WalletTransaction {
        $tx = (new WalletTransaction())->find($transactionId);
        if (!$tx || $tx->getAttribute('type') !== 'deposit') {
            throw new Exception('طلب الإيداع غير موجود');
        }
        if ($tx->getAttribute('status') !== 'pending') {
            throw new Exception('الطلب ده اتعالج بالفعل');
        }

        $tx->setAttribute('status', 'rejected');
        $tx->setAttribute('admin_note', $adminNote);
        $tx->setAttribute('approved_by', $adminId);
        $tx->setAttribute('approved_at', date('Y-m-d H:i:s'));
        $tx->save();

        ActivityLog::record('wallet', 'wallet.deposit_rejected', [
            'user_id' => $adminId, 'subject_type' => 'wallet_transactions', 'subject_id' => $transactionId,
            'meta' => ['admin_note' => $adminNote],
        ]);

        return $tx;
    }

    /**
     * الاشتراك التلقائي من الرصيد: لو رصيد العميل كافي لسعر الباقة،
     * بنخصم المبلغ فورًا وننشئ الاشتراك مباشرة - من غير أي تدخل بشري
     * أو واتساب. بيرجّع نتيجة واضحة لو الرصيد مش كافي بدل ما يفشل صامت.
     *
     * تصحيح (2026-08-09 / Phase 1): الدالة دي كانت بتُستخدم أيضًا كمسار
     * الترقية/التخفيض الوحيد الشغّال فعليًا (زر "Upgrade" في /subscription
     * كان بيودّي هنا في النهاية عن طريق /plans)، لكنها كانت بتعامل كل
     * استدعاء كأنه اشتراك جديد بالكامل: بتخصم السعر الكامل للباقة الجديدة
     * حتى لو العميل عنده اشتراك فعّال شغال بالفعل، وبتسيب الاشتراك القديم
     * "active" من غير إلغاء - يعني العميل يدفع مرتين ويتراكم عنده أكتر من
     * صف "active" في نفس الوقت.
     *
     * الإصلاح: لو فيه اشتراك فعّال حالي، بنحسب الفرق بين سعر الباقة
     * الجديدة والسعر الفعلي المعروض حاليًا للعميل (نفس القيمة اللي شايفها
     * فعليًا في /subscription)، ونخصم الفرق بس (مش السعر الكامل)، ونلغي
     * الاشتراك القديم قبل ما ننشئ الجديد. الترقية (فرق موجب) لازم تتطلب
     * رصيد كافي زي أي عملية تانية. التخفيض (فرق سالب) بيتم من غير أي رد
     * فلوس تلقائي - سياسة محافظة موثّقة في CHANGELOG، ممكن تتغيّر لاحقًا
     * لو المشروع احتاج استرجاع جزئي حقيقي.
     *
     * @param int $userId
     * @param string $planKey
     * @param string $planType 'monthly' أو 'yearly'
     * @param string|null $idempotencyKey مفتاح فريد اختياري من العميل (مثلاً UUID
     *        مولّد في الفرونت وقت الضغطة) لمنع تكرار الخصم لو الطلب اتبعت
     *        مرتين (دبل-كليك أو إعادة محاولة شبكة). لو مش متبعت، بيتولّد
     *        سيرفر-سايد.
     */
    public function subscribeWithBalance(int $userId, string $planKey, string $planType, ?string $idempotencyKey = null): array {
        $plans = SubscriptionPlan::allAsLegacyArray();
        $plan = $plans[$planKey] ?? null;
        if (!$plan) {
            return ['success' => false, 'error' => 'الباقة غير موجودة'];
        }
        if (!in_array($planType, ['monthly', 'yearly'], true)) {
            return ['success' => false, 'error' => 'نوع الباقة غير معروف'];
        }

        $newPrice = $planType === 'yearly' ? (float) $plan['price_yearly'] : (float) $plan['price_monthly'];
        $idempotencyKey = $idempotencyKey ?: ('plan_change_' . $userId . '_' . (function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16))));

        // لو فيه اشتراك فعّال حالي فعلاً، ده تغيير باقة (upgrade/downgrade)
        // مش اشتراك جديد من الصفر - بنحسب الفرق بس.
        $currentRow = Subscription::activeSubscriptionRow($userId);
        $isPlanChange = $currentRow !== null;
        $oldPrice = $isPlanChange ? (float) $currentRow['price'] : 0.0;
        $chargeAmount = $isPlanChange ? round($newPrice - $oldPrice, 2) : $newPrice;

        $balance = $this->getBalance($userId);

        if ($chargeAmount > 0 && $balance < $chargeAmount) {
            return [
                'success' => false,
                'error' => $isPlanChange ? 'رصيدك الحالي غير كافي لدفع فرق السعر' : 'رصيدك الحالي غير كافي',
                'balance' => $balance,
                'required' => $chargeAmount,
                'shortfall' => round($chargeAmount - $balance, 2),
                'is_plan_change' => $isPlanChange,
            ];
        }

        try {
            return $this->db->transaction(function () use ($userId, $planKey, $planType, $plan, $newPrice, $oldPrice, $chargeAmount, $isPlanChange, $currentRow, $idempotencyKey) {
                // قفل صف المستخدم - نفس تصحيح Section 6 المطبّق في
                // chargeForUsage(): بيمنع سباق بين تغيير باقة وخصم
                // pay-as-you-go متزامنين لنفس العميل. الفحص فوق (قبل
                // دخول الـ transaction) كان بس Fast-fail لتحسين تجربة
                // المستخدم - الفحص الحقيقي المُعتمَد عليه هو ده جوه القفل.
                $this->db->query("SELECT id FROM users WHERE id = ? FOR UPDATE", [$userId]);
                if ($chargeAmount > 0) {
                    $freshBalance = $this->getBalance($userId);
                    if ($freshBalance < $chargeAmount) {
                        // Section 13: إشعار "فشل الدفع" - عند نقطة الفحص
                        // المُعتمَد عليها فعليًا (جوه القفل)، مش الفحص
                        // السريع اللي قبلها (عشان متتكررش الرسالة).
                        if (class_exists('Notification')) {
                            Notification::notify($userId, 'payment_failed', 'تعذّر إتمام الدفع',
                                'رصيدك الحالي مش كافي - جدّد رصيد محفظتك وحاول تاني.', '/subscription');
                        }
                        return [
                            'success' => false,
                            'error' => $isPlanChange ? 'رصيدك الحالي غير كافي لدفع فرق السعر' : 'رصيدك الحالي غير كافي',
                            'balance' => $freshBalance,
                            'required' => $chargeAmount,
                            'shortfall' => round($chargeAmount - $freshBalance, 2),
                            'is_plan_change' => $isPlanChange,
                        ];
                    }
                }

                // خصم الفرق (أو السعر الكامل لو اشتراك جديد) - كحركة مكتملة
                // فورًا. لو الفرق صفر أو سالب (تخفيض)، مفيش حركة محفظة خالص
                // (لا خصم ولا استرجاع تلقائي).
                $chargeTx = null;
                if ($chargeAmount > 0) {
                    $chargeTx = new WalletTransaction();
                    $chargeTx->fill([
                        'user_id' => $userId,
                        'type' => 'subscription_charge',
                        'amount' => -$chargeAmount,
                        'currency' => 'USD',
                        'status' => 'completed',
                        'related_subscription_plan' => $planKey,
                        'approved_at' => date('Y-m-d H:i:s'),
                        'idempotency_key' => $idempotencyKey,
                    ]);
                    $chargeTx->save();
                    $this->checkLowBalanceAndNotify($userId);

                    // Section 3/9: تسجيل موازٍ في سجل payment_transactions
                    // الموحّد - عشان أي استرجاع مستقبلي (RefundService)
                    // يقدر يتعامل مع الخصم ده زي أي معاملة دفع تانية،
                    // بدل ما يكون معزول جوه wallet_transactions بس.
                    // معزولة في try/catch مستقلة تمامًا - فشلها ميلغيش
                    // الخصم أو الاشتراك اللي نجحوا فعلًا.
                    try {
                        $adapter = new WalletGatewayAdapter();
                        $intent = $adapter->createPaymentIntent($userId, $chargeAmount, 'USD', [
                            'plan' => $planKey, 'plan_type' => $planType, 'is_plan_change' => $isPlanChange,
                        ]);
                        $adapter->markSettled($intent['internal_transaction_id'], true, (int) $chargeTx->getAttribute('id'));
                    } catch (Exception $ledgerError) {
                        Logger::error('payment_transactions ledger write failed (charge already succeeded)', [
                            'user_id' => $userId, 'message' => $ledgerError->getMessage(),
                        ]);
                    }
                }

                // تصحيح (2026-08-15 / Phase 17 - Prorated Downgrade Credit):
                // لو التخفيض (chargeAmount سالب) والمنصة فعّلت الرجوع
                // التلقائي، نضيف فرق السعر رصيد موجبة لمحفظة العميل.
                // مقفول افتراضيًا (ALLOW_PRORATED_DOWNGRADE_CREDIT = false)
                // - قرار مالي بياخده مالك المنصة مش الكود.
                if ($chargeAmount < 0 && self::ALLOW_PRORATED_DOWNGRADE_CREDIT) {
                    $creditAmount = abs($chargeAmount);
                    $creditTx = new WalletTransaction();
                    $creditTx->fill([
                        'user_id' => $userId,
                        'type' => 'subscription_credit',
                        'amount' => $creditAmount,
                        'currency' => 'USD',
                        'status' => 'completed',
                        'reference_note' => 'رصيد فرق تخفيض الباقة من "' . $plan['name'] . '"',
                        'approved_at' => date('Y-m-d H:i:s'),
                        'idempotency_key' => $idempotencyKey,
                    ]);
                    $creditTx->save();

                    ActivityLog::record('wallet', 'wallet.downgrade_credited', [
                        'user_id' => $userId,
                        'subject_type' => 'wallet_transactions',
                        'subject_id' => (int) $creditTx->getAttribute('id'),
                        'meta' => [
                            'plan' => $planKey, 'type' => $planType,
                            'old_price' => $oldPrice, 'new_price' => $newPrice, 'credited' => $creditAmount,
                        ],
                    ]);

                    if (class_exists('Notification')) {
                        Notification::notify($userId, 'wallet_downgrade_credit', 'اتضاف رصيد لمحفظتك',
                            'بسبب تخفيض باقتك لـ "' . $plan['name'] . '"، اتضاف فرق السعر ' . $creditAmount . '$ لرصيد محفظتك.', '/subscription');
                    }
                }

                // لو ده تغيير باقة، نلغي الاشتراك القديم أولاً عشان ميفضلش
                // صف "active" يتيم من غير ما يتحسب في أي مكان.
                if ($isPlanChange && !empty($currentRow['id'])) {
                    $this->db->exec("UPDATE `subscriptions` SET `status` = 'cancelled' WHERE `id` = ?", [(int) $currentRow['id']]);
                }

                // إنشاء الاشتراك الفعلي - نفس الدالة الحقيقية اللي بينده عليها
                // الأدمن وقت التفعيل اليدوي، عشان يبقى نفس المسار المضمون.
                $subscription = Subscription::createSubscription($userId, $planKey, $planType);
                if (!$subscription) {
                    throw new Exception('تعذر إنشاء الاشتراك الجديد بعد الخصم');
                }

                // تصحيح حرج (2026-08-10 / Phase 13): لحد دلوقتي مفيش أي كود
                // في المشروع كله بيكتب فعليًا في جدول invoices - فيه دالة
                // BillingManager::createInvoice() جاهزة بس مش متصلة بأي
                // مسار حقيقي، يعني كل اشتراك بيتشحن من المحفظة كان بيحصل
                // من غير أي فاتورة تتسجل خالص. بننشئ الفاتورة هنا مباشرة
                // بحالة 'paid' فورًا (الدفع نفسه خلص فعليًا لحظة خصم
                // المحفظة فوق) - مش 'pending' زي التدفق القديم المعطّل
                // اللي كان مفترض بوابة دفع خارجية.
                //
                // معزولة في try/catch مستقلة عن قصد: لو أي مفاجأة في شكل
                // الجدول الحقيقي (زي اللي اكتشفناها قبل كده في جدول
                // subscriptions)، فشل إنشاء الفاتورة لازم ميرجّعش الخصم
                // والاشتراك اللي نجحوا فعلاً - العميل مايتضررش من باگ في
                // جزء تاني.
                $this->createInvoiceForCharge($userId, $planKey, $plan, $planType, $chargeAmount, $chargeTx);

                ActivityLog::record('wallet', $isPlanChange ? 'wallet.plan_changed' : 'wallet.auto_subscribed', [
                    'user_id' => $userId,
                    'subject_type' => 'wallet_transactions',
                    'subject_id' => $chargeTx ? (int) $chargeTx->getAttribute('id') : null,
                    'meta' => [
                        'plan' => $planKey, 'type' => $planType,
                        'old_price' => $oldPrice, 'new_price' => $newPrice, 'charged' => $chargeAmount,
                    ],
                ]);

                if (class_exists('Notification')) {
                    $msg = $isPlanChange
                        ? ($chargeAmount > 0
                            ? 'تم خصم فرق السعر ' . $chargeAmount . '$ من محفظتك وتفعيل باقة "' . $plan['name'] . '" فورًا.'
                            : 'تم تفعيل باقة "' . $plan['name'] . '" فورًا من غير أي خصم إضافي.')
                        : 'تم خصم ' . $chargeAmount . '$ من محفظتك وتفعيل باقة "' . $plan['name'] . '" فورًا.';
                    Notification::notify($userId, 'subscription_activated', 'تم تفعيل اشتراكك', $msg, '/subscription');
                }

                return [
                    'success' => true,
                    'subscription' => $subscription,
                    'new_balance' => $this->getBalance($userId),
                    'charged' => $chargeAmount,
                    'is_plan_change' => $isPlanChange,
                ];
            });
        } catch (Exception $e) {
            // مفتاح idempotency مكرر = نفس الطلب اتبعت قبل كده ونفّذ بنجاح -
            // نرجّع النتيجة الحالية بدل ما نخصم تاني أو نرجّع خطأ مربك.
            if (strpos($e->getMessage(), 'idempotency_key') !== false || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                Logger::warning('subscribeWithBalance duplicate idempotency key - returning current state', ['user_id' => $userId, 'idempotency_key' => $idempotencyKey]);
                return [
                    'success' => true,
                    'subscription' => Subscription::activeSubscriptionRow($userId),
                    'new_balance' => $this->getBalance($userId),
                    'duplicate_request' => true,
                ];
            }

            Logger::error('subscribeWithBalance Error', ['user_id' => $userId, 'message' => $e->getMessage()]);
            return ['success' => false, 'error' => 'تعذر إنشاء الاشتراك - تواصل مع الدعم الفني'];
        }
    }

    /** كل حركات محفظة مستخدم معيّن (لعرضها في صفحة الاشتراك) */
    public function getHistory(int $userId, int $limit = 30): array {
        return (new WalletTransaction())->where(['user_id' => $userId], ['created_at' => 'DESC'], $limit);
    }

    /**
     * الأدمن يضيف رصيد مباشر لعميل معيّن - بيتسجّل "مكتمل" فورًا (مفيش
     * موافقة لازمة، الأدمن نفسه هو اللي بيوافق بالفعل بضغطة الزرار).
     */
    public function adminAddBalance(int $userId, float $amount, int $adminId, string $note = ''): WalletTransaction {
        if ($amount <= 0) {
            throw new Exception('المبلغ لازم يكون أكبر من صفر');
        }

        $tx = new WalletTransaction();
        $tx->fill([
            'user_id' => $userId,
            'type' => 'admin_adjustment',
            'amount' => $amount,
            'currency' => 'USD',
            'status' => 'completed',
            'payment_method' => 'admin',
            'reference_note' => $note ?: 'إضافة رصيد يدوية من الأدمن',
            'approved_by' => $adminId,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);
        $tx->save();

        return $tx;
    }

    /**
     * توليد دفعة بطاقات شحن جديدة - كل بطاقة بكود فريد بصيغة سهلة
     * القراءة (زي أمازون بالظبط: TRFC-XXXX-XXXX-XXXX).
     */
    public function generateRechargeCards(int $count, float $value, int $adminId, string $batchLabel = ''): array {
        if ($count <= 0 || $count > 500) {
            throw new Exception('عدد البطاقات لازم يكون بين 1 و 500');
        }
        if ($value <= 0) {
            throw new Exception('قيمة البطاقة لازم تكون أكبر من صفر');
        }

        $cards = [];
        for ($i = 0; $i < $count; $i++) {
            $code = $this->generateUniqueCardCode();
            $card = new WalletRechargeCard();
            $card->fill([
                'code' => $code,
                'value' => $value,
                'status' => 'unused',
                'batch_label' => $batchLabel ?: date('Y-m-d H:i'),
                'created_by_admin_id' => $adminId,
            ]);
            $card->save();
            $cards[] = $card->toArray();
        }

        return $cards;
    }

    private function generateUniqueCardCode(): string {
        do {
            $code = 'TRFC-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
            $exists = !empty((new WalletRechargeCard())->where(['code' => $code], [], 1));
        } while ($exists);
        return $code;
    }

    /**
     * العميل يشحن بطاقة - بيتأكد إن الكود صحيح ومستخدمش قبل كده،
     * ويضيف قيمتها لرصيد محفظته فورًا.
     */
    public function redeemCard(int $userId, string $code): array {
        $code = strtoupper(trim($code));
        $rows = (new WalletRechargeCard())->where(['code' => $code], [], 1);

        if (empty($rows)) {
            throw new Exception('كود البطاقة غير صحيح');
        }

        $card = $rows[0];
        if ($card->getAttribute('status') === 'used') {
            throw new Exception('البطاقة دي اتشحنت قبل كده');
        }

        $value = (float) $card->getAttribute('value');

        $tx = new WalletTransaction();
        $tx->fill([
            'user_id' => $userId,
            'type' => 'card_redemption',
            'amount' => $value,
            'currency' => 'USD',
            'status' => 'completed',
            'payment_method' => 'recharge_card',
            'reference_note' => 'شحن بطاقة: ' . $code,
        ]);
        $tx->save();

        $card->setAttribute('status', 'used');
        $card->setAttribute('used_by_user_id', $userId);
        $card->setAttribute('used_at', date('Y-m-d H:i:s'));
        $card->save();

        return ['value' => $value, 'new_balance' => $this->getBalance($userId)];
    }

    /** كل طلبات الإيداع المعلّقة (لعرضها في لوحة الأدمن) */
    public function getPendingDeposits(): array {
        return (new WalletTransaction())->where(['type' => 'deposit', 'status' => 'pending'], ['created_at' => 'ASC']);
    }

    /**
     * إحصائيات مجمّعة للمحفظة (لوحة الأدمن): إجمالي الإيداعات المعتمدة
     * هذا الشهر، عدد الطلبات المعلّقة، إجمالي أرصدة كل العملاء، وإجمالي
     * خصومات "ادفع حسب الاستخدام" هذا الشهر.
     */
    public function getAdminStats(): array {
        try {
            $depositsThisMonth = $this->db->query(
                "SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS count FROM wallet_transactions
                 WHERE type = 'deposit' AND status = 'completed' AND MONTH(approved_at) = MONTH(NOW()) AND YEAR(approved_at) = YEAR(NOW())"
            );
            $pendingCount = $this->db->query(
                "SELECT COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total FROM wallet_transactions WHERE type = 'deposit' AND status = 'pending'"
            );
            $totalBalances = $this->db->query(
                "SELECT COALESCE(SUM(amount), 0) AS total FROM wallet_transactions WHERE status = 'completed'"
            );
            $usageChargesThisMonth = $this->db->query(
                "SELECT COALESCE(SUM(ABS(amount)), 0) AS total, COUNT(*) AS count FROM wallet_transactions
                 WHERE type = 'subscription_charge' AND status = 'completed' AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())"
            );

            $billing = $this->getBillingAnalytics();
            $this->snapshotTodayMetricsIfNeeded($billing);

            return array_merge([
                'deposits_this_month' => (float) ($depositsThisMonth[0]['total'] ?? 0),
                'deposits_this_month_count' => (int) ($depositsThisMonth[0]['count'] ?? 0),
                'pending_count' => (int) ($pendingCount[0]['count'] ?? 0),
                'pending_total' => (float) ($pendingCount[0]['total'] ?? 0),
                'total_customer_balances' => (float) ($totalBalances[0]['total'] ?? 0),
                'usage_charges_this_month' => (float) ($usageChargesThisMonth[0]['total'] ?? 0),
                'usage_charges_this_month_count' => (int) ($usageChargesThisMonth[0]['count'] ?? 0),
            ], $billing);
        } catch (Exception $e) {
            Logger::error('WalletService getAdminStats Error', ['message' => $e->getMessage()]);
            return array_merge([
                'deposits_this_month' => 0, 'deposits_this_month_count' => 0, 'pending_count' => 0,
                'pending_total' => 0, 'total_customer_balances' => 0, 'usage_charges_this_month' => 0,
                'usage_charges_this_month_count' => 0,
            ], $this->getBillingAnalytics());
        }
    }

    /**
     * Phase 10: مفيش cron job في المشروع نعتمد عليه لتسجيل لقطة يومية،
     * فبدل ما نخترع نظام جدولة جديد، بنسجّل لقطة اليوم "بشكل كسول" أول
     * مرة حد (أدمن) يفتح إحصائيات الفوترة في اليوم ده. لو اليوم مسجّل
     * بالفعل، الاستعلام بيتجاهل التكرار (ON DUPLICATE KEY).
     */
    private function snapshotTodayMetricsIfNeeded(array $billing): bool {
        if ($billing['mrr'] === null) {
            return false; // مفيش بيانات كافية أصلاً - متسجّلش لقطة مضلّلة
        }
        try {
            $this->db->exec(
                "INSERT INTO billing_metrics_snapshots (snapshot_date, mrr, arr, active_subscriptions)
                 VALUES (CURDATE(), ?, ?, ?)
                 ON DUPLICATE KEY UPDATE mrr = VALUES(mrr), arr = VALUES(arr), active_subscriptions = VALUES(active_subscriptions)",
                [$billing['mrr'], $billing['arr'], $billing['active_subscriptions']]
            );
            return true;
        } catch (Exception $e) {
            Logger::error('snapshotTodayMetricsIfNeeded failed', ['message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * تحليل تنافسي (Stripe/Chargebee/Paddle): "الإيراد لكل ميزة" -
     * تفصيل إيراد "ادفع حسب الاستخدام" الشهري لكل ميزة على حدة، مع
     * إجمالي عدد مرات الاستخدام. العمود feature_key بيتعبى من Phase 17
     * (كانت الميزات بتختفي قبل كده). لو صفوف قديمة مفيهاش feature_key
     * (NULL)، بتتجمع تحت مفتاح '_unmapped' عشان ميحصلش فقد صامت للبيانات.
     */
    public function getUsageRevenueBreakdown(?int $year = null, ?int $month = null): array {
        $year = $year ?: (int) date('Y');
        $month = $month ?: (int) date('n');
        try {
            $rows = $this->db->query(
                "SELECT COALESCE(feature_key, '_unmapped') AS feature_key,
                        COUNT(*) AS usage_count,
                        COALESCE(SUM(ABS(amount)), 0) AS revenue
                 FROM wallet_transactions
                 WHERE type = 'subscription_charge' AND status = 'completed'
                   AND feature_key IS NOT NULL
                   AND YEAR(created_at) = ? AND MONTH(created_at) = ?
                 GROUP BY feature_key
                 ORDER BY revenue DESC",
                [$year, $month]
            );
            // الصفوف القديمة (feature_key = NULL) - بنعدّها من reference_note
            // بس مجموعها الكلي، عشان النقطة المؤقتة دي متضيعش إيراد.
            $legacy = $this->db->query(
                "SELECT COUNT(*) AS usage_count, COALESCE(SUM(ABS(amount)), 0) AS revenue
                 FROM wallet_transactions
                 WHERE type = 'subscription_charge' AND status = 'completed'
                   AND feature_key IS NULL
                   AND YEAR(created_at) = ? AND MONTH(created_at) = ?",
                [$year, $month]
            );

            $breakdown = [];
            foreach ($rows as $row) {
                $breakdown[$row['feature_key']] = [
                    'usage_count' => (int) $row['usage_count'],
                    'revenue' => round((float) $row['revenue'], 2),
                ];
            }
            if (!empty($legacy) && ((int) $legacy[0]['usage_count']) > 0) {
                $breakdown['_legacy_unmapped'] = [
                    'usage_count' => (int) $legacy[0]['usage_count'],
                    'revenue' => round((float) $legacy[0]['revenue'], 2),
                ];
            }

            return [
                'year' => $year,
                'month' => $month,
                'total_revenue' => round(array_sum(array_column($breakdown, 'revenue')), 2),
                'total_usage_count' => (int) array_sum(array_column($breakdown, 'usage_count')),
                'breakdown' => $breakdown,
            ];
        } catch (Exception $e) {
            Logger::error('getUsageRevenueBreakdown failed', ['message' => $e->getMessage()]);
            return ['year' => $year, 'month' => $month, 'total_revenue' => 0.0, 'total_usage_count' => 0, 'breakdown' => []];
        }
    }

    /**
     * Phase 10: تاريخ MRR/ARR الحقيقي لآخر N يوم - من اللقطات اللي
     * اتسجّلت فعليًا (lazy snapshot أعلاه). لو المنتج جديد ومفيش لقطات
     * كتير لسه، هترجع مصفوفة قصيرة بس - ده طبيعي ومش خطأ (البيانات
     * بتتكوّن تدريجيًا، مفيش تاريخ ملفّق قبل أول استخدام فعلي للصفحة).
     */
    public function getMrrTrend(int $days = 30): array {
        try {
            $rows = $this->db->query(
                "SELECT snapshot_date, mrr, arr, active_subscriptions FROM billing_metrics_snapshots
                 WHERE snapshot_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                 ORDER BY snapshot_date ASC",
                [$days]
            );
            return $rows ?: [];
        } catch (Exception $e) {
            Logger::error('getMrrTrend failed', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Phase 8: MRR/ARR وباقي مقاييس الاشتراكات - محسوبة من الجداول
     * الحقيقية (subscriptions + subscription_plans، محرك الفوترة الفعلي
     * اللي اكتُشف في Phase 1)، مش من أي جدول إحصائيات وهمي. كل استعلام
     * معزول بـ try/catch مستقل (زي نمط getPlatformOverview() الموجود في
     * AdminController) عشان لو جدول واحد فيه مشكلة، باقي الأرقام تفضل
     * شغالة.
     *
     * ملحوظة منهجية (مهم): churn_rate_this_month تقريبي - بيستخدم
     * updated_at كبديل لعمود "تاريخ الإلغاء" غير الموجود فعليًا في
     * الجدول الحقيقي. لو فيه تعديلات تانية على نفس الصف (مش إلغاء) في
     * نفس الشهر، الرقم ممكن يبقى أعلى شوية من الحقيقي - محدود التأثير
     * لأن الصف بيتحدّث غالبًا وقت الإلغاء بس فعليًا حسب الكود الحالي.
     */
    private function getBillingAnalytics(): array {
        $safe = function (string $sql) {
            try {
                $rows = $this->db->query($sql);
                return $rows[0] ?? [];
            } catch (Exception $e) {
                Logger::error('getBillingAnalytics query failed', ['message' => $e->getMessage()]);
                return null;
            }
        };

        $mrrRow = $safe(
            "SELECT COALESCE(SUM(CASE WHEN sp.billing_cycle = 'yearly' THEN sp.price / 12 ELSE sp.price END), 0) AS mrr,
                    COUNT(*) AS active_count
             FROM subscriptions s
             JOIN subscription_plans sp ON sp.id = s.plan_id
             WHERE s.status = 'active' AND s.current_period_end > NOW()"
        );
        $newThisMonth = $safe(
            "SELECT COUNT(*) AS count FROM subscriptions
             WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())"
        );
        $cancelledThisMonth = $safe(
            "SELECT COUNT(*) AS count FROM subscriptions
             WHERE status = 'cancelled' AND MONTH(updated_at) = MONTH(NOW()) AND YEAR(updated_at) = YEAR(NOW())"
        );

        if ($mrrRow === null || $newThisMonth === null || $cancelledThisMonth === null) {
            // فشل استعلام أساسي - أفضل نرجع "مفيش بيانات كافية" بدل ما
            // نعرض رقم جزئي ممكن يكون مضلّل.
            return [
                'mrr' => null, 'arr' => null, 'active_subscriptions' => null,
                'new_subscriptions_this_month' => null, 'cancelled_this_month' => null,
                'churn_rate_this_month' => null, 'average_revenue_per_subscription' => null,
            ];
        }

        $mrr = (float) ($mrrRow['mrr'] ?? 0);
        $activeCount = (int) ($mrrRow['active_count'] ?? 0);
        $cancelled = (int) ($cancelledThisMonth['count'] ?? 0);
        $churnDenominator = $activeCount + $cancelled;

        return [
            'mrr' => round($mrr, 2),
            'arr' => round($mrr * 12, 2),
            'active_subscriptions' => $activeCount,
            'new_subscriptions_this_month' => (int) ($newThisMonth['count'] ?? 0),
            'cancelled_this_month' => $cancelled,
            // null صراحةً لو مفيش قاعدة مقارنة (Not enough data) بدل ما نخترع 0%.
            'churn_rate_this_month' => $churnDenominator > 0 ? round(($cancelled / $churnDenominator) * 100, 1) : null,
            'average_revenue_per_subscription' => $activeCount > 0 ? round($mrr / $activeCount, 2) : null,
        ];
    }

    /** بيانات الدفع (IBAN/PayPal) القابلة للتعديل من لوحة الأدمن */
    public function getPaymentSettings(): array {
        try {
            $rows = $this->db->query("SELECT setting_key, setting_value FROM wallet_payment_settings");
        } catch (Exception $e) {
            return [];
        }
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    /** تحديث إعداد دفع واحد من لوحة الأدمن */
    public function updatePaymentSetting(string $key, string $value): void {
        $this->db->exec(
            "INSERT INTO wallet_payment_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [$key, $value]
        );
    }

    // ============================================
    // ادفع حسب الاستخدام (Pay-As-You-Go)
    // العميل من غير اشتراك (أو اللي خلّص حد باقته) يقدر يستخدم أي ميزة
    // وتتخصم تلقائيًا من رصيد محفظته بدل ما يتمنع خالص.
    // ============================================

    /** كل أسعار الاستخدام الفردي النشطة، مفهرسة بمفتاح الميزة */
    public function getUsagePricing(): array {
        try {
            $rows = $this->db->query("SELECT * FROM pay_per_use_pricing WHERE is_active = 1");
        } catch (Exception $e) {
            return [];
        }
        $pricing = [];
        foreach ($rows as $row) {
            $pricing[$row['feature_key']] = $row;
        }
        return $pricing;
    }

    /** فحص: هل رصيد العميل كافي لاستخدام ميزة معيّنة مرة واحدة؟ */
    public function canAffordUsage(int $userId, string $featureKey): array {
        $pricing = $this->getUsagePricing();
        $price = $pricing[$featureKey] ?? null;

        if (!$price) {
            return ['can_afford' => false, 'reason' => 'الميزة دي مش متاحة كـ"ادفع حسب الاستخدام" حاليًا'];
        }

        $balance = $this->getBalance($userId);
        $unitPrice = (float) $price['price'];

        if ($balance < $unitPrice) {
            return [
                'can_afford' => false, 'reason' => 'رصيدك غير كافي', 'balance' => $balance,
                'required' => $unitPrice, 'shortfall' => round($unitPrice - $balance, 2),
            ];
        }

        return ['can_afford' => true, 'balance' => $balance, 'price' => $unitPrice, 'currency_symbol' => $price['currency_symbol']];
    }

    /**
     * خصم فعلي لثمن استخدام ميزة واحدة من المحفظة. بترجع false لو الرصيد
     * مش كافي (بدل ما تخصم قيمة سالبة/تدّي رصيد بالسالب بالغلط).
     *
     * تصحيح حرج (2026-08-12 / Phase 14 - Atomic Pay-As-You-Go): كانت
     * الدالة دي بتقرأ الرصيد ثم تكتب الخصم في خطوتين منفصلتين من غير
     * أي قفل أو Transaction - يعني طلبين متزامنين لنفس العميل (زي مثال
     * Balance=100 وطلبين بـ80) كانوا يقدروا الاتنين يعدّوا فحص "الرصيد
     * كافي" في نفس اللحظة قبل ما أي واحد يكتب، فيتم خصم الاتنين ويوصل
     * الرصيد لسالب. دلوقتي العملية كلها جوه Database Transaction، مع
     * قفل صف المستخدم نفسه (SELECT ... FOR UPDATE) - أي طلب تاني لنفس
     * العميل بيستنى لحد ما القفل يتفك (يعني بعد ما أول عملية تخلص وتلتزم
     * فعليًا)، فيعيد حساب الرصيد الحقيقي المُحدَّث ويرفض لو مبقاش كافي -
     * بدل ما ينفّذ عمليتين مش المفروض تتنفّذ الاتنين مع بعض.
     *
     * @param string|null $idempotencyKey مفتاح فريد اختياري لمنع تكرار
     *        نفس الخصم لو نفس الطلب اتبعت مرتين (شبكة/دبل-كليك).
     */
    public function chargeForUsage(int $userId, string $featureKey, string $note = '', ?string $idempotencyKey = null): bool {
        $pricing = $this->getUsagePricing();
        $price = $pricing[$featureKey] ?? null;
        if (!$price) {
            return false;
        }
        $unitPrice = (float) $price['price'];

        try {
            return $this->db->transaction(function () use ($userId, $featureKey, $unitPrice, $note, $idempotencyKey) {
                // قفل صف المستخدم - أي عملية خصم تانية لنفس اليوزر (سواء
                // usage charge أو subscribe/upgrade) بتتسلسل خلف القفل ده
                // بدل ما تتنافس على قراءة رصيد قديم.
                $this->db->query("SELECT id FROM users WHERE id = ? FOR UPDATE", [$userId]);

                if ($idempotencyKey) {
                    $existing = $this->db->query(
                        "SELECT id FROM wallet_transactions WHERE idempotency_key = ? LIMIT 1",
                        [$idempotencyKey]
                    );
                    if (!empty($existing)) {
                        return true; // نفس الطلب اتنفّذ قبل كده - مش خطأ، بس منفّذوش تاني
                    }
                }

                // إعادة حساب الرصيد جوه القفل - ده الرصيد الحقيقي المضمون
                // دلوقتي، مش اللي اتقرا قبل الدخول في الـ transaction.
                $balance = $this->getBalance($userId);
                if ($balance < $unitPrice) {
                    return false;
                }

                $tx = new WalletTransaction();
                $tx->fill([
                    'user_id' => $userId,
                    'type' => 'subscription_charge',
                    'amount' => -$unitPrice,
                    'currency' => 'USD',
                    'status' => 'completed',
                    'reference_note' => $note ?: $featureKey,
                    // Phase 17: feature_key لتحليل "الإيراد لكل ميزة" -
                    // كان بيختفي وبيتحفظ Arabic label بس في reference_note.
                    'feature_key' => $featureKey,
                    'approved_at' => date('Y-m-d H:i:s'),
                    'idempotency_key' => $idempotencyKey,
                ]);
                $tx->save();
                $this->checkLowBalanceAndNotify($userId);

                return true;
            });
        } catch (Exception $e) {
            Logger::error('chargeForUsage failed', ['user_id' => $userId, 'feature' => $featureKey, 'message' => $e->getMessage()]);
            return false;
        }
    }

    /** كل أسعار الاستخدام (نشطة وغير نشطة) - لعرضها وتعديلها في لوحة الأدمن */
    public function getAllUsagePricingForAdmin(): array {
        try {
            return $this->db->query("SELECT * FROM pay_per_use_pricing ORDER BY id ASC");
        } catch (Exception $e) {
            return [];
        }
    }

    /** تحديث سعر استخدام ميزة واحدة من لوحة الأدمن */
    public function updateUsagePricing(int $id, float $price, bool $isActive): void {
        $this->db->exec(
            "UPDATE pay_per_use_pricing SET price = ?, is_active = ? WHERE id = ?",
            [$price, $isActive ? 1 : 0, $id]
        );
    }

    /** حد "رصيد منخفض" - تحت الرقم ده بيتبعت تنبيه (مرة واحدة كل يوم بالكتير) */
    private const LOW_BALANCE_THRESHOLD = 10.0;

    /**
     * هل نرجع رصيد تلقائي للعميل عند التخفيض (downgrade)؟
     *
     * تحليل تنافسي (Stripe Billing / Chargebee / Paddle): المنصات العالمية
     * العالمية بتعمل prorated credit تلقائي عند تغيير الباقة لأسفل -
     * العميل بياخد فرق السعر عن الأيام المتبقية من الفترة الحالية رصيد
     * في محفظته بدل ما يخسر فلوسه.
     *
     * ❌ قيمتها حاليًا false (سياسة محافظة مستمرة من قبل): التخفيض
     * بيفعّل الباقة الجديدة من غير أي استرجاع تلقائي. ده قرار مالي
     * حقيقي بيأثر على إيراد المنصة، فمش هيتفعّل في الكود إلا بقرار
     * صريح من مالك المنصة - غيّر القيمة لـ true هنا لما تقرر كده.
     *
     * ⚠️ ملحوظة تقنية: المبلغ المرتجع هنا هو فرق السعر الكامل
     * (old - new) مش "pro-rated" حرفيًا على الأيام المتبقية - لأن
     * التسعير الحالي بيخصم الفرق الكامل عند الترقية برضه (متماثل
     * الاتجاهين). لو احتجناهم pro-rating حقيقي بدقة على اليوم، ده
     * بيتطلب تخزين تاريخ بداية الفترة (متاح فعلًا في subscriptions
     * كـ current_period_start) ويبقى تعديل منفصل موثّق.
     */
    private const ALLOW_PRORATED_DOWNGRADE_CREDIT = false;

    /**
     * Section 13: تنبيه "رصيد منخفض" - بيتنده بعد أي خصم ناجح (استخدام
     * فردي أو اشتراك). Dedup مرة واحدة في اليوم لكل عميل (نفس نمط
     * تذكير التجديد) عشان ميبقاش إشعار مزعج على كل عملية صغيرة تحت الحد.
     */
    private function checkLowBalanceAndNotify(int $userId): void {
        try {
            $balance = $this->getBalance($userId);
            if ($balance >= self::LOW_BALANCE_THRESHOLD) {
                return;
            }

            $today = date('Y-m-d');
            $already = $this->db->query(
                "SELECT id FROM activity_logs WHERE module = 'wallet' AND action = 'wallet.low_balance_notified'
                 AND user_id = ? AND DATE(created_at) = ? LIMIT 1",
                [$userId, $today]
            );
            if (!empty($already)) {
                return;
            }

            if (class_exists('Notification')) {
                Notification::notify($userId, 'wallet_low_balance', 'رصيد محفظتك منخفض',
                    'رصيدك الحالي $' . number_format($balance, 2) . ' - جدّده عشان متتأثرش خدماتك.', '/subscription');
            }
            ActivityLog::record('wallet', 'wallet.low_balance_notified', [
                'user_id' => $userId, 'meta' => ['balance' => $balance],
            ]);
        } catch (Exception $e) {
            Logger::error('checkLowBalanceAndNotify failed', ['user_id' => $userId, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Phase 13: ينشئ فاتورة 'paid' فعلية فور نجاح خصم المحفظة - أول مرة
     * جدول invoices بيتكتب فيه فعليًا في المشروع كله (كان بيتقرأ بس).
     *
     * ⚠️ ملحوظة مهمة قبل التشغيل على الإنتاج: أعمدة الجدول هنا مبنية
     * على database/schema.sql (المرجع الوحيد المتاح لي وقت الكتابة).
     * سبق واكتشفنا في تدقيق سابق إن جدول subscriptions الحقيقي في
     * السيرفر مختلف تمامًا عن اللي في schema.sql - نفس الاحتمال وارد
     * هنا لجدول invoices. عشان كده الكود ده معزول في try/catch مستقل
     * تمامًا عن عملية الخصم والاشتراك: لو فشل (عمود مش موجود، مثلاً)،
     * الخصم والاشتراك يفضلوا ناجحين والعميل مايتأثرش، بس السجل بيتسجل
     * في الـ Logger عشان تلاحظه وتظبط الأعمدة لو مختلفة فعليًا.
     *
     * لازم تتأكد من أعمدة الجدول الحقيقية أول Deployment (SHOW COLUMNS
     * FROM invoices;) وتظبط القائمة تحت لو فيه فرق.
     */
    private function createInvoiceForCharge(int $userId, string $planKey, array $plan, string $planType, float $amount, ?WalletTransaction $chargeTx): void {
        try {
            $invoiceNumber = 'INV-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $cycleLabel = $planType === 'yearly' ? 'سنوي' : 'شهري';
            $items = json_encode([[
                'description' => ($plan['name'] ?? $planKey) . ' - ' . $cycleLabel,
                'amount' => $amount,
                'quantity' => 1,
            ]], JSON_UNESCAPED_UNICODE);

            // Section 12: ضريبة معلوماتية بس - من دولة العميل الحقيقية
            // في billing_profile لو موجودة. مفيش نسبة افتراضية، ومفيش
            // إضافة تلقائية لمبلغ amount المخصوم فعليًا (شوف تعليق
            // migration الأعمدة). لو فشل الاستعلام لأي سبب، الفاتورة
            // لسه بتتعمل عادي من غير بيانات ضريبة.
            $taxCountry = null;
            $taxType = null;
            $taxAmount = null;
            try {
                if (class_exists('BillingProfile') && class_exists('TaxService')) {
                    $profile = BillingProfile::forUser($userId);
                    $country = $profile ? $profile->getAttribute('country') : null;
                    if ($country) {
                        $tax = (new TaxService())->computeTax($amount, $country);
                        if ($tax['configured']) {
                            $taxCountry = $tax['country_code'];
                            $taxType = $tax['tax_type'];
                            $taxAmount = $tax['tax_amount'];
                        }
                    }
                }
            } catch (Exception $taxError) {
                Logger::error('Invoice tax lookup failed (invoice still created without tax data)', ['message' => $taxError->getMessage()]);
            }

            $this->db->exec(
                "INSERT INTO invoices
                    (user_id, invoice_number, plan_name, plan_type, amount, subtotal, tax_country, tax_type, tax_amount,
                     currency, status, payment_method, transaction_id, items, due_date, paid_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'USD', 'paid', 'wallet', ?, ?, CURDATE(), NOW(), NOW())",
                [
                    $userId, $invoiceNumber, ($plan['name'] ?? $planKey), $planType, $amount, $amount,
                    $taxCountry, $taxType, $taxAmount,
                    $chargeTx ? 'wallet_tx_' . $chargeTx->getAttribute('id') : 'wallet_no_charge',
                    $items,
                ]
            );
        } catch (Exception $e) {
            // مقصود: فشل إنشاء الفاتورة ميلغيش الخصم أو الاشتراك اللي
            // نجحوا فعلاً. بس المشكلة لازم تتسجل بوضوح عشان تتراجع
            // وتظبط أعمدة الجدول الحقيقية لو مختلفة عن schema.sql.
            Logger::error('createInvoiceForCharge failed - subscription/charge succeeded but no invoice was recorded', [
                'user_id' => $userId, 'plan' => $planKey, 'amount' => $amount, 'message' => $e->getMessage(),
            ]);
        }
    }
}
