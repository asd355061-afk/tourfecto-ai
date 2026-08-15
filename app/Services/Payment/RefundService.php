<?php

/**
 * Tourfecto - Refund Service
 * @version 1.0.0
 * @date 2026-08-12
 *
 * ينسّق عملية الاسترجاع: يتحقق من المبلغ ضد معاملة دفع حقيقية، يفوّض
 * التنفيذ الفعلي لأداة الدفع نفسها (Gateway Adapter) - أول تنفيذ حقيقي
 * هو WalletGatewayAdapter - وميعتبرش النجاح مضمون قبل ما يرجع رد فعلي.
 * دايمًا بيحدّث حالة refunds و payment_transactions من النتيجة
 * الحقيقية بس، مش افتراض.
 */
class RefundService
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * @param int $paymentTransactionId معاملة الدفع الأصلية المطلوب استرجاعها
     * @param float $amount المبلغ المطلوب استرجاعه (كامل أو جزء من قيمة المعاملة)
     */
    public function createRefund(int $paymentTransactionId, float $amount, string $reason, int $adminId): array
    {
        $ptx = (new PaymentTransaction())->find($paymentTransactionId);
        if (!$ptx) {
            return ['success' => false, 'error' => 'معاملة الدفع غير موجودة'];
        }
        if (!in_array($ptx->getAttribute('status'), ['succeeded', 'partially_refunded'], true)) {
            return ['success' => false, 'error' => 'المعاملة دي مش في حالة تسمح بالاسترجاع'];
        }
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'المبلغ لازم يكون أكبر من صفر'];
        }

        $originalAmount = (float) $ptx->getAttribute('amount');
        $alreadyRefunded = $this->totalRefundedFor($paymentTransactionId);
        $remaining = round($originalAmount - $alreadyRefunded, 2);

        if ($amount > $remaining) {
            return ['success' => false, 'error' => "أقصى مبلغ ممكن استرجاعه دلوقتي هو {$remaining}$ (اتسترجع {$alreadyRefunded}$ قبل كده)"];
        }

        $refund = new Refund();
        $refund->fill([
            'payment_transaction_id' => $paymentTransactionId,
            'user_id' => (int) $ptx->getAttribute('user_id'),
            'amount' => $amount,
            'currency' => (string) $ptx->getAttribute('currency'),
            'reason' => $reason,
            'status' => 'processing',
            'created_by_admin_id' => $adminId,
        ]);
        $refund->save();

        // تنفيذ فعلي عبر أداة الدفع الحقيقية اللي اتعمل بيها الدفع
        // الأصلي (المحفظة حاليًا - مفيش بوابة تانية مفعّلة).
        $gateway = $this->resolveGatewayFor((string) $ptx->getAttribute('gateway'));
        if (!$gateway) {
            $refund->setAttribute('status', 'failed');
            $refund->save();
            return ['success' => false, 'error' => 'بوابة الدفع الأصلية غير مدعومة للاسترجاع حاليًا'];
        }

        $result = $gateway->refund((string) $ptx->getAttribute('internal_transaction_id'), $amount, $reason);

        if ($result['success']) {
            $refund->setAttribute('status', 'succeeded');
            $refund->setAttribute('gateway_refund_reference', $result['gateway_refund_id']);
            $refund->save();

            $newTotal = $alreadyRefunded + $amount;
            $ptx->setAttribute('status', $newTotal >= $originalAmount ? 'refunded' : 'partially_refunded');
            $ptx->save();

            ActivityLog::record('billing', 'billing.refund_succeeded', [
                'user_id' => $adminId, 'subject_type' => 'refunds', 'subject_id' => (int) $refund->getAttribute('id'),
                'meta' => ['payment_transaction_id' => $paymentTransactionId, 'amount' => $amount],
            ]);

            if (class_exists('Notification')) {
                Notification::notify(
                    (int) $ptx->getAttribute('user_id'),
                    'refund_completed',
                    'تم استرجاع مبلغ لمحفظتك',
                    "تم استرجاع {$amount}$ بنجاح.",
                    '/subscription'
                );
            }

            return ['success' => true, 'refund' => $refund->toArray()];
        }

        $refund->setAttribute('status', 'failed');
        $refund->save();

        ActivityLog::record('billing', 'billing.refund_failed', [
            'user_id' => $adminId, 'subject_type' => 'refunds', 'subject_id' => (int) $refund->getAttribute('id'),
            'meta' => ['payment_transaction_id' => $paymentTransactionId, 'amount' => $amount, 'error' => $result['error'] ?? null],
        ]);

        return ['success' => false, 'error' => $result['error'] ?? 'تعذر تنفيذ الاسترجاع'];
    }

    private function totalRefundedFor(int $paymentTransactionId): float
    {
        $rows = $this->db->query(
            "SELECT COALESCE(SUM(amount), 0) AS total FROM refunds WHERE payment_transaction_id = ? AND status = 'succeeded'",
            [$paymentTransactionId]
        );
        return (float) ($rows[0]['total'] ?? 0);
    }

    private function resolveGatewayFor(string $gatewayKey): ?PaymentGatewayInterface
    {
        // حاليًا المحفظة بس مفعّلة فعليًا. أي بوابة حقيقية جديدة (Stripe
        // مثلاً) تتضاف هنا لما توجد Credentials حقيقية - من غير أي
        // تعديل على باقي RefundService.
        if ($gatewayKey === 'wallet') {
            return new WalletGatewayAdapter();
        }
        return null;
    }

    /** كل الاسترجاعات (لعرضها في لوحة الأدمن) */
    public function listAll(int $limit = 200): array
    {
        return $this->db->query(
            "SELECT r.*, u.email AS user_email, u.company_name
             FROM refunds r
             LEFT JOIN users u ON u.id = r.user_id
             ORDER BY r.created_at DESC LIMIT ?",
            [$limit]
        );
    }
}
