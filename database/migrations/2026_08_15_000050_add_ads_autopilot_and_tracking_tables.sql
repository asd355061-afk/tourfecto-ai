-- ============================================================
-- Tourfecto - Migration: Ads Autopilot + Pending Actions + Tracking
-- @version 1.0.0  @date 2026-08-15
--
-- يكمّل schema موديول الإعلانات الاحترافي (الـFrontend الجديد) بشكل
-- إضافي (non-destructive) فوق migrations الموديولات السابقة:
--   - أعمدة الحملات الجديدة (landing page / target countries / published)
--   - توسعة ad_optimization_logs لقابلية الـRollback وسجل القرارات
--   - جداول Autopilot (settings / pending actions / daily counters)
--   - جداول UTM Tracking / Market Research / Competitor Insights
-- ============================================================

-- ------------------------------------------------------------
-- 1) ad_campaigns: أعمدة جديدة للحملات الاحترافية
-- ------------------------------------------------------------
ALTER TABLE `ad_campaigns`
    ADD COLUMN IF NOT EXISTS `published_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'وقت النشر الفعلي على المنصة' AFTER `status`,
    ADD COLUMN IF NOT EXISTS `target_countries_json` JSON DEFAULT NULL COMMENT 'أكواد الدول المستهدفة (iso2) - مطلوبة كـGuardrail للـAutopilot' AFTER `target_audience_brief`,
    ADD COLUMN IF NOT EXISTS `landing_page_url` VARCHAR(500) DEFAULT NULL AFTER `target_countries_json`,
    ADD COLUMN IF NOT EXISTS `landing_page_last_analysis` LONGTEXT DEFAULT NULL COMMENT 'نتيجة تحليل صفحة الهبوط (JSON) من LandingPageAnalysisService' AFTER `landing_page_url`,
    ADD COLUMN IF NOT EXISTS `landing_page_analyzed_at` TIMESTAMP NULL DEFAULT NULL AFTER `landing_page_last_analysis`,
    ADD COLUMN IF NOT EXISTS `external_budget_resource` VARCHAR(255) DEFAULT NULL COMMENT 'Google Ads Campaign Budget resource name (القيمة الخام من GoogleAdsAPI)' AFTER `landing_page_analyzed_at`,
    ADD COLUMN IF NOT EXISTS `external_budget_resource_name` VARCHAR(255) DEFAULT NULL COMMENT 'Google Ads Campaign Budget resource name - مطلوب للـAutopilot لتعديل الميزانية بعد النشر' AFTER `external_budget_resource`;

-- الحالة 'removed' مطلوبة لإلغاء حملة منشورة على المنصة (cancelCampaign)
ALTER TABLE `ad_campaigns`
    MODIFY COLUMN `status` ENUM('draft','active','paused','completed','removed') NOT NULL DEFAULT 'draft';

-- ------------------------------------------------------------
-- 2) ad_optimization_logs: توسعة لقابلية الـRollback + ملكية السجل
-- ------------------------------------------------------------
ALTER TABLE `ad_optimization_logs`
    ADD COLUMN IF NOT EXISTS `user_id` INT(11) DEFAULT NULL COMMENT 'صاحب قرار التحسين (مطلوب لسرد سجل المستخدم والتحقق من ملكية Rollback)' AFTER `campaign_id`,
    ADD COLUMN IF NOT EXISTS `mode` ENUM('manual','approval','autopilot','rollback') NOT NULL DEFAULT 'manual' AFTER `action_type`,
    ADD COLUMN IF NOT EXISTS `before_value` VARCHAR(100) DEFAULT NULL COMMENT 'القيمة قبل التغيير (ميزانية أو حالة)' AFTER `description`,
    ADD COLUMN IF NOT EXISTS `after_value` VARCHAR(100) DEFAULT NULL COMMENT 'القيمة بعد التغيير' AFTER `before_value`,
    ADD COLUMN IF NOT EXISTS `can_rollback` TINYINT(1) NOT NULL DEFAULT 0 AFTER `applied_automatically`,
    ADD COLUMN IF NOT EXISTS `external_result` VARCHAR(500) DEFAULT NULL COMMENT 'نتيجة التنفيذ على المنصة الفعلية' AFTER `can_rollback`,
    ADD COLUMN IF NOT EXISTS `rolled_back_at` TIMESTAMP NULL DEFAULT NULL AFTER `external_result`,
    ADD COLUMN IF NOT EXISTS `rollback_of_log_id` INT(11) DEFAULT NULL COMMENT 'لو صف Rollback - بيشير لصف السجل الأصلي اللي اترجع عنه' AFTER `rolled_back_at`;

-- الفهرس والـFK محميان بـinformation_schema (لا يوجد ADD INDEX/CONSTRAINT IF NOT EXISTS في MariaDB)
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_optimization_logs'
      AND INDEX_NAME = 'idx_user_id'
);
SET @idx_sql := IF(@idx_exists = 0,
    'ALTER TABLE `ad_optimization_logs` ADD INDEX `idx_user_id` (`user_id`)',
    'SELECT 1');
PREPARE idx_stmt FROM @idx_sql;
EXECUTE idx_stmt;
DEALLOCATE PREPARE idx_stmt;

SET @fk_log_user_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_optimization_logs'
      AND CONSTRAINT_NAME = 'fk_optimization_log_user' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @fk_log_user_sql := IF(@fk_log_user_exists = 0,
    'ALTER TABLE `ad_optimization_logs` ADD CONSTRAINT `fk_optimization_log_user`
     FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL',
    'SELECT 1');
PREPARE fk_log_user_stmt FROM @fk_log_user_sql;
EXECUTE fk_log_user_stmt;
DEALLOCATE PREPARE fk_log_user_stmt;

-- 'resume_campaign' (إيقاف/استئناف يدوي) و 'rollback' مطلوبان من الموديول
ALTER TABLE `ad_optimization_logs`
    MODIFY COLUMN `action_type` ENUM(
        'increase_budget','decrease_budget','pause_campaign','resume_campaign',
        'rotate_ad_copy','add_keywords','add_negative_keywords',
        'narrow_audience','broaden_audience','no_action_recommended','rollback'
    ) NOT NULL;

-- ------------------------------------------------------------
-- 3) جدول إعدادات الـAutopilot (Guardrails)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ad_autopilot_settings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `optimization_mode` ENUM('manual','approval','autopilot') NOT NULL DEFAULT 'manual',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `max_daily_budget` DECIMAL(12,2) DEFAULT NULL COMMENT 'سقف الميزانية اليومية لأي حملة - التلقائي مش بيكسره أبدًا',
    `max_budget_increase_pct` DECIMAL(5,2) NOT NULL DEFAULT 20.00,
    `max_budget_decrease_pct` DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    `max_allowed_cpa` DECIMAL(12,2) DEFAULT NULL COMMENT 'سقف تكلفة الاكتساب - تجاوزه = تقليص تلقائي',
    `min_required_roas` DECIMAL(10,2) DEFAULT NULL COMMENT 'حد أدنى للعائد على الإنفاق الإعلاني',
    `max_changes_per_day` INT(11) NOT NULL DEFAULT 3,
    `allowed_campaign_ids_json` JSON DEFAULT NULL,
    `allowed_platforms_json` JSON DEFAULT NULL,
    `allowed_countries_json` JSON DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_user_id` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إعدادات/حدود الـAutopilot لكل مستخدم';

-- ------------------------------------------------------------
-- 4) جدول القرارات المعلّقة بانتظار الموافقة
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ad_pending_actions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `campaign_id` INT(11) NOT NULL,
    `action_type` ENUM('increase_budget','decrease_budget','pause_campaign','resume_campaign') NOT NULL,
    `before_value` VARCHAR(100) DEFAULT NULL,
    `after_value` VARCHAR(100) DEFAULT NULL,
    `reasoning` TEXT DEFAULT NULL,
    `confidence_level` VARCHAR(20) DEFAULT NULL,
    `blocked_reason` VARCHAR(500) DEFAULT NULL COMMENT 'لو القرار اتجاوز Guardrails - سبب التحويل للموافقة',
    `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `decided_by_user_id` INT(11) DEFAULT NULL,
    `decided_at` TIMESTAMP NULL DEFAULT NULL,
    `executed_log_id` INT(11) DEFAULT NULL COMMENT 'رقم سجل ad_optimization_logs للتنفيذ الفعلي بعد الموافقة',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قرارات Autopilot المعلّقة بانتظار موافقة العميل';

-- ------------------------------------------------------------
-- 5) عداد التغييرات اليومي (max_changes_per_day)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ad_autopilot_daily_counters` (
    `user_id` INT(11) NOT NULL,
    `counter_date` DATE NOT NULL,
    `changes_executed` INT(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`user_id`, `counter_date`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='عداد التغييرات التلقائية اليومية لكل مستخدم';

-- ------------------------------------------------------------
-- 6) روابط UTM القابلة للتتبع (بند 18)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ad_utm_links` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `code` VARCHAR(20) NOT NULL COMMENT 'كود قصير فريد يستخدم في /r/{code}',
    `destination_url` VARCHAR(1000) NOT NULL,
    `utm_source` VARCHAR(100) DEFAULT NULL,
    `utm_medium` VARCHAR(100) DEFAULT NULL,
    `utm_campaign` VARCHAR(100) DEFAULT NULL,
    `utm_content` VARCHAR(255) DEFAULT NULL,
    `utm_term` VARCHAR(255) DEFAULT NULL,
    `clicks` INT(11) NOT NULL DEFAULT 0 COMMENT 'عدد النقرات الحقيقية المسجلة',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_code` (`code`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='روابط UTM قصيرة للحملات الإعلانية مع تتبع النقرات';

-- ------------------------------------------------------------
-- 7) أبحاث السوق (بند 15)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ad_market_research` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `campaign_id` INT(11) DEFAULT NULL,
    `goal_description` VARCHAR(2000) NOT NULL,
    `result_json` LONGTEXT NOT NULL COMMENT 'نتيجة البحث الكاملة (JSON) - شامل/منافسين/أسعار/اقتراحات',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تاريخ أبحاث السوق لكل عميل';

-- ------------------------------------------------------------
-- 8) رؤى إعلانية عن المنافسين (بند 16)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ad_competitor_insights` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) DEFAULT NULL,
    `competitor_id` INT(11) NOT NULL,
    `offer_description` VARCHAR(2000) NOT NULL,
    `insights_json` LONGTEXT NOT NULL COMMENT 'الرؤى الكاملة (JSON) - استراتيجيات/نصوص/زوايا مقترحة',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE,
    INDEX `idx_website` (`website_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='رؤى إعلانية محللة عن منافس معين (أحدث تحليل لكل موقع)';
