-- ============================================================
-- Tourfecto - Migration: ربط Google Ads حقيقي + تمييز منصّة كل حملة
-- @version 1.0.0  @date 2026-08-08
--
-- ad_keywords / platform_connections (platform=google_ads) كانوا
-- موجودين مسبقًا من migration 2026_07_14_000010 لكن من غير أي كود
-- فعلي يستخدمهم. المطلوب هنا فقط عمود `platform` على ad_campaigns
-- عشان نعرف الحملة دي معمولة لـ Meta Ads ولا Google Ads ولا يدوية،
-- حتى قبل ما تتربط/تتزامن مع أي حساب فعلي (platform_connection_id
-- بيفضل NULL لحد ما تتزامن).
-- ============================================================

ALTER TABLE `ad_campaigns`
    ADD COLUMN `platform` ENUM('manual','meta_ads','google_ads') NOT NULL DEFAULT 'manual' AFTER `platform_connection_id`,
    ADD INDEX `idx_platform` (`platform`);

-- تصنيف الحملات الحالية اللي بالفعل مرتبطة بحساب Meta/Google (لو كانت موجودة من قبل هذا الإصدار)
UPDATE `ad_campaigns` c
    INNER JOIN `platform_connections` pc ON pc.id = c.platform_connection_id
    SET c.platform = pc.platform
    WHERE pc.platform IN ('meta_ads', 'google_ads');
