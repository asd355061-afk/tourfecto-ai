<?php

/**
 * Tourfecto - Feature Resolver
 * =============================
 * بيدمج ميزات الباقة الافتراضية (SUBSCRIPTION_PLANS) مع أي تخصيص
 * (Override) عملَه الأدمن لعميل بذاته من لوحة الأدمن، ويرجّع القيمة
 * الفعلية (Effective) اللي المفروض الكود يتصرف على أساسها.
 *
 * أي حتة في الكود عايزة تعرف هل عميل معين معاه ميزة معينة، أو إيه
 * الحد المسموح له بيه، المفروض تستخدم الكلاس ده بدل ما تقرا
 * SUBSCRIPTION_PLANS مباشرة - عشان override الأدمن يتطبق فعليًا.
 *
 * @version 1.0.0
 */

class FeatureResolver
{
    /**
     * يرجّع كل الميزات الفعلية لمستخدم معين (افتراضي الباقة + أي تخصيص).
     * @param int $userId
     * @return array ['features' => [...], 'plan_name' => string|null, 'has_overrides' => bool]
     */
    public static function resolve(int $userId): array
    {
        $subscription = Subscription::activeSubscriptionRow($userId);
        $planName = $subscription['plan_name'] ?? 'starter';
        $planDefaults = SUBSCRIPTION_PLANS[$planName]['features'] ?? SUBSCRIPTION_PLANS['starter']['features'];

        $overrides = [];
        if ($subscription && !empty($subscription['feature_overrides'])) {
            $decoded = json_decode((string) $subscription['feature_overrides'], true);
            if (is_array($decoded)) {
                $overrides = $decoded;
            }
        }

        // الدمج: أي قيمة موجودة في overrides (حتى لو false أو 0) بتغلب
        // على قيمة الباقة الافتراضية. القيم اللي مالهاش override بتفضل
        // زي ما هي من الباقة.
        $effective = $planDefaults;
        foreach ($overrides as $key => $value) {
            $effective[$key] = $value;
        }

        return [
            'plan_name' => $planName,
            'plan_defaults' => $planDefaults,
            'overrides' => $overrides,
            'features' => $effective,
            'has_overrides' => count($overrides) > 0,
        ];
    }

    /**
     * فحص سريع: هل عند المستخدم ده ميزة معينة (Boolean) مفعّلة فعليًا؟
     */
    public static function has(int $userId, string $featureKey): bool
    {
        $resolved = self::resolve($userId);
        return (bool) ($resolved['features'][$featureKey] ?? false);
    }

    /**
     * فحص سريع: إيه القيمة الفعلية لميزة رقمية (زي حد الرسايل الشهري)؟
     */
    public static function value(int $userId, string $featureKey)
    {
        $resolved = self::resolve($userId);
        return $resolved['features'][$featureKey] ?? null;
    }

    /**
     * حفظ تخصيصات جديدة لعميل (بيستبدل كل الـ overrides القديمة).
     * $overrides ممكن يبقى فيها بعض الميزات بس - مش لازم كل الميزات.
     * لو عايز ترجع ميزة لقيمة الباقة الافتراضية تاني، امسحها من المصفوفة
     * دي بدل ما تبعتها.
     */
    public static function saveOverrides(int $userId, array $overrides): bool
    {
        $row = Subscription::activeSubscriptionRow($userId);
        if (!$row || empty($row['id'])) {
            return false;
        }

        $subscription = (new Subscription())->find((int) $row['id']);
        if (!$subscription) {
            return false;
        }

        $subscription->setAttribute('feature_overrides', json_encode($overrides, JSON_UNESCAPED_UNICODE));
        return $subscription->save() !== false;
    }
}
