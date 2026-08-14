<?php
/**
 * Tourfecto - Google Search Console API Integration
 * تكامل مع Search Console API (بيانات ظهور الموقع في نتائج بحث Google:
 * clicks / impressions / CTR / متوسط الترتيب لكل query أو صفحة)
 * @version 1.0.0
 *
 * نفس فلسفة app/Services/Reputation/GoogleBusinessAPI.php: التوكن ومعرف
 * الموقع بييجوا من صف platform_connections الخاص بكل عميل (مش من .env عام)،
 * عشان كل عميل يربط حساب Google بتاعه هو.
 */
class GoogleSearchConsoleAPI {
    private const BASE_URL = 'https://www.googleapis.com/webmasters/v3';

    private string $accessToken;
    private int $timeout = 30;

    /**
     * @param string $accessToken توكن OAuth حقيقي (scope: webmasters.readonly) خاص بحساب هذا العميل.
     */
    public function __construct(string $accessToken = '') {
        $this->accessToken = $accessToken;
    }

    /**
     * قائمة مواقع Search Console اللي التوكن ده عنده صلاحية عليها
     * (بيرجع بس اللي مؤكدة الملكية verified، عشان العميل يختار الموقع
     * الصح لو عنده أكتر من واحد).
     */
    public function listSites(): array {
        $response = $this->makeRequest('GET', '/sites');
        if (!$response['success']) {
            return $response;
        }

        $sites = array_values(array_filter(array_map(function ($site) {
            $permission = $site['permissionLevel'] ?? '';
            if ($permission === 'siteUnverifiedUser') {
                return null; // ملكش صلاحية فعلية عليه، منعرضهوش للاختيار
            }
            return [
                'site_url' => $site['siteUrl'] ?? '',
                'permission_level' => $permission,
            ];
        }, $response['data']['siteEntry'] ?? [])));

        return ['success' => true, 'sites' => $sites];
    }

    /**
     * بيانات الأداء (clicks/impressions/ctr/position) لموقع معيّن خلال فترة زمنية.
     * @param string $siteUrl لازم يتبعت بالظبط زي ما راجع من listSites()
     *   (ممكن يكون "sc-domain:example.com" أو "https://example.com/")
     * @param string $startDate YYYY-MM-DD
     * @param string $endDate YYYY-MM-DD
     * @param string[] $dimensions زي ['query'] أو ['page'] أو ['date']
     * @param int $rowLimit أقصى عدد صفوف (Google بيسمح لحد 25000)
     */
    public function getSearchAnalytics(string $siteUrl, string $startDate, string $endDate, array $dimensions = ['query'], int $rowLimit = 25): array {
        $endpoint = '/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query';

        $response = $this->makeRequest('POST', $endpoint, [], [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => $dimensions,
            'rowLimit' => $rowLimit,
        ]);

        if (!$response['success']) {
            return $response;
        }

        $rows = array_map(function ($row) use ($dimensions) {
            $keys = $row['keys'] ?? [];
            $mapped = [];
            foreach ($dimensions as $i => $dim) {
                $mapped[$dim] = $keys[$i] ?? null;
            }
            return array_merge($mapped, [
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round((float) ($row['ctr'] ?? 0) * 100, 2), // نسبة مئوية أوضح للعرض
                'position' => round((float) ($row['position'] ?? 0), 1),
            ]);
        }, $response['data']['rows'] ?? []);

        return ['success' => true, 'rows' => $rows];
    }

    /**
     * ملخص سريع (إجمالي clicks/impressions/متوسط ctr وposition) لآخر N يوم،
     * مفيد لكارت "نظرة عامة" من غير ما تحمّل تفاصيل كل query.
     */
    public function getSummary(string $siteUrl, int $days = 28): array {
        $endDate = date('Y-m-d', strtotime('-2 days')); // بيانات Google بتتأخر يوم-يومين عادة
        $startDate = date('Y-m-d', strtotime("-{$days} days", strtotime($endDate)));

        $result = $this->getSearchAnalytics($siteUrl, $startDate, $endDate, ['date'], 1000);
        if (!$result['success']) {
            return $result;
        }

        $totalClicks = 0;
        $totalImpressions = 0;
        $weightedPosition = 0.0;

        foreach ($result['rows'] as $row) {
            $totalClicks += $row['clicks'];
            $totalImpressions += $row['impressions'];
            $weightedPosition += $row['position'] * $row['impressions'];
        }

        return [
            'success' => true,
            'summary' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'clicks' => $totalClicks,
                'impressions' => $totalImpressions,
                'ctr' => $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0,
                'avg_position' => $totalImpressions > 0 ? round($weightedPosition / $totalImpressions, 1) : 0,
            ],
        ];
    }

    private function makeRequest(string $method, string $endpoint, array $query = [], array $data = []): array {
        $url = self::BASE_URL . $endpoint;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);

        $headers = ['Accept: application/json'];
        if ($this->accessToken) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Tourfecto/1.0',
        ];

        if (!empty($data)) {
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'cURL Error: ' . $curlError];
        }

        $decoded = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = $decoded['error']['message'] ?? 'Unknown error';
            return [
                'success' => false,
                'error' => "Search Console API Error ({$httpCode}): {$errorMessage}",
                'http_code' => $httpCode,
            ];
        }

        return ['success' => true, 'data' => $decoded, 'http_code' => $httpCode];
    }
}
