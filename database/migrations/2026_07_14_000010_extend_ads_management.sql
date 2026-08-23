-- ============================================================
-- Tourfecto - Migration: توسعة إدارة الإعلانات من ai-ads-management-hub
-- @version 1.0.0  @date 2026-07-14
--
-- ملاحظة: جدول `ad_accounts` الأصلي (اتصال OAuth بمنصات الإعلانات)
-- اتجوهل تمامًا - `platform_connections` الموجود عندك فعلاً (ومُوسَّع
-- مسبقًا ليشمل google_ads/meta_ads) بيغطي نفس الغرض بالظبط.
-- ============================================================

ALTER TABLE `ad_campaigns`
    ADD COLUMN `product_or_service` TEXT DEFAULT NULL COMMENT 'نص حر يُستخدم كأساس توليد الذكاء الاصطناعي' AFTER `objective`,
    ADD COLUMN `target_audience_brief` TEXT DEFAULT NULL AFTER `product_or_service`,
    ADD COLUMN `budget_total` DECIMAL(12,2) DEFAULT NULL AFTER `daily_budget`,
    ADD COLUMN `start_date` DATE DEFAULT NULL AFTER `budget_total`,
    ADD COLUMN `end_date` DATE DEFAULT NULL AFTER `start_date`,
    ADD COLUMN `ai_generated` TINYINT(1) NOT NULL DEFAULT 0 AFTER `end_date`,
    ADD COLUMN `auto_optimize` TINYINT(1) NOT NULL DEFAULT 0 AFTER `ai_generated`;

CREATE TABLE IF NOT EXISTS `ad_copies` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `headline` VARCHAR(255) DEFAULT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `primary_text` TEXT DEFAULT NULL COMMENT 'نص أطول لمنصات Meta/LinkedIn/TikTok',
    `call_to_action` VARCHAR(100) DEFAULT NULL,
    `variant_label` VARCHAR(50) DEFAULT NULL COMMENT 'A/B/C لاختبار التنويعات',
    `ai_generated` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('pending_review','approved','rejected','live') NOT NULL DEFAULT 'pending_review',
    `performance_score` DECIMAL(5,2) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='نصوص إعلانية مولّدة بالذكاء الاصطناعي';

CREATE TABLE IF NOT EXISTS `ad_keywords` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `keyword` VARCHAR(255) NOT NULL,
    `match_type` ENUM('exact','phrase','broad','negative') NOT NULL DEFAULT 'broad',
    `ai_relevance_score` DECIMAL(5,2) DEFAULT NULL,
    `estimated_search_volume` INT(11) DEFAULT NULL,
    `estimated_cpc` DECIMAL(10,2) DEFAULT NULL,
    `ai_generated` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='كلمات مفتاحية مستهدفة لحملة إعلانية (بحث مدفوع، تختلف عن tracked_keywords العضوية)';

CREATE TABLE IF NOT EXISTS `ad_audiences` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `age_min` TINYINT(3) UNSIGNED DEFAULT NULL,
    `age_max` TINYINT(3) UNSIGNED DEFAULT NULL,
    `genders` VARCHAR(50) DEFAULT NULL,
    `locations_json` JSON DEFAULT NULL,
    `interests_json` JSON DEFAULT NULL,
    `estimated_reach` BIGINT UNSIGNED DEFAULT NULL,
    `ai_generated` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جماهير مستهدفة مقترحة لحملة إعلانية';

CREATE TABLE IF NOT EXISTS `ad_budget_recommendations` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `recommended_daily_budget` DECIMAL(12,2) NOT NULL,
    `bid_strategy` VARCHAR(100) DEFAULT NULL,
    `reasoning` TEXT DEFAULT NULL,
    `confidence_score` DECIMAL(5,2) DEFAULT NULL COMMENT '0-100',
    `applied` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='توصيات ميزانية مولّدة بالذكاء الاصطناعي';

CREATE TABLE IF NOT EXISTS `ad_performance_reports` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `date_start` DATE NOT NULL,
    `date_end` DATE NOT NULL,
    `impressions` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `clicks` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `conversions` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `spend` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `revenue` DECIMAL(12,2) DEFAULT NULL,
    `ctr` DECIMAL(6,4) DEFAULT NULL,
    `cpc` DECIMAL(10,4) DEFAULT NULL,
    `roas` DECIMAL(10,4) DEFAULT NULL,
    `synced_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign_dates` (`campaign_id`, `date_start`, `date_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تقارير أداء الحملات - تحتاج مزامنة حقيقية عبر platform_connections لاحقًا';

CREATE TABLE IF NOT EXISTS `ad_optimization_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `action_type` ENUM(
        'increase_budget','decrease_budget','pause_campaign',
        'rotate_ad_copy','add_keywords','add_negative_keywords',
        'narrow_audience','broaden_audience','no_action_recommended'
    ) NOT NULL,
    `description` TEXT NOT NULL,
    `ai_confidence` DECIMAL(5,2) DEFAULT NULL,
    `applied_automatically` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل قرارات تحسين الحملات';
