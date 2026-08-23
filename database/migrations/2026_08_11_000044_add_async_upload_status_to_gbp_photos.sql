-- ============================================
-- Tourfecto - Migration: Async Photo Upload Support
-- إضافة عمودين بس عشان الرفع يبقى Async حقيقي عبر نظام الطابور
-- الموجود بالفعل (jobs table) بدل ما يستنى المستخدم رد Google API
-- وهو واقف على الصفحة (بند "Performance" في السبيك الأصلي).
-- @version 1.0.0
-- @date 2026-08-11 (GBP Module Upgrade - Round 6: Async Photo Upload)
-- ============================================

ALTER TABLE `gbp_photos`
    ADD COLUMN `status` ENUM('uploading','ready','failed') NOT NULL DEFAULT 'ready' COMMENT 'uploading = لسه بيترفع لجوجل في الخلفية' AFTER `is_primary`,
    ADD COLUMN `error_message` TEXT NULL DEFAULT NULL COMMENT 'رسالة الخطأ لو فشل الرفع على Google' AFTER `status`;
