<?php
/**
 * Tourfecto - Subscription Validator
 * مدقق الاشتراكات والصلاحيات المتقدم
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class SubscriptionValidator {
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var array $subscription - بيانات الاشتراك الحالية
     */
    private $subscription = null;
    
    /**
     * @var array $planFeatures - ميزات الباقات
     */
    private $planFeatures = [];
    
    /**
     * @var UsageTracker $usageTracker - متتبع الاستخدام
     */
    private $usageTracker;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->usageTracker = new UsageTracker();
        $this->loadPlanFeatures();
    }

    /**
     * تصحيح: بدل ما نكرر منطق اكتشاف اسم عمود الانتهاء هنا كمان، بنستخدم
     * Subscription::expiryColumn() كمصدر واحد للحقيقة في المشروع كله
     * (نفس الكاش، نفس النتيجة، استعلام واحد بس على مستوى الطلب).
     * @return string
     */
    private function expiryColumn(): string {
        return Subscription::expiryColumn();
    }

    /**
     * شرط SQL جاهز لفلترة الاشتراكات الغير منتهية، أو نص فاضي لو مفيش
     * عمود انتهاء أصلاً في الجدول.
     */
    private function expiryWhereClause(): string {
        $col = $this->expiryColumn();
        return $col ? "AND (`{$col}` IS NULL OR `{$col}` > NOW())" : '';
    }
    
    /**
     * التحقق من صلاحية الاشتراك لمستخدم معين
     * @param int $userId - معرف المستخدم
     * @return array - حالة الاشتراك مع التفاصيل
     */
    public function validateSubscription(int $userId): array {
        try {
            // جلب بيانات الاشتراك النشط (عن طريق الدالة المركزية اللي بتحوّل
            // البنية الحقيقية - plan_id/current_period_end/usage_* - لنفس
            // الأسماء القديمة اللي باقي الكود بيعتمد عليها)
            $subscription = Subscription::activeSubscriptionRow($userId);

            if (!$subscription) {
                // التحقق من وجود اشتراك منتهي
                $expired = $this->checkExpiredSubscription($userId);
                
                return [
                    'valid' => false,
                    'status' => 'inactive',
                    'message' => 'No active subscription found.',
                    'plan' => null,
                    'expired_subscription' => $expired
                ];
            }
            
            $sub = $subscription;
            $this->subscription = $sub;
            
            // التحقق من الـ Credits المتبقية
            $aiRemaining = $sub['ai_credits'] - $sub['ai_credits_used'];
            $chatRemaining = $sub['chat_credits'] - $sub['chat_credits_used'];
            $reviewRemaining = $sub['review_credits'] - $sub['review_credits_used'];
            $competitorRemaining = $sub['competitor_analysis_limit'] - $sub['competitor_analysis_used'];
            
            // التحقق من حد الاستخدام اليومي
            $dailyUsage = $this->usageTracker->getDailyUsage($userId);
            
            return [
                'valid' => true,
                'status' => 'active',
                'message' => 'Subscription is active.',
                'plan' => $sub['plan_name'],
                'plan_type' => $sub['plan_type'],
                'plan_features' => $this->getPlanFeatures($sub['plan_name']),
                'ai_credits' => (int) $sub['ai_credits'],
                'ai_credits_used' => (int) $sub['ai_credits_used'],
                'ai_credits_remaining' => $aiRemaining,
                'chat_credits' => (int) $sub['chat_credits'],
                'chat_credits_used' => (int) $sub['chat_credits_used'],
                'chat_credits_remaining' => $chatRemaining,
                'review_credits' => (int) $sub['review_credits'],
                'review_credits_used' => (int) $sub['review_credits_used'],
                'review_credits_remaining' => $reviewRemaining,
                'competitor_analysis_limit' => (int) $sub['competitor_analysis_limit'],
                'competitor_analysis_used' => (int) $sub['competitor_analysis_used'],
                'competitor_analysis_remaining' => $competitorRemaining,
                'auto_pilot' => (bool) $sub['auto_pilot'],
                'start_date' => $sub['start_date'],
                'expiry_date' => $sub[$this->expiryColumn()] ?? null,
                'days_remaining' => $this->calculateDaysRemaining($sub[$this->expiryColumn()] ?? null),
                'daily_usage' => $dailyUsage,
                'subscription_id' => (int) $sub['id']
            ];
            
        } catch (Exception $e) {
            Logger::error('Subscription Validation Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'valid' => false,
                'status' => 'error',
                'message' => 'Error validating subscription: ' . $e->getMessage(),
                'plan' => null
            ];
        }
    }
    
    /**
     * التحقق من توفر رصيد الذكاء الاصطناعي
     * @param int $userId
     * @param int $requiredCredits
     * @return array
     */
    public function checkAICredits(int $userId, int $requiredCredits = 1): array {
        $subscription = $this->validateSubscription($userId);

        if ($subscription['valid'] && $subscription['ai_credits_remaining'] >= $requiredCredits) {
            return [
                'available' => true,
                'message' => 'Sufficient AI credits available.',
                'remaining' => $subscription['ai_credits_remaining'],
                'required' => $requiredCredits,
                'source' => 'subscription',
            ];
        }

        // تصحيح: لو مفيش اشتراك نشط أو خلّص رصيده، بدل ما نرفض خالص،
        // نفحص لو العميل عنده رصيد محفظة كافي يدفع بيه استخدام واحد
        // "حسب الطلب" - العميل مش لازم يكون مشترك عشان يستخدم المنصة.
        $walletCheck = (new WalletService())->canAffordUsage($userId, 'ai_analysis');
        if ($walletCheck['can_afford']) {
            return [
                'available' => true,
                'message' => 'Payable from wallet balance.',
                'remaining' => 0,
                'required' => $requiredCredits,
                'source' => 'wallet',
                'wallet_price' => $walletCheck['price'],
            ];
        }

        return [
            'available' => false,
            'message' => 'No active subscription and insufficient wallet balance.',
            'remaining' => $subscription['valid'] ? $subscription['ai_credits_remaining'] : 0,
            'required' => $requiredCredits,
            'wallet_shortfall' => $walletCheck['shortfall'] ?? null,
        ];
    }
    
    /**
     * التحقق من توفر رصيد الشات
     * @param int $userId
     * @param int $requiredCredits
     * @return array
     */
    public function checkChatCredits(int $userId, int $requiredCredits = 1): array {
        $subscription = $this->validateSubscription($userId);

        if ($subscription['valid'] && $subscription['chat_credits_remaining'] >= $requiredCredits) {
            return [
                'available' => true,
                'message' => 'Sufficient chat credits available.',
                'remaining' => $subscription['chat_credits_remaining'],
                'required' => $requiredCredits,
                'source' => 'subscription',
            ];
        }

        $walletCheck = (new WalletService())->canAffordUsage($userId, 'chat_message');
        if ($walletCheck['can_afford']) {
            return [
                'available' => true,
                'message' => 'Payable from wallet balance.',
                'remaining' => 0,
                'required' => $requiredCredits,
                'source' => 'wallet',
                'wallet_price' => $walletCheck['price'],
            ];
        }

        return [
            'available' => false,
            'message' => 'No active subscription and insufficient wallet balance.',
            'remaining' => $subscription['valid'] ? $subscription['chat_credits_remaining'] : 0,
            'required' => $requiredCredits,
            'wallet_shortfall' => $walletCheck['shortfall'] ?? null,
        ];
    }
    
    /**
     * التحقق من توفر رصيد تحليل المنافسين
     * @param int $userId
     * @return array
     */
    public function checkCompetitorAnalysisCredits(int $userId): array {
        $subscription = $this->validateSubscription($userId);

        if ($subscription['valid'] && $subscription['competitor_analysis_remaining'] > 0) {
            return [
                'available' => true,
                'message' => 'Competitor analysis credits available.',
                'remaining' => $subscription['competitor_analysis_remaining'],
                'source' => 'subscription',
            ];
        }

        $walletCheck = (new WalletService())->canAffordUsage($userId, 'competitor_analysis');
        if ($walletCheck['can_afford']) {
            return [
                'available' => true,
                'message' => 'Payable from wallet balance.',
                'remaining' => 0,
                'source' => 'wallet',
                'wallet_price' => $walletCheck['price'],
            ];
        }

        return [
            'available' => false,
            'message' => 'No active subscription and insufficient wallet balance.',
            'remaining' => 0,
            'wallet_shortfall' => $walletCheck['shortfall'] ?? null,
        ];
    }
    
    /**
     * استهلاك رصيد AI
     * @param int $userId
     * @param int $creditsUsed
     * @return bool
     */
    public function consumeAICredits(int $userId, int $creditsUsed = 1, bool $viaWallet = false): bool {
        try {
            // تصحيح: لو الاستخدام ده "ادفع حسب الاستخدام" من المحفظة (مفيش
            // اشتراك نشط أصلاً)، بنخصم ثمنه من الرصيد بدل ما نحاول نحدّث
            // عداد اشتراك مش موجود (كان هيفشل صامت من غير خصم حقيقي).
            if ($viaWallet) {
                return (new WalletService())->chargeForUsage($userId, 'ai_analysis', 'تحليل AI');
            }

            $sql = "UPDATE subscriptions 
                    SET usage_ai_analysis_count = usage_ai_analysis_count + :credits_used,
                        updated_at = NOW()
                    WHERE user_id = :user_id 
                    AND status = 'active' 
                    AND current_period_end > NOW()
                    ORDER BY id DESC LIMIT 1";
            
            $result = $this->db->query($sql, [
                ':credits_used' => $creditsUsed,
                ':user_id' => $userId
            ]);
            
            if ($result > 0) {
                // تسجيل الاستخدام
                $this->usageTracker->logUsage($userId, 'ai', $creditsUsed);
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            Logger::error('Consume AI Credits Error', [
                'user_id' => $userId,
                'credits' => $creditsUsed,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * استهلاك رصيد الشات
     * @param int $userId
     * @param int $creditsUsed
     * @param bool $viaWallet - لو true، بيتخصم ثمنه من المحفظة بدل عداد الاشتراك
     * @return bool
     */
    public function consumeChatCredits(int $userId, int $creditsUsed = 1, bool $viaWallet = false): bool {
        try {
            if ($viaWallet) {
                return (new WalletService())->chargeForUsage($userId, 'chat_message', 'رد شات');
            }

            $sql = "UPDATE subscriptions 
                    SET usage_ai_message_count = usage_ai_message_count + :credits_used,
                        updated_at = NOW()
                    WHERE user_id = :user_id 
                    AND status = 'active' 
                    AND current_period_end > NOW()
                    ORDER BY id DESC LIMIT 1";
            
            $result = $this->db->query($sql, [
                ':credits_used' => $creditsUsed,
                ':user_id' => $userId
            ]);
            
            if ($result > 0) {
                $this->usageTracker->logUsage($userId, 'chat', $creditsUsed);
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            Logger::error('Consume Chat Credits Error', [
                'user_id' => $userId,
                'credits' => $creditsUsed,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * استهلاك رصيد تحليل المنافسين
     * @param int $userId
     * @param bool $viaWallet - لو true، بيتخصم ثمنه من المحفظة
     * @return bool
     */
    public function consumeCompetitorAnalysisCredit(int $userId, bool $viaWallet = false): bool {
        try {
            if ($viaWallet) {
                $charged = (new WalletService())->chargeForUsage($userId, 'competitor_analysis', 'تحليل منافس');
                if ($charged) {
                    $this->usageTracker->logUsage($userId, 'competitor_analysis', 1);
                }
                return $charged;
            }

            // ملحوظة: مفيش عمود مخصص لتتبع استهلاك تحليل المنافسين في
            // الجدول الحقيقي (زي usage_ai_analysis_count للتحليل العادي)،
            // فمينفعش نحدّث عمود مش موجود. بنسجّل الاستخدام في UsageTracker
            // بس (اللي بيدي تتبع تقريبي)، والحد نفسه (competitor_analysis
            // في features_json) مش بيتفرض فعليًا لحد ما نضيف عمود مخصص.
            $this->usageTracker->logUsage($userId, 'competitor_analysis', 1);
            return true;
        } catch (Exception $e) {
            Logger::error('Consume Competitor Analysis Credit Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * التحقق من صلاحية المستخدم لاستخدام خاصية معينة
     * @param int $userId
     * @param string $feature
     * @return bool
     */
    public function canUseFeature(int $userId, string $feature): bool {
        $subscription = $this->validateSubscription($userId);
        
        if (!$subscription['valid']) {
            return false;
        }
        
        $plan = $subscription['plan'];
        $features = $this->getPlanFeatures($plan);
        
        return isset($features[$feature]) && $features[$feature];
    }
    
    /**
     * الحصول على إعدادات البوت للمستخدم
     * @param int $userId
     * @param int $websiteId
     * @return array
     */
    public function getBotSettings(int $userId, int $websiteId): array {
        try {
            $sql = "SELECT * FROM bot_settings 
                    WHERE user_id = :user_id 
                    AND website_id = :website_id 
                    AND is_enabled = 1
                    ORDER BY id DESC LIMIT 1";
            
            $settings = $this->db->query($sql, [
                ':user_id' => $userId,
                ':website_id' => $websiteId
            ]);
            
            if (empty($settings)) {
                // التحقق من صلاحية Auto Pilot
                $canAutoPilot = $this->canUseFeature($userId, 'auto_pilot');
                
                // إعدادات افتراضية
                return [
                    'is_enabled' => true,
                    'auto_pilot' => $canAutoPilot,
                    'requires_approval' => !$canAutoPilot,
                    'ai_model' => 'gemini-flash-latest',
                    'ai_temperature' => 0.70,
                    'ai_max_tokens' => 2000,
                    'ai_language' => 'auto',
                    'greeting_message' => 'مرحباً بك! كيف يمكننا مساعدتك اليوم؟',
                    'fallback_message' => 'شكراً لتواصلك معنا. أحد ممثلي خدمة العملاء سيتواصل معك قريباً.'
                ];
            }
            
            return $settings[0];
            
        } catch (Exception $e) {
            Logger::error('Get Bot Settings Error', [
                'user_id' => $userId,
                'website_id' => $websiteId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'is_enabled' => false,
                'auto_pilot' => false,
                'requires_approval' => true,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * التحقق من الاشتراك المنتهي
     * @param int $userId
     * @return array|null
     */
    private function checkExpiredSubscription(int $userId): ?array {
        try {
            $sql = "SELECT 
                        s.*,
                        SUBSTRING_INDEX(sp.plan_code, '_', 1) AS plan_name,
                        sp.billing_cycle AS plan_type
                    FROM subscriptions s
                    LEFT JOIN subscription_plans sp ON sp.id = s.plan_id
                    WHERE s.user_id = ? 
                    AND s.status IN ('expired', 'cancelled')
                    ORDER BY s.current_period_end DESC LIMIT 1";

            $result = $this->db->query($sql, [$userId]);
            return $result[0] ?? null;
        } catch (Exception $e) {
            Logger::warning('checkExpiredSubscription failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }
    
    /**
     * حساب الأيام المتبقية
     * @param string|null $expiryDate
     * @return int|null
     */
    private function calculateDaysRemaining(?string $expiryDate): ?int {
        if (!$expiryDate) {
            return null;
        }
        
        $now = time();
        $expiry = strtotime($expiryDate);
        $diff = $expiry - $now;
        
        return max(0, (int) ceil($diff / (60 * 60 * 24)));
    }
    
    /**
     * تحميل ميزات الباقات
     */
    private function loadPlanFeatures(): void {
        // تصحيح: بقت الباقات قابلة للتعديل من لوحة الأدمن بدل الثابت الجامد.
        $this->planFeatures = SubscriptionPlan::allAsLegacyArray();
    }
    
    /**
     * الحصول على ميزات باقة معينة
     * @param string $planName
     * @return array
     */
    public function getPlanFeatures(string $planName): array {
        return $this->planFeatures[$planName]['features'] ?? [];
    }
    
    /**
     * الحصول على جميع الباقات المتاحة
     * @return array
     */
    public function getAvailablePlans(): array {
        return $this->planFeatures;
    }
}