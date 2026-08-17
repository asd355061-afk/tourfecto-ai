<?php

/**
 * Tourfecto - Subscription Plan Display Model
 * باقات وأسعار العرض العام - قابلة للتعديل من لوحة الأدمن.
 * @version 1.0.0
 *
 * ⚠️ اسم الجدول "plan_pricing_display" (مش "subscription_plans") عشان
 * لاحظنا وجود جدول حقيقي بنفس الاسم "subscription_plans" بعمود
 * "plan_code" شغّال بالفعل في محرك الفوترة (Subscription::createSubscription)
 * وميعرفش نتأكد من بنيته الحقيقية - فبدل ما نخاطر بتعارض صامت، الجدول
 * ده منفصل تمامًا وبيتحكم بس في العرض العام (صفحة الأسعار وحدود
 * المميزات المعروضة) - مش في محرك الفوترة الفعلي.
 */
class SubscriptionPlan extends Model
{
    protected $table = 'plan_pricing_display';

    protected $fillable = [
        'plan_key', 'name', 'price_monthly', 'price_yearly', 'currency', 'currency_symbol',
        'ai_analysis', 'competitor_analysis', 'chat_credits', 'review_credits', 'multiple_websites',
        'whatsapp_bot', 'auto_pilot', 'advanced_analytics', 'sort_order', 'is_active',
    ];

    /**
     * كل الباقات بنفس شكل SUBSCRIPTION_PLANS القديمة (array مفتاحها
     * plan_key) عشان أي كود قديم يستخدمها من غير تعديل. بيرجع الباقات
     * النشطة بس، مرتّبة حسب sort_order.
     */
    public static function allAsLegacyArray(): array
    {
        try {
            $db = Database::getInstance();
            $rows = $db->query("SELECT * FROM plan_pricing_display WHERE is_active = 1 ORDER BY sort_order ASC");
        } catch (Exception $e) {
            $rows = [];
        }

        if (empty($rows)) {
            // مفيش جدول لسه (الـ migration ما اتشغّلش) أو فاضي - نرجع للثابت
            // القديم عشان الموقع يفضل شغّال بدل ما يقع.
            return defined('SUBSCRIPTION_PLANS') ? SUBSCRIPTION_PLANS : [];
        }

        $plans = [];
        foreach ($rows as $row) {
            $plans[$row['plan_key']] = [
                'id' => $row['plan_key'],
                'db_id' => (int) $row['id'],
                'name' => $row['name'],
                'price_monthly' => (float) $row['price_monthly'],
                'price_yearly' => (float) $row['price_yearly'],
                'currency' => $row['currency'],
                'currency_symbol' => $row['currency_symbol'],
                'features' => [
                    'ai_analysis' => (int) $row['ai_analysis'],
                    'competitor_analysis' => (int) $row['competitor_analysis'],
                    'chat_credits' => (int) $row['chat_credits'],
                    'review_credits' => (int) $row['review_credits'],
                    'whatsapp_bot' => (bool) $row['whatsapp_bot'],
                    'auto_pilot' => (bool) $row['auto_pilot'],
                    'multiple_websites' => (int) $row['multiple_websites'],
                    'advanced_analytics' => (bool) $row['advanced_analytics'],
                ],
            ];
        }
        return $plans;
    }
}
