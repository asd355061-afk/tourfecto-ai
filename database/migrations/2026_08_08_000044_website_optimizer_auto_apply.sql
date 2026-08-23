-- ============================================================
-- Tourfecto - Migration: ربط الـ Website Optimizer بمواقع الـ Website
-- Builder، عشان Auto-Apply حقيقي يبقى ممكن (Phase 5)
--
-- إضافي بالكامل (Additive) - مفيش أي عمود أو جدول أو بيانات اتحذفت.
-- ============================================================

ALTER TABLE `wo_audits`
    ADD COLUMN `generated_website_id` BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'لو الرابط اللي اتفحص بيتبع موقع اتعمل بالـ Website Builder بتاعنا - عشان Auto-Apply يبقى ممكن فعليًا (عندنا صلاحية كتابة)'
        AFTER `website_id`,
    ADD INDEX `idx_wo_audits_generated_website` (`generated_website_id`);

ALTER TABLE `wo_fixes`
    ADD COLUMN `applied_by` VARCHAR(20) NULL DEFAULT NULL
        COMMENT 'user = العميل علّم الإصلاح applied يدويًا، auto_pilot = النظام طبّقه تلقائيًا فعليًا'
        AFTER `status`,
    ADD COLUMN `suggested_value` TEXT NULL DEFAULT NULL
        COMMENT 'القيمة الجاهزة للتطبيق التلقائي (مثلاً نص الـ title/description المقترح) - Nullable، مبني بس لأنواع الإصلاحات القابلة للتطبيق الآلي',
    ADD COLUMN `check_key` VARCHAR(50) NULL DEFAULT NULL
        COMMENT 'مفتاح نوع الإصلاح (title_tag/meta_description/...) - كان غير مخزّن أصلًا على wo_fixes (بس على wo_audit_findings)، لازم نعرفه هنا عشان Auto-Apply يعرف يتعامل مع أنهي عمود يكتب فيه';
