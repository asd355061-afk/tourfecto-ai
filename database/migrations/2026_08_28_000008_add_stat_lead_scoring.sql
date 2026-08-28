-- ============================================================
-- Tourfecto - Migration: Statistical AI Lead Scoring (بند 6)
-- @version 1.0.0  @date 2026-08-28
--
-- طبقة إحصائية شفافة فوق التقييم Rule-based القائم (CrmLeadScoringService):
-- تحسب احتمال تحويل كل Lead من معدل التحويل التجريبي الحقيقي لمصدره
-- (من سجل الحساب نفسه - leads وصلت لقرار نهائي أو صفقة مغلقة)، مع فاصل
-- ثقة Wilson وحجم عينة، وتخزّن النتيجة في أعمدة جديدة Additive فقط
-- (لا تُعدّل score/priority/score_reason الخاصة بالتقييم القديم).
--
-- Idempotent على MariaDB 10.11 عبر ADD COLUMN IF NOT EXISTS.
-- ============================================================

ALTER TABLE `crm_leads`
    ADD COLUMN IF NOT EXISTS `conv_probability` DECIMAL(5,4) NULL DEFAULT NULL
        COMMENT 'احتمال التحويل الإحصائي (0-1) من معدل تحويل مصدر الـLead في بيانات الحساب (بند 6)'
        AFTER `score_reason`;

ALTER TABLE `crm_leads`
    ADD COLUMN IF NOT EXISTS `score_confidence` ENUM('low','moderate','high') NULL DEFAULT NULL
        COMMENT 'ثقة النتيجة الإحصائية من حجم العينة (بند 6)'
        AFTER `conv_probability`;

ALTER TABLE `crm_leads`
    ADD COLUMN IF NOT EXISTS `score_signals_json` JSON NULL DEFAULT NULL
        COMMENT 'إشارات إحصائية مخزنة فقط (عينة/تحويلات/فاصل Wilson) - ليست توصيات كحقيقة (بند 6)'
        AFTER `score_confidence`;
