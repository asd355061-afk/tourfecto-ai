-- ============================================================
-- Tourfecto - Migration: دعم كامل لإدارة الحملة بعد النشر
-- (إيقاف/تشغيل، تعديل ميزانية، إلغاء) - محتاجين نحفظ محليًا معرّفات
-- فرعية (Ad Set على Meta، Campaign Budget resource على Google) عشان
-- نقدر نعدّل عليها بعد كده من غير ما نعيد إنشاء الحملة من الصفر.
-- @version 1.0.0  @date 2026-08-10
-- ============================================================

ALTER TABLE `ad_campaigns`
    ADD COLUMN `external_adset_id` VARCHAR(64) DEFAULT NULL AFTER `external_campaign_id` COMMENT 'Meta Ad Set ID - لازم لتعديل الميزانية بعد النشر',
    ADD COLUMN `external_budget_resource` VARCHAR(255) DEFAULT NULL AFTER `external_adset_id` COMMENT 'Google Ads Campaign Budget resource name - لازم لتعديل الميزانية بعد النشر';
