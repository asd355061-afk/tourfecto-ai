-- ============================================================
-- Tourfecto - Migration: CRM Sales Sequences (المرحلة 15 - G12)
-- @version 1.0.0  @date 2026-08-16
--
-- تسلسلات مبيعات متعددة الخطوات (Sales Sequences) - سد فجوة 2.5
-- "Sequences متعددة الخطوات: ❌". كل Sequence سلسلة خطوات مؤجلة
-- (متابعة/مكالمة/إيميل...) تُنفَّذ على Lead/Contact بترتيب زمني.
--
-- Additive: جدولان جديدان فقط - لا يمس CrmAutomationRule القائم.
-- الخطوات تُخزَّن كـJSON ضمن sequence للبساطة (نفس نمط actions
-- في crm_automation_rules).
-- ============================================================

CREATE TABLE IF NOT EXISTS `crm_sequences` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `name` VARCHAR(150) NOT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `steps` TEXT NOT NULL COMMENT 'JSON: [{"type":"task","delay_days":1,"title":"..."},{"type":"email","delay_days":3,...}]',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_seq_user` (`user_id`),
    CONSTRAINT `fk_crm_seq_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تسلسلات مبيعات متعددة الخطوات - المرحلة 15 (G12)';

CREATE TABLE IF NOT EXISTS `crm_sequence_enrollments` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `sequence_id` INT(11) NOT NULL,
    `related_type` VARCHAR(30) DEFAULT NULL COMMENT 'contact / lead / deal',
    `related_id` INT(11) DEFAULT NULL,
    `current_step` INT(11) NOT NULL DEFAULT 0 COMMENT 'فهرس الخطوة الحالية (يبدأ من 0)',
    `next_run_at` DATETIME DEFAULT NULL COMMENT 'موعد تنفيذ الخطوة التالية',
    `status` ENUM('active', 'completed', 'paused', 'cancelled') NOT NULL DEFAULT 'active',
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_seq_enr_user` (`user_id`),
    INDEX `idx_crm_seq_enr_seq` (`sequence_id`),
    INDEX `idx_crm_seq_enr_status` (`status`),
    CONSTRAINT `fk_crm_seq_enr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_seq_enr_seq` FOREIGN KEY (`sequence_id`) REFERENCES `crm_sequences` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تسجيلات الـSequences الجارية - المرحلة 15 (G12)';
