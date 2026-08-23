<?php

/**
 * Tourfecto - Google Analytics 4 (GA4) API Integration
 * @version 1.0.0
 *
 * تكامل مع GA4 Data API (v1beta) لسحب مقاييس الزيارات والتفاعل لكل موقع
 * مربوط بحساب Google Analytics الخاص بالعميل - بنفس فلسفة Search Console:
 * التوكن ومعرف الـ property بييجوا من صف platform_connections الخاص بكل
 * عميل (مش من .env عام).
 *
 * بيستخدم الـ runReport مع metrics تجميعية (sessions/engagedSessions/
 * totalUsers/activeUsers/conversions) عشان يوفّر نظرة سريعة "هل التحسين
 * بيجيب زيارات أكتر؟" جنب بيانات CTR من Search Console.
 */
class GoogleAnalyticsAPI
{
    private const BASE_URL = 'https://analyticsdata.googleapis.com/v1beta';
    private const ADMIN_BASE_URL = 'https://analyticsadmin.googleapis.com/v1beta';

    private string $accessToken;
    private int $timeout = 30;

    /** @param string $accessToken توكن OAuth (scope: analytics.readonly) خاص بحساب هذا العميل */
    public function __construct(string $accessToken = '')
    {
        $this->accessToken = $accessToken;
    }

    /**
     * سحب حسابات GA4 (accounts) وخصائصها (properties) المرتبطة بحساب Google.
     * بيستخدم Analytics Admin API (accountSummaries) عشان نعرّض للعميل
     * قائمة يختار منها الـ property الصحيح في picker.
     * @return array ['success'=>bool, 'accounts'=>[...]|'error'=>string]
     */
    public function listAccounts(): array
    {
        return $this->adminGet('accountSummaries');
    }

    /**
     * ملخص مقاييس الزيارات لآخر N يوم.
     * @param string $propertyId بصيغة GA4: properties/123456789
     * @param int $days عدد الأيام (الافتراضي 28)
     * @return array ['success'=>bool, 'summary'=>[...]|'error'=>string]
     */
    public function getSummary(string $propertyId, int $days = 28): array
    {
        $endDate = date('Y-m-d', strtotime('-1 day'));
        $startDate = date('Y-m-d', strtotime("-{$days} days", strtotime($endDate)));

        $metrics = ['sessions', 'engagedSessions', 'totalUsers', 'newUsers', 'activeUsers', 'conversions'];
        $payload = [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'metrics' => array_map(fn ($m) => ['name' => $m], $metrics),
        ];

        $response = $this->runReport($propertyId, $payload);
        if (!$response['success']) {
            return $response;
        }

        $totals = $response['data']['totals'][0]['metricValues'] ?? [];
        $values = array_map(fn ($v) => (int) ($v['value'] ?? 0), $totals);

        return [
            'success' => true,
            'summary' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'sessions' => $values[0] ?? 0,
                'engaged_sessions' => $values[1] ?? 0,
                'total_users' => $values[2] ?? 0,
                'new_users' => $values[3] ?? 0,
                'active_users' => $values[4] ?? 0,
                'conversions' => $values[5] ?? 0,
                'engagement_rate' => !empty($values[0]) ? round((($values[1] ?? 0) / $values[0]) * 100, 2) : 0,
            ],
        ];
    }

    /**
     * مصادر الزيارات (source/medium) لآخر N يوم.
     * @return array ['success'=>bool, 'rows'=>[...]|'error'=>string]
     */
    public function getTrafficSources(string $propertyId, int $days = 28, int $limit = 10): array
    {
        $endDate = date('Y-m-d', strtotime('-1 day'));
        $startDate = date('Y-m-d', strtotime("-{$days} days", strtotime($endDate)));

        $payload = [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions' => [['name' => 'sessionSource'], ['name' => 'sessionMedium']],
            'metrics' => [['name' => 'sessions']],
            'limit' => (string) $limit,
        ];

        $response = $this->runReport($propertyId, $payload);
        if (!$response['success']) {
            return $response;
        }

        $rows = array_map(function ($row) {
            $dims = $row['dimensionValues'] ?? [];
            $metrics = $row['metricValues'] ?? [];
            return [
                'source' => $dims[0]['value'] ?? '',
                'medium' => $dims[1]['value'] ?? '',
                'sessions' => (int) ($metrics[0]['value'] ?? 0),
            ];
        }, $response['data']['rows'] ?? []);

        return ['success' => true, 'rows' => $rows];
    }

    private function runReport(string $propertyId, array $payload): array
    {
        $url = self::BASE_URL . '/' . rawurlencode($propertyId) . ':runReport';

        $ch = curl_init($url);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($this->accessToken) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Tourfecto/1.0',
        ]);

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
            return ['success' => false, 'error' => "GA4 API Error ({$httpCode}): {$errorMessage}", 'http_code' => $httpCode];
        }

        return ['success' => true, 'data' => $decoded, 'http_code' => $httpCode];
    }

    /**
     * GET من Analytics Admin API (v1beta) - لسحب accountSummaries وغيرها.
     * @param string $path مسار الإندبوينت (مثال: accountSummaries)
     */
    private function adminGet(string $path): array
    {
        $url = self::ADMIN_BASE_URL . '/' . $path;
        $headers = ['Accept: application/json'];
        if ($this->accessToken) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Tourfecto/1.0',
        ]);

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
            return ['success' => false, 'error' => "GA4 Admin API Error ({$httpCode}): {$errorMessage}", 'http_code' => $httpCode];
        }

        $accounts = $decoded['accountSummaries'] ?? [];

        // تطبيع لشكل سهل الاستخدام: قائمة مسطّحة من الخصائص مع أسماء الحسابات
        $properties = [];
        foreach ($accounts as $account) {
            $accountName = (string) ($account['displayName'] ?? '');
            foreach ($account['propertySummaries'] ?? [] as $prop) {
                $properties[] = [
                    'account_name' => $accountName,
                    'property_id' => (string) ($prop['property'] ?? ''),
                    'property_name' => (string) ($prop['displayName'] ?? ''),
                ];
            }
        }

        return ['success' => true, 'properties' => $properties];
    }
}
