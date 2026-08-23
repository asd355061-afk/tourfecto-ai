-- ============================================================
-- Tourfecto - Migration: صفحة الإعدادات الاحترافية
-- (صورة شخصية + تفضيلات إشعارات حقيقية بدل قيم وهمية)
-- @version 1.0.0  @date 2026-07-19
-- ============================================================

ALTER TABLE `users`
    ADD COLUMN `avatar_url` VARCHAR(500) DEFAULT NULL COMMENT 'رابط الصورة الشخصية (نسبي من جذر الموقع)' AFTER `company_name`,
    ADD COLUMN `notify_email` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'تفعيل إشعارات البريد الإلكتروني',
    ADD COLUMN `notify_chat` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'تفعيل إشعارات المحادثات',
    ADD COLUMN `notify_reviews` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'تفعيل إشعارات المراجعات الجديدة';

-- ============================================================
-- إصلاح إضافي: GeminiClient::logUsage() بيسجّل استخدام كل استدعاء
-- لـ Gemini API (توليد مقالات، تحليل SEO...) لكن من غير user_id خالص
-- (الكلاس مستوى منخفض ومش عنده سياق المستخدم بسهولة). بما إن العمود
-- كان NOT NULL من غير default، كل عملية INSERT كانت بتفشل بصمت
-- (بتتسجل كـ Query Error في app.log بس التوليد نفسه بينجح، فمحدش كان
-- بيلاحظ). نخليه NULL مسموح بدل ما نغيّر GeminiClient دلوقتي.
-- ============================================================
ALTER TABLE `api_usage_logs`
    MODIFY COLUMN `user_id` INT(11) NULL DEFAULT NULL COMMENT 'معرف المستخدم (ممكن يكون NULL لعمليات مستوى-نظام زي GeminiClient الحالي)';
