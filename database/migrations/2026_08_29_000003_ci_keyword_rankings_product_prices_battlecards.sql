-- ============================================================
-- Tourfecto - Migration: Competitor Intelligence M5
-- @date 2026-08-29
--
-- يغلق 3 فجوات من COMPETITIVE_ANALYSIS_CompetitorIntelligence.md:
--   G1 تتبع ترتيب الكلمات المفتاحية (SERP Keyword Rankings)
--   G6 Battlecards لإعداد فريق المبيعات
--   G7 تتبع أسعار لكل منتج/SKU بجدولة منتظمة
--
-- كل الجداول CREATE TABLE IF NOT EXISTS - غير هدّامة، آمنة على قاعدة
-- بيانات حية بها بيانات فعلية (نفس نمط باقي ميجريشنات الموديول).
-- ============================================================

-- ------------------------------------------------------------
-- G1: تتبع ترتيب الكلمات المفتاحية في نتائج البحث (SERP)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ci_keyword_rankings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `competitor_id` INT(11) NOT NULL,
    `keyword` VARCHAR(255) NOT NULL,
    `position` SMALLINT UNSIGNED DEFAULT NULL COMMENT 'ترتيب الظهور (1-100)؛ NULL = خارج أول 100 نتيجة أو غير محسوب',
    `url` VARCHAR(1000) DEFAULT NULL COMMENT 'الرابط الذي ظهر عليه المنافس',
    `source` VARCHAR(100) NOT NULL DEFAULT 'manual' COMMENT 'مصدر القياس: manual / integration:{name}',
    `checked_at` DATETIME NOT NULL COMMENT 'وقت القياس الفعلي',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ci_kr_competitor_keyword` (`competitor_id`, `keyword`, `checked_at`),
    KEY `idx_ci_kr_keyword` (`keyword`),
    CONSTRAINT `fk_ci_kr_competitor` FOREIGN KEY (`competitor_id`)
        REFERENCES `competitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل قياسات ترتيب الكلمات المفتاحية للمنافس عبر الزمن';

-- ------------------------------------------------------------
-- G7: أسعار منتجات/SKUs المنافس مع سجل زمني
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ci_product_prices` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `competitor_id` INT(11) NOT NULL,
    `product_name` VARCHAR(255) NOT NULL COMMENT 'اسم المنتج/SKU - نص مستخرج من الصفحة أو إدخال يدوي',
    `price` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(8) NOT NULL DEFAULT 'USD',
    `source_url` VARCHAR(1000) DEFAULT NULL,
    `page_type` VARCHAR(30) DEFAULT NULL COMMENT 'الصفحة التي رُصد منها السعر: pricing/products/offers',
    `detected_at` DATETIME NOT NULL COMMENT 'وقت الرصد',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ci_pp_competitor_product` (`competitor_id`, `product_name`, `detected_at`),
    KEY `idx_ci_pp_product` (`product_name`),
    CONSTRAINT `fk_ci_pp_competitor` FOREIGN KEY (`competitor_id`)
        REFERENCES `competitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل أسعار منتجات/SKUs المنافس عبر الزمن';

-- ------------------------------------------------------------
-- G6: بطاقات المعركة (Battlecards) لإعداد فريق المبيعات
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ci_battlecards` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `competitor_id` INT(11) NOT NULL,
    `title` VARCHAR(500) NOT NULL,
    `positioning_summary` TEXT DEFAULT NULL,
    `strengths` JSON DEFAULT NULL COMMENT 'قائمة نقاط قوة المنافس',
    `weaknesses` JSON DEFAULT NULL COMMENT 'قائمة نقاط ضعف المنافس',
    `price_position` JSON DEFAULT NULL COMMENT 'موقف المنافس السعري: منتجات + أسعار + اتجاه',
    `content_position` JSON DEFAULT NULL COMMENT 'موقف المنافس المحتوى/النشاط',
    `recommended_actions` JSON DEFAULT NULL COMMENT 'توصيات إجرائية للفريق',
    `evidence` JSON DEFAULT NULL COMMENT 'أدلة حقيقية (scorecard/insights/تغييرات)',
    `generated_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ci_bc_user_competitor` (`user_id`, `competitor_id`, `generated_at`),
    KEY `idx_ci_bc_competitor` (`competitor_id`),
    CONSTRAINT `fk_ci_bc_user` FOREIGN KEY (`user_id`)
        REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ci_bc_competitor` FOREIGN KEY (`competitor_id`)
        REFERENCES `competitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بطاقات معركة المنافسين لتجهيز فريق المبيعات';
