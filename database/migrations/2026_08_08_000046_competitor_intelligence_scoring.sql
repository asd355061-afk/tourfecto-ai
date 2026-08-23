-- ============================================================
-- Tourfecto - Migration: Competitor Intelligence scoring (Phase 7)
-- إضافي بالكامل. بيسد الفجوة اللي الكود نفسه موثّقها من قبل: عمود
-- last_analyzed_at كان مفقود من جدول competitors أصلًا (تعليق موجود في
-- CompetitorAnalysisService.php يوضح كده).
-- ============================================================

ALTER TABLE `competitors`
    ADD COLUMN `competitor_score` TINYINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'درجة تقديرية 0-100 لقوة الحضور الرقمي للمنافس بناءً على آخر تحليل' AFTER `is_active`,
    ADD COLUMN `my_score` TINYINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'نفس الدرجة لموقعي وقت نفس التحليل - للمقارنة المباشرة' AFTER `competitor_score`,
    ADD COLUMN `last_analyzed_at` TIMESTAMP NULL DEFAULT NULL
        COMMENT 'آخر مرة اتعمل فيها تحليل فعلي للمنافس ده' AFTER `my_score`;
