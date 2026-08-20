-- ============================================
-- Tourfecto - إصلاح محلي: مواءمة سكيما محلية مع كود السيرفر
-- (reviews: أسماء الأعمدة الجديدة - users: أعمدة الاسم والصورة)
-- Idempotent (آمن للتشغيل أكثر من مرة): يستخدم IF NOT EXISTS / IF EXISTS
-- ملاحظة: إنتاج السيرفر يملك هذه السكيما فعلاً؛ هذا الملف خاص بالمحلي فقط.
-- ============================================

-- 1) users: إضافة first_name / last_name / avatar_url (كود CRM والنموذج يعتمدان عليها)
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `first_name` VARCHAR(100) DEFAULT NULL COMMENT 'الاسم الأول' AFTER `id`,
    ADD COLUMN IF NOT EXISTS `last_name` VARCHAR(100) DEFAULT NULL COMMENT 'الاسم الأخير' AFTER `first_name`,
    ADD COLUMN IF NOT EXISTS `avatar_url` VARCHAR(500) DEFAULT NULL COMMENT 'رابط الصورة الشخصية' AFTER `last_name`;

-- 2) users: حقول الملف الشخصي الإضافية (بديل آمن لميجراتشن 000042 إذا طُبقت قبله)
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `display_name` VARCHAR(120) DEFAULT NULL COMMENT 'الاسم المعروض' AFTER `last_name`,
    ADD COLUMN IF NOT EXISTS `job_title` VARCHAR(120) DEFAULT NULL COMMENT 'المسمى الوظيفي' AFTER `company_name`,
    ADD COLUMN IF NOT EXISTS `bio` VARCHAR(500) DEFAULT NULL COMMENT 'نبذة مختصرة' AFTER `job_title`;

-- 3) reviews: إعادة تسمية الأعمدة إلى السكيما الجديدة المستخدمة في الكود
ALTER TABLE `reviews`
    CHANGE COLUMN IF EXISTS `platform` `source_platform` ENUM('tripadvisor','google_business','booking','expedia','trustpilot','other') NOT NULL COMMENT 'المنصة المصدر للمراجعة',
    CHANGE COLUMN IF EXISTS `platform_review_id` `external_review_id` VARCHAR(255) DEFAULT NULL COMMENT 'المعرف الخارجي للمراجعة لدى المنصة',
    CHANGE COLUMN IF EXISTS `sentiment_label` `sentiment` ENUM('positive','neutral','negative','mixed') DEFAULT NULL COMMENT 'تصنيف المشاعر',
    CHANGE COLUMN IF EXISTS `auto_reply_generated` `ai_generated_reply` TEXT COMMENT 'الرد المولّد بالذكاء الاصطناعي';

-- 4) reviews: أعمدة إضافية تعتمد عليها الكود (reply_status / keywords_injected / webhook_payload)
ALTER TABLE `reviews`
    ADD COLUMN IF NOT EXISTS `reply_status` ENUM('pending','approved','rejected','sent') DEFAULT NULL COMMENT 'حالة الرد' AFTER `ai_generated_reply`,
    ADD COLUMN IF NOT EXISTS `keywords_injected` TINYINT(1) DEFAULT 0 COMMENT 'تم حقن الكلمات المفتاحية في الرد' AFTER `reply_status`,
    ADD COLUMN IF NOT EXISTS `webhook_payload` LONGTEXT DEFAULT NULL COMMENT 'حمولة الـ webhook الأصلية' AFTER `webhook_raw_data`;

-- 5) subscription_plans: جدول فوترة حقيقي على السيرفر فقط (ناقص محليًا)
--    الكود يقرأ منه (Subscription::createSubscription, MRR، لوحة الأدمن).
CREATE TABLE IF NOT EXISTS `subscription_plans` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `plan_code` VARCHAR(50) NOT NULL COMMENT 'المعرف الثابت (starter_monthly, professional_yearly...)',
    `name` VARCHAR(150) NOT NULL COMMENT 'اسم الباقة',
    `price` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'سعر الفترة',
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `billing_cycle` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `features_json` LONGTEXT DEFAULT NULL COMMENT 'مميزات JSON (auto_pilot, competitor_analysis...)',
    `ai_analysis_limit` INT(11) NOT NULL DEFAULT 0,
    `ai_message_limit` INT(11) NOT NULL DEFAULT 0,
    `review_auto_reply_limit` INT(11) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_plan_code` (`plan_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='باقات الفوترة الحقيقية';

INSERT INTO `subscription_plans`
    (`plan_code`, `name`, `price`, `currency`, `billing_cycle`, `is_active`, `features_json`,
     `ai_analysis_limit`, `ai_message_limit`, `review_auto_reply_limit`)
VALUES
    ('starter_monthly', 'الباقة الأساسية', 49.00, 'USD', 'monthly', 1, '{"auto_pilot":0,"competitor_analysis":5,"multiple_websites":1,"chat_credits":100,"review_credits":10,"ai_analysis":50}', 50, 100, 10),
    ('starter_yearly', 'الباقة الأساسية', 490.00, 'USD', 'yearly', 1, '{"auto_pilot":0,"competitor_analysis":5,"multiple_websites":1,"chat_credits":100,"review_credits":10,"ai_analysis":50}', 50, 100, 10),
    ('professional_monthly', 'الباقة الاحترافية', 99.00, 'USD', 'monthly', 1, '{"auto_pilot":1,"competitor_analysis":20,"multiple_websites":3,"chat_credits":500,"review_credits":50,"ai_analysis":200}', 200, 500, 50),
    ('professional_yearly', 'الباقة الاحترافية', 990.00, 'USD', 'yearly', 1, '{"auto_pilot":1,"competitor_analysis":20,"multiple_websites":3,"chat_credits":500,"review_credits":50,"ai_analysis":200}', 200, 500, 50),
    ('enterprise_monthly', 'الباقة المؤسسية', 299.00, 'USD', 'monthly', 1, '{"auto_pilot":1,"competitor_analysis":100,"multiple_websites":10,"chat_credits":2000,"review_credits":200,"ai_analysis":1000}', 1000, 2000, 200),
    ('enterprise_yearly', 'الباقة المؤسسية', 2990.00, 'USD', 'yearly', 1, '{"auto_pilot":1,"competitor_analysis":100,"multiple_websites":10,"chat_credits":2000,"review_credits":200,"ai_analysis":1000}', 1000, 2000, 200)
ON DUPLICATE KEY UPDATE `plan_code` = `plan_code`;

-- 6) subscriptions: إضافة أعمدة السكيما الجديدة (السيرفر يملكها؛ المحلي قديم)
ALTER TABLE `subscriptions`
    ADD COLUMN IF NOT EXISTS `plan_id` INT(11) DEFAULT NULL COMMENT 'مرجع لـ subscription_plans' AFTER `plan_type`,
    ADD COLUMN IF NOT EXISTS `current_period_start` DATETIME DEFAULT NULL AFTER `start_date`,
    ADD COLUMN IF NOT EXISTS `current_period_end` DATETIME DEFAULT NULL AFTER `current_period_start`,
    ADD COLUMN IF NOT EXISTS `trial_ends_at` DATETIME DEFAULT NULL AFTER `current_period_end`,
    ADD COLUMN IF NOT EXISTS `cancel_at_period_end` TINYINT(1) DEFAULT 0 AFTER `trial_ends_at`,
    ADD COLUMN IF NOT EXISTS `payment_gateway` VARCHAR(50) DEFAULT NULL AFTER `payment_method`,
    ADD COLUMN IF NOT EXISTS `gateway_subscription_id` VARCHAR(255) DEFAULT NULL AFTER `payment_gateway`,
    ADD COLUMN IF NOT EXISTS `gateway_customer_id` VARCHAR(255) DEFAULT NULL AFTER `gateway_subscription_id`,
    ADD COLUMN IF NOT EXISTS `usage_ai_analysis_count` INT(11) DEFAULT 0 AFTER `cancellation_reason`,
    ADD COLUMN IF NOT EXISTS `usage_ai_message_count` INT(11) DEFAULT 0 AFTER `usage_ai_analysis_count`,
    ADD COLUMN IF NOT EXISTS `usage_review_reply_count` INT(11) DEFAULT 0 AFTER `usage_ai_message_count`,
    ADD COLUMN IF NOT EXISTS `last_usage_reset_at` DATETIME DEFAULT NULL AFTER `usage_review_reply_count`;

-- 7) websites: أعمدة يعتمد عليها كود CRM المباشر (السيرفر يملكها)
ALTER TABLE `websites`
    ADD COLUMN IF NOT EXISTS `brand_name` VARCHAR(255) DEFAULT NULL COMMENT 'اسم العلامة' AFTER `company_name`,
    ADD COLUMN IF NOT EXISTS `domain` VARCHAR(255) DEFAULT NULL COMMENT 'النطاق' AFTER `brand_name`,
    ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) DEFAULT 1 COMMENT 'حالة الموقع' AFTER `is_verified`;

-- 8) ceo_risk_alerts: عمود source_module (كانت الجدول موجودًا بنسخة أقدم ناقصة)
ALTER TABLE `ceo_risk_alerts`
    ADD COLUMN IF NOT EXISTS `source_module` VARCHAR(50) DEFAULT NULL COMMENT 'reputation/crm/ads/competitor' AFTER `severity`;
