-- ============================================================
-- Tourfecto - Migration: محرك الحجز والتوفر (Booking & Availability Engine)
--
-- ملاحظة مهمة (دمج Phase 2 المُرسل): النسخة الأصلية اللي اتبعتت كانت
-- بتفترض جداول جديدة كليًا لـ payments/customer_wallets/notification_queue
-- بتتعارض مباشرة مع أنظمة شغّالة فعليًا في المشروع
-- (payment_transactions + PaymentGatewayInterface، wallet_transactions،
-- جدول notifications). اتشالوا من هنا عمدًا لمنع ازدواجية - راجع رد
-- الشات لتفاصيل السبب والخطوة التالية المطلوبة لكل واحد منهم.
--
-- الجداول هنا فقط: bookings, booking_items, booking_status_history,
-- inventory - مفاهيم جديدة فعليًا مالهاش مكافئ حالي في المشروع.
-- bookings.product_id بيشير لـ crm_products (الموديل الحالي لخدمات/جولات
-- الحساب) بدل مفهوم "product" منفصل كان مفترض في النسخة الأصلية.
-- @version 1.0.0  @date 2026-08-21
-- ============================================================

CREATE TABLE IF NOT EXISTS `bookings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `booking_reference` VARCHAR(32) NOT NULL COMMENT 'مرجع فريد يظهر للعميل - مش الـ id الداخلي',
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (الشركة) - نفس اصطلاح crm_products.user_id',
    `product_id` INT(11) NOT NULL COMMENT 'يشير إلى crm_products.id',
    `customer_id` INT(11) NULL DEFAULT NULL COMMENT 'يشير إلى crm_contacts.id لو موجود، NULL لحجز بدون CRM contact',
    `customer_name` VARCHAR(191) NOT NULL,
    `customer_phone` VARCHAR(32) NULL DEFAULT NULL,
    `customer_email` VARCHAR(191) NULL DEFAULT NULL,
    `start_date` DATE NOT NULL,
    `start_time` TIME NULL DEFAULT NULL,
    `adults_count` INT(11) NOT NULL DEFAULT 1,
    `children_count` INT(11) NOT NULL DEFAULT 0,
    `total_amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `status` ENUM('pending','confirmed','cancelled','completed','no_show') NOT NULL DEFAULT 'pending',
    `source` VARCHAR(30) NOT NULL DEFAULT 'direct' COMMENT 'direct / whatsapp / website / ota:<name>',
    `notes` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bookings_reference` (`booking_reference`),
    KEY `idx_bookings_user_id` (`user_id`),
    KEY `idx_bookings_product_id` (`product_id`),
    KEY `idx_bookings_start_date` (`start_date`),
    KEY `idx_bookings_status` (`status`),
    CONSTRAINT `fk_bookings_product` FOREIGN KEY (`product_id`) REFERENCES `crm_products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حجوزات السياح النهائيين على خدمات/جولات الحساب';

CREATE TABLE IF NOT EXISTS `booking_status_history` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `booking_id` INT(11) NOT NULL,
    `from_status` VARCHAR(20) NULL DEFAULT NULL,
    `to_status` VARCHAR(20) NOT NULL,
    `changed_by_user_id` INT(11) NULL DEFAULT NULL,
    `reason` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_booking_status_history_booking_id` (`booking_id`),
    CONSTRAINT `fk_booking_status_history_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل تغييرات حالة كل حجز (Audit Trail)';

CREATE TABLE IF NOT EXISTS `inventory` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `product_id` INT(11) NOT NULL COMMENT 'يشير إلى crm_products.id',
    `date` DATE NOT NULL,
    `capacity` INT(11) NOT NULL DEFAULT 0 COMMENT 'أقصى عدد حجوزات مسموح بيه في هذا التاريخ',
    `booked_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'بيتحدّث تلقائيًا مع كل حجز/إلغاء - لا يُعدَّل يدويًا',
    `price_override` DECIMAL(12,2) NULL DEFAULT NULL COMMENT 'سعر مخصص لليوم ده لو مختلف عن crm_products.price (تسعير ديناميكي)',
    `is_blocked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'إغلاق اليوم يدويًا حتى لو فيه سعة',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_inventory_product_date` (`product_id`, `date`),
    KEY `idx_inventory_user_id` (`user_id`),
    CONSTRAINT `fk_inventory_product` FOREIGN KEY (`product_id`) REFERENCES `crm_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='التوفر اليومي لكل خدمة/جولة';
