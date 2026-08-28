-- ============================================================
-- Tourfecto - Migration: Booking Ad Attribution (CAPI)
-- @version 1.0.0  @date 2026-08-28
--
-- ربط الحجوزات برابط UTM الإعلاني اللي اتعمله كليك قبل الحجز
-- (نافذة إسناد 30 يوم). العمود بيخزّن id من جدول ad_utm_links
-- فقط (مش أي بيانات شخصية)، وبيعتمد عليه:
--   1) حساب ROAS الحقيقي من الحجوزات المرتبطة.
--   2) إرسال حدث تحويل CAPI غير متزامن عند تأكيد الحجز.
--
-- Idempotent على MariaDB 10.11: ADD COLUMN/INDEX IF NOT EXISTS،
-- وقيود FK محمية بفحص information_schema (لا يوجد ADD CONSTRAINT
-- IF NOT EXISTS في MariaDB).
-- ============================================================

ALTER TABLE `bookings`
    ADD COLUMN IF NOT EXISTS `attributed_utm_link_id` INT(11) NULL DEFAULT NULL
        COMMENT 'ad_utm_links.id لرابط الإعلان اللي اتعمله كليك قبل الحجز (نافذة 30 يوم)'
        AFTER `source`,
    ADD INDEX IF NOT EXISTS `idx_bookings_attributed_utm` (`attributed_utm_link_id`);

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'bookings'
      AND CONSTRAINT_NAME = 'fk_bookings_attributed_utm_link'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @fk_sql := IF(@fk_exists = 0,
    'ALTER TABLE `bookings` ADD CONSTRAINT `fk_bookings_attributed_utm_link`
     FOREIGN KEY (`attributed_utm_link_id`) REFERENCES `ad_utm_links` (`id`) ON DELETE SET NULL',
    'SELECT 1');

PREPARE fk_stmt FROM @fk_sql;
EXECUTE fk_stmt;
DEALLOCATE PREPARE fk_stmt;
