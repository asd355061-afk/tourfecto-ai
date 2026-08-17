<?php
/**
 * Tourfecto - Subscription Period Helper
 * @version 1.0.0
 * @date 2026-08-15
 *
 * حسابات فترات الاشتراك النقية (Pure) - مفيش أي اعتماد على قاعدة
 * بيانات، فمنفصلة في كلاس لوحدها عشان تختبر بسهولة من غير DB، ولأن
 * نفس المنطق (تمديد شهر/سنة) كان متكرر في مكانين:
 *   - Subscription::createSubscription (الاشتراك الجديد)
 *   - WalletService::renewSubscriptionFromBalance (التجديد التلقائي)
 *
 * كل الدوال static و pure - نفس المدخلات بتدي نفس المخرجات دايمًا،
 * فمفيش حالة غامضة ولا أخطاء توقيت.
 */
class SubscriptionPeriod {
    /**
     * حساب نهاية الفترة الجديدة بتمديد شهر/سنة من تاريخ معيّن.
     *
     * @param string $fromDateTime تاريخ البداية بصيغة 'Y-m-d H:i:s'
     * @param string $planType 'monthly' أو 'yearly' (أي قيمة تانية بتتعامل
     *                         كـ monthly - سلوك محافظ)
     * @return string 'Y-m-d H:i:s'
     */
    public static function nextPeriodEnd(string $fromDateTime, string $planType): string {
        $base = strtotime($fromDateTime);
        if ($base === false) {
            // تاريخ غير صالح - نرجع من الآن بدل ما نرمي Exception في سلسلة
            // دفع حساسة (الاشتراك نفسه ناجح ومفيش داعي نعطّل التجديد بسبب
            // قيمة تاريخ شاذة). الحالة دي لازم تتحقق منها - ملف اختبار.
            $base = time();
        }
        if ($planType === 'yearly') {
            return date('Y-m-d H:i:s', strtotime('+1 year', $base));
        }
        return date('Y-m-d H:i:s', strtotime('+1 month', $base));
    }

    /**
     * مفتاح idempotency فريد لتجديد اشتراك معيّن لفترة معيّنة - بيمنع
     * الخصم المزدوج لو نفس التشغيلة (أو تشغيلتين متوازيتين) حاولوا
     * يجددوا نفس الاشتراك لنفس الفترة.
     *
     * @param int $subscriptionId
     * @param string $currentPeriodEnd تاريخ نهاية الفترة الحالية (اللي
     *                                  بيعرّف الفترة اللي بنجددها)
     * @return string
     */
    public static function renewalIdempotencyKey(int $subscriptionId, string $currentPeriodEnd): string {
        $stamp = strtotime($currentPeriodEnd);
        if ($stamp === false) {
            $stamp = time();
        }
        return 'renewal_' . $subscriptionId . '_' . $stamp;
    }
}
