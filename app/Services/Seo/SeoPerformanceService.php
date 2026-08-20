<?php

/**
 * Tourfecto - SEO Performance Service (قياس CTR + تقارير قبل/بعد)
 * @version 1.0.0
 *
 * بيعالج الفجوتين اللي كانوا موجودين في قياس نتائج SEO A/B:
 * 1) كل نداء /results كان بيجيب بيانات GSC كاملة (بطيء + بيتعرض لـ rate
 *    limit من Google). الخدمة دي بتخزّن مقاييس الصفحات في جدول كاش
 *    (seo_gsc_page_metrics) وبتجددها بس لما تكون قديمة.
 * 2) مفيش "قبل/بعد" واضح للعميل. بنسجّل لقطة (seo_reports) بعد كل تدقيق/
 *    تطبيق، عشان نعرض تحسن الدرجة والإصلاحات ومقاييس GSC عبر الوقت.
 */
class SeoPerformanceService
{
    /** @var Database */
    private $db;

    /** عدد ساعات صلاحية كاش GSC قبل ما نعيد الجلب من Google */
    private const METRICS_TTL_HOURS = 6;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * سحب مقاييس GSC لكل صفحة وتخزينها في الكاش.
     * @param string $siteUrl بصيغة GSC (sc-domain:example.com أو https://example.com/)
     * @param string $accessToken توكن OAuth صالح (webmasters.readonly)
     * @return array ['success'=>bool, 'rows'=>int, 'error'=>?string]
     */
    public function syncPageMetrics(int $websiteId, string $siteUrl, string $accessToken): array
    {
        try {
            $api = new GoogleSearchConsoleAPI($accessToken);
            $endDate = date('Y-m-d', strtotime('-2 days')); // تأخر بيانات Google
            $startDate = date('Y-m-d', strtotime('-28 days', strtotime($endDate)));

            $result = $api->getSearchAnalytics($siteUrl, $startDate, $endDate, ['page'], 25000);
            if (!$result['success']) {
                return ['success' => false, 'error' => $result['error'] ?? 'GSC fetch failed'];
            }

            $rows = $result['rows'] ?? [];
            foreach ($rows as $row) {
                $path = SeoAbTestService::normalizePagePath((string) ($row['page'] ?? '/'));
                $this->db->exec(
                    "INSERT INTO seo_gsc_page_metrics
                        (website_id, page_path, clicks, impressions, ctr, position, date_start, date_end, fetched_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                        clicks = VALUES(clicks), impressions = VALUES(impressions),
                        ctr = VALUES(ctr), position = VALUES(position),
                        date_start = VALUES(date_start), date_end = VALUES(date_end),
                        fetched_at = VALUES(fetched_at)",
                    [
                        $websiteId,
                        $path,
                        (int) ($row['clicks'] ?? 0),
                        (int) ($row['impressions'] ?? 0),
                        (float) ($row['ctr'] ?? 0),
                        (float) ($row['position'] ?? 0),
                        $startDate,
                        $endDate,
                    ]
                );
            }

            return ['success' => true, 'rows' => count($rows), 'date_range' => ['start' => $startDate, 'end' => $endDate]];
        } catch (Exception $e) {
            Logger::error('SeoPerformanceService sync error', ['website_id' => $websiteId, 'message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * قراءة مقاييس GSC المخزّنة لموقع، كخريطة page_path => metrics.
     * @return array<string,array>
     */
    public function getCachedPageMetrics(int $websiteId): array
    {
        $rows = $this->db->query(
            "SELECT page_path, clicks, impressions, ctr, position FROM seo_gsc_page_metrics WHERE website_id = ?",
            [$websiteId]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['page_path']] = [
                'clicks' => (int) $r['clicks'],
                'impressions' => (int) $r['impressions'],
                'ctr' => (float) $r['ctr'],
                'position' => (float) $r['position'],
            ];
        }
        return $map;
    }

    /** عمر بيانات الكاش بالساعات (null لو مفيش بيانات خالص) */
    public function metricsAgeHours(int $websiteId): ?float
    {
        $rows = $this->db->query(
            "SELECT MAX(fetched_at) AS latest FROM seo_gsc_page_metrics WHERE website_id = ?",
            [$websiteId]
        );
        if (empty($rows) || empty($rows[0]['latest'])) {
            return null;
        }
        return (time() - strtotime((string) $rows[0]['latest'])) / 3600.0;
    }

    /** إجمالي مقاييس GSC المخزّنة لموقع (ملخص سريع للتقارير) */
    public function cachedSummary(int $websiteId): array
    {
        $rows = $this->db->query(
            "SELECT COALESCE(SUM(clicks),0) AS clicks, COALESCE(SUM(impressions),0) AS impressions,
                    COALESCE(AVG(ctr),0) AS ctr, COALESCE(AVG(position),0) AS position
             FROM seo_gsc_page_metrics WHERE website_id = ?",
            [$websiteId]
        );
        $s = $rows[0] ?? ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0];
        return [
            'clicks' => (int) $s['clicks'],
            'impressions' => (int) $s['impressions'],
            'ctr' => (float) $s['ctr'],
            'avg_position' => (float) $s['position'],
        ];
    }

    /**
     * تسجيل لقطة قبل/بعد (بعد تدقيق أو تطبيق إصلاحات).
     * @return int|null id اللقطة
     */
    public function snapshot(
        int $websiteId,
        ?int $userId,
        ?int $auditId,
        ?float $overallScore,
        int $findingsTotal,
        int $fixesApplied,
        array $gscSummary = [],
        string $source = 'manual'
    ): ?int {
        try {
            $id = $this->db->query(
                "INSERT INTO seo_reports
                    (website_id, user_id, audit_id, overall_score, findings_total, fixes_applied,
                     clicks, impressions, ctr, avg_position, source, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $websiteId,
                    $userId,
                    $auditId,
                    $overallScore,
                    $findingsTotal,
                    $fixesApplied,
                    $gscSummary['clicks'] ?? null,
                    $gscSummary['impressions'] ?? null,
                    $gscSummary['ctr'] ?? null,
                    $gscSummary['avg_position'] ?? null,
                    $source,
                ]
            );
            return (int) $id;
        } catch (Exception $e) {
            Logger::error('SeoPerformanceService snapshot error', ['website_id' => $websiteId, 'message' => $e->getMessage()]);
            return null;
        }
    }

    /** سجل اللقطات (قبل/بعد) لموقع، الأحدث أولًا */
    public function history(int $websiteId, int $limit = 30): array
    {
        return $this->db->query(
            "SELECT * FROM seo_reports WHERE website_id = ? ORDER BY id DESC LIMIT ?",
            [$websiteId, $limit]
        );
    }
}
