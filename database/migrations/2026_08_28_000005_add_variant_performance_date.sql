-- ============================================================
-- Tourfecto - Migration: Ad/Variant Performance Reporting (بند 3)
-- @version 1.0.0  @date 2026-08-28
--
-- إضافة عمود تاريخ الأداء `recorded_on` إلى ad_creative_variants عشان
-- تقارير الفترة (weekly/monthly) على مستوى الإعلان/الـ variant تقدر
-- تقطع البيانات الحقيقية لنافذة زمنية فعلية بدل عرض كل الأوقات.
--
-- Additive فقط: عمود NULL جديد + backfill بتاريخ الإنشاء للصفوف القديمة
-- (بيانات موجودة مش بتتغير). Idempotent على MariaDB 10.11 عبر
-- ADD COLUMN IF NOT EXISTS.
-- ============================================================

ALTER TABLE `ad_creative_variants`
    ADD COLUMN IF NOT EXISTS `recorded_on` DATE NULL DEFAULT NULL
        COMMENT 'تاريخ الأداء - فترة التقارير على مستوى الإعلان/الـ variant (بند 3)'
        AFTER `is_control`;

-- Backfill للصفوف القائمة: التاريخ = تاريخ الإنشاء (بيانات فعلية بلا اختراع)
UPDATE `ad_creative_variants` SET `recorded_on` = DATE(`created_at`) WHERE `recorded_on` IS NULL;

ALTER TABLE `ad_creative_variants`
    ADD INDEX IF NOT EXISTS `idx_variant_recorded_on` (`user_id`, `recorded_on`);
