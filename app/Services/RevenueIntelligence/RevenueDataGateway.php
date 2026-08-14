<?php
/**
 * Tourfecto - Revenue Intelligence Data Gateway
 * @version 1.0.0
 *
 * طبقة القراءة المركزية (Repository) لموديول Revenue Intelligence.
 * كل الوصول لبيانات إيرادات حقيقية بيعدّي من هنا فقط - لا يوجد أي
 * كلاس تاني في الموديول بيعمل SQL مباشر على جداول تانية. هذا يضمن:
 *   1) Tenant Isolation: كل استعلام هنا مقيّد بـ user_id إجباريًا.
 *   2) لا تكرار منطق الاستعلامات في كل Service.
 *   3) لو احتجنا نغيّر مصدر بيانات (مثلاً orders حقيقية بدل الإدخال
 *      اليدوي rev_revenue_records) نغيّره في مكان واحد فقط.
 *
 * لا يعيد هذا الكلاس بناء CRM/Analytics/Ads/Billing - فقط "يقرأ" من
 * جداولها الموجودة فعلاً (rev_revenue_records, rev_marketing_spend,
 * ad_campaigns, crm_deals, crm_contacts, crm_leads, crm_pipeline_stages,
 * subscriptions) عبر Database::query() مباشرة (بنفس أسلوب باقي
 * Controllers في المشروع)، بدون ORM إضافي غير ضروري.
 */
class RevenueDataGateway {
    /** @var Database */
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // ============================================================
    // Revenue Records (rev_revenue_records) - BATCH6
    // ============================================================

    /** كل سجلات الإيراد الفعلية لمستخدم معيّن ضمن فترة زمنية. */
    public function getRevenueRecords(int $userId, string $fromDate, string $toDate): array {
        return $this->db->query(
            "SELECT source, reference_id, amount, currency, recorded_at, notes
             FROM rev_revenue_records
             WHERE user_id = ? AND recorded_at >= ? AND recorded_at < ?
             ORDER BY recorded_at ASC",
            [$userId, $fromDate, $toDate]
        );
    }

    /** إجمالي الإيراد + عدد السجلات ضمن فترة. */
    /**
     * Section 21 (No Fake Data - extends to "no silently wrong data" too):
     * SUM(amount) across records only makes sense if they're all the same
     * currency. Rather than silently adding e.g. $50 + €50 = "100", we
     * detect a currency mix and surface it explicitly so the UI/AI
     * assistant can warn instead of showing a misleading total. No FX
     * conversion is attempted (no exchange-rate data source exists in the
     * project), so a mixed-currency period cannot be honestly summed at all.
     */
    public function getRevenueTotals(int $userId, string $fromDate, string $toDate): array {
        $row = $this->db->query(
            "SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS count, COUNT(DISTINCT currency) AS currency_count,
                    MIN(currency) AS single_currency
             FROM rev_revenue_records WHERE user_id = ? AND recorded_at >= ? AND recorded_at < ?",
            [$userId, $fromDate, $toDate]
        );
        $currencyCount = (int) ($row[0]['currency_count'] ?? 0);
        return [
            'total' => (float) ($row[0]['total'] ?? 0),
            'count' => (int) ($row[0]['count'] ?? 0),
            'mixed_currency' => $currencyCount > 1,
            'currency' => $currencyCount === 1 ? $row[0]['single_currency'] : null,
        ];
    }

    /** سلسلة إيراد يومية (لأغراض الرسم البياني/الـ Forecast/الـ Anomaly Detection). */
    public function getDailyRevenueSeries(int $userId, string $fromDate, string $toDate): array {
        $rows = $this->db->query(
            "SELECT DATE(recorded_at) AS d, SUM(amount) AS revenue, COUNT(*) AS records
             FROM rev_revenue_records
             WHERE user_id = ? AND recorded_at >= ? AND recorded_at < ?
             GROUP BY DATE(recorded_at) ORDER BY d ASC",
            [$userId, $fromDate, $toDate]
        );
        return array_map(static function ($r) {
            return ['date' => $r['d'], 'revenue' => (float) $r['revenue'], 'records' => (int) $r['records']];
        }, $rows);
    }

    /** الإيراد مجمّع حسب المصدر (booking/order/subscription/manual...) - المصدر الحقيقي الوحيد المتاح. */
    public function getRevenueBySource(int $userId, string $fromDate, string $toDate): array {
        return $this->db->query(
            "SELECT source, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS count
             FROM rev_revenue_records
             WHERE user_id = ? AND recorded_at >= ? AND recorded_at < ?
             GROUP BY source ORDER BY total DESC",
            [$userId, $fromDate, $toDate]
        );
    }

    // ============================================================
    // Marketing Spend (rev_marketing_spend + ad_campaigns) - BATCH6
    // ============================================================

    public function getMarketingSpendTotal(int $userId, string $fromDate, string $toDate): float {
        $manual = $this->db->query(
            "SELECT COALESCE(SUM(amount), 0) AS total FROM rev_marketing_spend
             WHERE user_id = ? AND spend_date >= ? AND spend_date < ?",
            [$userId, $fromDate, $toDate]
        );
        // ad_campaigns.spend ما عندوش تاريخ لكل جزء من الإنفاق (رقم تراكمي للحملة)،
        // فبنضيفه كإجمالي عام مكمّل بدل ما نوزّعه غلط على تواريخ محددة.
        $ads = $this->db->query("SELECT COALESCE(SUM(spend), 0) AS total FROM ad_campaigns WHERE user_id = ?", [$userId]);
        return (float) ($manual[0]['total'] ?? 0) + (float) ($ads[0]['total'] ?? 0);
    }

    public function getMarketingSpendByChannel(int $userId, string $fromDate, string $toDate): array {
        return $this->db->query(
            "SELECT channel, COALESCE(SUM(amount), 0) AS total
             FROM rev_marketing_spend WHERE user_id = ? AND spend_date >= ? AND spend_date < ?
             GROUP BY channel ORDER BY total DESC",
            [$userId, $fromDate, $toDate]
        );
    }

    // ============================================================
    // CRM: Contacts / Leads (crm_contacts, crm_leads) - قراءة فقط
    // ============================================================

    public function getContacts(int $userId): array {
        return $this->db->query("SELECT * FROM crm_contacts WHERE user_id = ? ORDER BY created_at DESC", [$userId]);
    }

    public function getLeads(int $userId): array {
        return $this->db->query(
            "SELECT l.* FROM crm_leads l
             INNER JOIN crm_contacts c ON c.id = l.contact_id
             WHERE c.user_id = ? ORDER BY l.updated_at DESC",
            [$userId]
        );
    }

    // ============================================================
    // CRM: Deals / Pipeline (crm_deals, crm_pipeline_stages) - قراءة فقط
    // ============================================================

    /** كل الصفقات (مفتوحة/مكسوبة/خسرانة) لمستخدم معيّن مع اسم المرحلة. */
    public function getDeals(int $userId, ?string $status = null): array {
        $sql = "SELECT d.*, s.name AS stage_name, s.slug AS stage_slug, s.win_probability AS stage_win_probability,
                       s.is_won AS stage_is_won, s.is_lost AS stage_is_lost, c.name AS contact_name, c.email AS contact_email
                FROM crm_deals d
                LEFT JOIN crm_pipeline_stages s ON s.id = d.stage_id
                LEFT JOIN crm_contacts c ON c.id = d.contact_id
                WHERE d.owner_user_id = ?";
        $params = [$userId];
        if ($status !== null) {
            $sql .= " AND d.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY d.updated_at DESC";
        return $this->db->query($sql, $params);
    }

    /** صفقات مكسوبة (won) ضمن فترة - أقرب "إيراد فعلي مرتبط بعميل" متاح في المشروع حاليًا. */
    public function getWonDealsByContact(int $userId, ?string $fromDate = null, ?string $toDate = null): array {
        $sql = "SELECT d.id, d.contact_id, d.value, d.currency, d.closed_at, d.title,
                       c.name AS contact_name, c.email AS contact_email
                FROM crm_deals d
                INNER JOIN crm_contacts c ON c.id = d.contact_id
                WHERE d.owner_user_id = ? AND d.status = 'won'";
        $params = [$userId];
        if ($fromDate !== null && $toDate !== null) {
            $sql .= " AND d.closed_at >= ? AND d.closed_at < ?";
            $params[] = $fromDate;
            $params[] = $toDate;
        }
        $sql .= " ORDER BY d.closed_at ASC";
        return $this->db->query($sql, $params);
    }

    // ============================================================
    // Subscriptions (recurring revenue) - عندما تتوفر
    // ============================================================

    /** هل يوجد جدول اشتراكات فعلي مع مبالغ يمكن اعتبارها Recurring Revenue؟ */
    public function getActiveSubscriptionsRevenue(int $userId): ?array {
        try {
            // نفس البنية المؤكدة فعليًا في Subscription::activeSubscriptionRow()
            // (subscriptions.plan_id + current_period_end - ده الشكل الحقيقي
            // على السيرفر المباشر، مختلف عن schema.sql/migrations القديمة في
            // الريبو التي توثّق الكود نفسه أنها غير مطابقة للواقع).
            $rows = $this->db->query(
                "SELECT s.id, s.status, p.price, p.billing_cycle
                 FROM subscriptions s
                 LEFT JOIN subscription_plans p ON p.id = s.plan_id
                 WHERE s.user_id = ? AND s.status = 'active' AND s.current_period_end > NOW()",
                [$userId]
            );
            return $rows;
        } catch (Exception $e) {
            // الجدول ممكن يكون غير موجود أو بأعمدة مختلفة في بعض النشرات - لا نكسر الموديول لأجله.
            return null;
        }
    }

    /** Section 2 (Forecast Accuracy - إضافة): توقعات سابقة انتهت فترتها فعليًا، عشان نقارنها بالإيراد الحقيقي اللي حصل. */
    public function getPastForecasts(int $userId, int $limit = 10): array {
        return $this->db->query(
            "SELECT id, period_type, period_start, period_end, expected_revenue, low_estimate, high_estimate, confidence, created_at
             FROM revai_forecasts
             WHERE user_id = ? AND period_end < CURDATE() AND insufficient_data = 0 AND expected_revenue IS NOT NULL
             ORDER BY period_end DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }
}
