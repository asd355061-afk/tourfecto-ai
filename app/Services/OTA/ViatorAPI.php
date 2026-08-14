<?php
/**
 * Tourfecto - Viator Partner API Client
 * ربط حساب الشريك (Affiliate/Merchant) في Viator - مفتاح وصول واحد
 * (exp-api-key) بيتحصّل عليه العميل من حسابه في Viator Partner Portal.
 * التوثيق الرسمي: https://docs.viator.com/partner-api/
 * @version 1.0.0
 */
class ViatorAPI {

    private const BASE_URL = 'https://api.viator.com/partner';

    private string $apiKey;
    private string $language;

    public function __construct(string $apiKey, string $language = 'en-US') {
        $this->apiKey = $apiKey;
        $this->language = $language;
    }

    /**
     * تحقق إن المفتاح صحيح فعلاً قبل ما نحفظه - بنستخدم endpoint خفيف
     * (قائمة الوجهات) بدل ما نصدّق المفتاح من غير اختبار.
     */
    public function verifyToken(): array {
        $result = $this->request('GET', '/destinations');
        if (!$result['success'] && in_array($result['http_code'], [401, 403], true)) {
            return ['success' => false, 'error' => 'مفتاح Viator غير صحيح أو منتهي'];
        }
        if (!$result['success'] && $result['http_code'] === 0) {
            return ['success' => false, 'error' => $result['error'] ?? 'تعذر الاتصال بـ Viator'];
        }
        return ['success' => true];
    }

    /** بحث عن منتجات (جولات/أنشطة) الشريك */
    public function searchProducts(array $filters = []): array {
        return $this->request('POST', '/products/search', [], $filters);
    }

    /** جلب حجز واحد بالتفصيل */
    public function getBooking(string $bookingRef): array {
        return $this->request('GET', '/bookings/' . rawurlencode($bookingRef) . '/status');
    }

    private function request(string $method, string $path, array $query = [], array $body = []): array {
        $url = self::BASE_URL . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json;version=2.0',
                'Content-Type: application/json',
                'Accept-Language: ' . $this->language,
                'exp-api-key: ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->log('error', "Viator cURL error: {$curlError}");
            return ['success' => false, 'data' => null, 'error' => $curlError, 'http_code' => 0];
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            $this->log('warning', "Viator API error HTTP {$httpCode}", ['response' => $response]);
            return ['success' => false, 'data' => $decoded, 'error' => "HTTP {$httpCode}", 'http_code' => $httpCode];
        }

        return ['success' => true, 'data' => $decoded, 'error' => null, 'http_code' => $httpCode];
    }

    private function log(string $level, string $message, array $context = []): void {
        if (function_exists('app_log')) {
            app_log($level, $message, $context);
        }
    }
}
