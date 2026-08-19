<?php

/**
 * Tourfecto - Fixture Loader
 * تحميل بيانات الاختبار في قاعدة البيانات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class FixtureLoader
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;

    /**
     * @var array $loadedFixtures - قائمة البيانات المحملة
     */
    private $loadedFixtures = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * تحميل جميع البيانات
     * @param bool $cleanFirst - تنظيف البيانات أولاً
     * @return array
     */
    public function loadAll(bool $cleanFirst = true): array
    {
        $this->loadedFixtures = [];

        if ($cleanFirst) {
            $this->cleanDatabase();
        }

        $this->loadUsers();
        $this->loadWebsites();
        $this->loadSubscriptions();
        $this->loadReports();
        $this->loadReviews();
        $this->loadChatMessages();
        $this->loadBotSettings();

        return $this->loadedFixtures;
    }

    /**
     * تنظيف قاعدة البيانات
     */
    private function cleanDatabase(): void
    {
        $tables = [
            'chat_messages', 'reviews', 'ai_reports',
            'bot_settings', 'subscriptions', 'websites', 'users'
        ];

        foreach ($tables as $table) {
            $sql = "DELETE FROM {$table} WHERE id > 0";
            $this->db->query($sql);

            $sql = "ALTER TABLE {$table} AUTO_INCREMENT = 1";
            $this->db->query($sql);
        }

        echo "🧹 Database cleaned\n";
    }

    /**
     * تحميل بيانات المستخدمين
     */
    private function loadUsers(): void
    {
        $fixtures = require_once __DIR__ . '/users_fixtures.php';
        $users = $fixtures['users'] ?? [];

        foreach ($users as $user) {
            $sql = "INSERT INTO users (
                company_name, email, password, phone, country, language, 
                timezone, role, is_active, email_verified, api_token, created_at
            ) VALUES (
                :company_name, :email, :password, :phone, :country, :language,
                :timezone, :role, :is_active, :email_verified, :api_token, :created_at
            )";

            $id = $this->db->query($sql, $user);
            $this->loadedFixtures['users'][] = $id;
        }

        echo "👤 Loaded " . count($users) . " users\n";
    }

    /**
     * تحميل بيانات المواقع
     */
    private function loadWebsites(): void
    {
        $fixtures = require_once __DIR__ . '/users_fixtures.php';
        $websites = $fixtures['websites'] ?? [];

        foreach ($websites as $website) {
            $sql = "INSERT INTO websites (
                user_id, main_url, company_name, industry, target_language,
                target_country, meta_description, competitor_1_url, competitor_2_url,
                competitor_3_url, is_verified
            ) VALUES (
                :user_id, :main_url, :company_name, :industry, :target_language,
                :target_country, :meta_description, :competitor_1_url, :competitor_2_url,
                :competitor_3_url, :is_verified
            )";

            $id = $this->db->query($sql, $website);
            $this->loadedFixtures['websites'][] = $id;
        }

        echo "🌐 Loaded " . count($websites) . " websites\n";
    }

    /**
     * تحميل بيانات الاشتراكات
     */
    private function loadSubscriptions(): void
    {
        $fixtures = require_once __DIR__ . '/subscriptions_fixtures.php';
        $subscriptions = $fixtures['subscriptions'] ?? [];

        foreach ($subscriptions as $sub) {
            $sql = "INSERT INTO subscriptions (
                user_id, plan_name, plan_type, status, price, currency,
                ai_credits, ai_credits_used, chat_credits, chat_credits_used,
                review_credits, review_credits_used, competitor_analysis_limit,
                competitor_analysis_used, auto_pilot, start_date, expiry_date,
                last_billed_at, next_billing_at, payment_method, payment_gateway
            ) VALUES (
                :user_id, :plan_name, :plan_type, :status, :price, :currency,
                :ai_credits, :ai_credits_used, :chat_credits, :chat_credits_used,
                :review_credits, :review_credits_used, :competitor_analysis_limit,
                :competitor_analysis_used, :auto_pilot, :start_date, :expiry_date,
                :last_billed_at, :next_billing_at, :payment_method, :payment_gateway
            )";

            $id = $this->db->query($sql, $sub);
            $this->loadedFixtures['subscriptions'][] = $id;
        }

        echo "📋 Loaded " . count($subscriptions) . " subscriptions\n";
    }

    /**
     * تحميل بيانات التقارير
     */
    private function loadReports(): void
    {
        $fixtures = require_once __DIR__ . '/reports_fixtures.php';

        $reportTypes = ['seo_reports', 'aeo_reports', 'geo_reports', 'full_reports'];
        $totalLoaded = 0;

        foreach ($reportTypes as $type) {
            $reports = $fixtures[$type] ?? [];

            foreach ($reports as $report) {
                $sql = "INSERT INTO ai_reports (
                    website_id, user_id, report_type, target_url, competitor_urls,
                    target_language, seo_keywords, seo_title_suggestions, seo_meta_suggestions,
                    seo_content_gaps, aeo_direct_answers, aeo_trust_signals,
                    aeo_positioning_strategy, geo_faq_schema, geo_questions_generated,
                    geo_map_integration, geo_improvement_suggestions, full_report_json,
                    analysis_score, keywords_found, competitors_analyzed, is_cached,
                    cached_until, status, error_message, tokens_used, cost_in_usd,
                    created_at
                ) VALUES (
                    :website_id, :user_id, :report_type, :target_url, :competitor_urls,
                    :target_language, :seo_keywords, :seo_title_suggestions, :seo_meta_suggestions,
                    :seo_content_gaps, :aeo_direct_answers, :aeo_trust_signals,
                    :aeo_positioning_strategy, :geo_faq_schema, :geo_questions_generated,
                    :geo_map_integration, :geo_improvement_suggestions, :full_report_json,
                    :analysis_score, :keywords_found, :competitors_analyzed, :is_cached,
                    :cached_until, :status, :error_message, :tokens_used, :cost_in_usd,
                    :created_at
                )";

                $id = $this->db->query($sql, $report);
                $this->loadedFixtures['reports'][] = $id;
                $totalLoaded++;
            }
        }

        echo "📊 Loaded " . $totalLoaded . " reports\n";
    }

    /**
     * تحميل بيانات المراجعات
     */
    private function loadReviews(): void
    {
        $reviews = $this->generateTestReviews();

        foreach ($reviews as $review) {
            $sql = "INSERT INTO reviews (
                website_id, user_id, platform, platform_review_id, reviewer_name,
                review_text, rating, review_date, sentiment_label, sentiment_score,
                sentiment_confidence, auto_reply_generated, reply_sent, is_processed,
                created_at
            ) VALUES (
                :website_id, :user_id, :platform, :platform_review_id, :reviewer_name,
                :review_text, :rating, :review_date, :sentiment_label, :sentiment_score,
                :sentiment_confidence, :auto_reply_generated, :reply_sent, :is_processed,
                :created_at
            )";

            $this->db->query($sql, $review);
        }

        echo "⭐ Loaded " . count($reviews) . " reviews\n";
    }

    /**
     * توليد مراجعات للاختبار
     * @return array
     */
    private function generateTestReviews(): array
    {
        $reviews = [
            [
                'website_id' => 1,
                'user_id' => 1,
                'platform' => 'tripadvisor',
                'platform_review_id' => 'ta_' . uniqid(),
                'reviewer_name' => 'Ahmed Ali',
                'review_text' => 'خدمة رائعة وفريق عمل محترف. أنصح الجميع بتجربة هذه المنصة.',
                'rating' => 5,
                'review_date' => date('Y-m-d H:i:s', strtotime('-2 days')),
                'sentiment_label' => 'positive',
                'sentiment_score' => 0.92,
                'sentiment_confidence' => 0.95,
                'auto_reply_generated' => 'شكراً جزيلاً لك على تقييمك الإيجابي!',
                'reply_sent' => 1,
                'is_processed' => 1,
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
            ],
            [
                'website_id' => 1,
                'user_id' => 1,
                'platform' => 'google_business',
                'platform_review_id' => 'gb_' . uniqid(),
                'reviewer_name' => 'Mohammed Khalid',
                'review_text' => 'خدمة جيدة ولكن تحتاج إلى تحسين في سرعة الاستجابة.',
                'rating' => 3,
                'review_date' => date('Y-m-d H:i:s', strtotime('-1 day')),
                'sentiment_label' => 'neutral',
                'sentiment_score' => 0.50,
                'sentiment_confidence' => 0.80,
                'auto_reply_generated' => null,
                'reply_sent' => 0,
                'is_processed' => 1,
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
            ],
            [
                'website_id' => 2,
                'user_id' => 3,
                'platform' => 'tripadvisor',
                'platform_review_id' => 'ta_' . uniqid(),
                'reviewer_name' => 'Sara Ahmed',
                'review_text' => 'تجربة سيئة جداً. لم أحصل على الخدمة المطلوبة.',
                'rating' => 1,
                'review_date' => date('Y-m-d H:i:s', strtotime('-3 days')),
                'sentiment_label' => 'negative',
                'sentiment_score' => 0.15,
                'sentiment_confidence' => 0.90,
                'auto_reply_generated' => 'نأسف جداً لتجربتك غير المرضية. سنتواصل معك لحل المشكلة.',
                'reply_sent' => 0,
                'is_processed' => 1,
                'created_at' => date('Y-m-d H:i:s', strtotime('-3 days'))
            ]
        ];

        return $reviews;
    }

    /**
     * تحميل بيانات رسائل الشات
     */
    private function loadChatMessages(): void
    {
        $messages = $this->generateTestChatMessages();

        foreach ($messages as $message) {
            $sql = "INSERT INTO chat_messages (
                website_id, user_id, platform, platform_message_id, customer_name,
                customer_phone, message_direction, message_text, message_language,
                ai_reply_generated, bot_status, is_auto_pilot, created_at
            ) VALUES (
                :website_id, :user_id, :platform, :platform_message_id, :customer_name,
                :customer_phone, :message_direction, :message_text, :message_language,
                :ai_reply_generated, :bot_status, :is_auto_pilot, :created_at
            )";

            $this->db->query($sql, $message);
        }

        echo "💬 Loaded " . count($messages) . " chat messages\n";
    }

    /**
     * توليد رسائل شات للاختبار
     * @return array
     */
    private function generateTestChatMessages(): array
    {
        return [
            [
                'website_id' => 1,
                'user_id' => 1,
                'platform' => 'whatsapp',
                'platform_message_id' => 'wa_' . uniqid(),
                'customer_name' => 'Test Customer',
                'customer_phone' => '+966500000001',
                'message_direction' => 'incoming',
                'message_text' => 'مرحباً، أريد حجز رحلة سياحية',
                'message_language' => 'ar',
                'ai_reply_generated' => 'مرحباً بك! يسعدنا مساعدتك في حجز رحلة سياحية. ما هي الوجهة التي تفضلها؟',
                'bot_status' => 'sent',
                'is_auto_pilot' => 1,
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))
            ],
            [
                'website_id' => 1,
                'user_id' => 1,
                'platform' => 'whatsapp',
                'platform_message_id' => 'wa_' . uniqid(),
                'customer_name' => 'Test Customer',
                'customer_phone' => '+966500000001',
                'message_direction' => 'outgoing',
                'message_text' => 'مرحباً بك! يسعدنا مساعدتك في حجز رحلة سياحية. ما هي الوجهة التي تفضلها؟',
                'message_language' => 'ar',
                'ai_reply_generated' => null,
                'bot_status' => 'sent',
                'is_auto_pilot' => 1,
                'created_at' => date('Y-m-d H:i:s', strtotime('-50 minutes'))
            ],
            [
                'website_id' => 2,
                'user_id' => 3,
                'platform' => 'whatsapp',
                'platform_message_id' => 'wa_' . uniqid(),
                'customer_name' => 'Another Customer',
                'customer_phone' => '+966500000002',
                'message_direction' => 'incoming',
                'message_text' => 'أحتاج إلى معلومات عن الفنادق في جدة',
                'message_language' => 'ar',
                'ai_reply_generated' => 'نقدم لك أفضل عروض الفنادق في جدة. هل تفضل فندقاً قريباً من البحر أم المطار؟',
                'bot_status' => 'pending_approval',
                'is_auto_pilot' => 0,
                'created_at' => date('Y-m-d H:i:s', strtotime('-30 minutes'))
            ]
        ];
    }

    /**
     * تحميل بيانات إعدادات البوت
     */
    private function loadBotSettings(): void
    {
        $settings = $this->generateTestBotSettings();

        foreach ($settings as $setting) {
            $sql = "INSERT INTO bot_settings (
                user_id, website_id, platform, is_enabled, auto_pilot,
                requires_approval, ai_model, ai_temperature, ai_max_tokens,
                ai_language, greeting_message, farewell_message, fallback_message,
                business_hours_start, business_hours_end, timezone,
                created_at
            ) VALUES (
                :user_id, :website_id, :platform, :is_enabled, :auto_pilot,
                :requires_approval, :ai_model, :ai_temperature, :ai_max_tokens,
                :ai_language, :greeting_message, :farewell_message, :fallback_message,
                :business_hours_start, :business_hours_end, :timezone,
                :created_at
            )";

            $this->db->query($sql, $setting);
        }

        echo "⚙️ Loaded " . count($settings) . " bot settings\n";
    }

    /**
     * توليد إعدادات بوت للاختبار
     * @return array
     */
    private function generateTestBotSettings(): array
    {
        return [
            [
                'user_id' => 1,
                'website_id' => 1,
                'platform' => 'all',
                'is_enabled' => 1,
                'auto_pilot' => 1,
                'requires_approval' => 0,
                'ai_model' => 'gemini-1.5-flash',
                'ai_temperature' => 0.70,
                'ai_max_tokens' => 2000,
                'ai_language' => 'auto',
                'greeting_message' => 'مرحباً بك في Tourfecto! كيف يمكننا مساعدتك اليوم؟',
                'farewell_message' => 'شكراً لتواصلك معنا. نتمنى لك يوماً سعيداً!',
                'fallback_message' => 'نعتذر، لم نتمكن من فهم طلبك. يرجى المحاولة مرة أخرى.',
                'business_hours_start' => '09:00:00',
                'business_hours_end' => '18:00:00',
                'timezone' => 'Asia/Riyadh',
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'user_id' => 3,
                'website_id' => 2,
                'platform' => 'whatsapp',
                'is_enabled' => 1,
                'auto_pilot' => 1,
                'requires_approval' => 0,
                'ai_model' => 'gemini-1.5-flash',
                'ai_temperature' => 0.75,
                'ai_max_tokens' => 2000,
                'ai_language' => 'ar',
                'greeting_message' => 'مرحباً بك في العربي للسياحة! 🕌 كيف يمكننا مساعدتك؟',
                'farewell_message' => 'شكراً لتواصلك مع العربي للسياحة. ✈️',
                'fallback_message' => 'عذراً، لم نستطع فهم طلبك. يمكنك التواصل مع فريق الدعم.',
                'business_hours_start' => '08:00:00',
                'business_hours_end' => '20:00:00',
                'timezone' => 'Asia/Riyadh',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ];
    }

    /**
     * الحصول على قائمة البيانات المحملة
     * @return array
     */
    public function getLoadedFixtures(): array
    {
        return $this->loadedFixtures;
    }
}

// ============================================
// تنفيذ التحميل
// ============================================
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $loader = new FixtureLoader();
    $result = $loader->loadAll(true);

    echo "\n✅ All fixtures loaded successfully!\n";
    echo "📊 Loaded " . count($result, COUNT_RECURSIVE) . " records\n";
}
