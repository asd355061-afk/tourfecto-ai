<?php

/**
 * Tourfecto - GetYourGuide Partner API Client
 * ربط حساب الشريك (Partner) في GetYourGuide - يستخدم مفتاح وصول واحد
 * (X-ACCESS-TOKEN) بيتحصّل عليه العميل من partner.getyourguide.com بعد
 * موافقتهم على حسابه كـ Partner. مفيش OAuth هنا لأن GetYourGuide مبيقدمهوش
 * لشركاء API - التوثيق الرسمي: https://github.com/getyourguide/partner-api-spec
 * @version 1.0.0
 */
class GetYourGuideAPI
{
    private const BASE_URL = 'https://api.getyourguide.com/1';

    private string $accessToken;

    /** @var callable|null transport قابل للحقن في الاختبارات بدل curl الحقيقي */
    private $transport;

    /**
     * @param string      $accessToken مفتاح الوصول (X-ACCESS-TOKEN) من Partner portal
     * @param callable|null $transport  fn(string $method, string $url, array $headers, ?string $body)
     *                                  => array{response:?string, http_code:int, error:?string}
     *                                  - اختياري للاختبارات فقط؛ الإنتاج يبقى curl كالمعتاد.
     */
    public function __construct(string $accessToken, ?callable $transport = null)
    {
        $this->accessToken = $accessToken;
        $this->transport = $transport;
    }

    /**
     * تحقق إن المفتاح صحيح فعلاً قبل ما نحفظه - بنستخدم endpoint خفيف
     * (بحث بحد أقصى نتيجة واحدة) بدل ما نصدّق المفتاح من غير اختبار.
     */
    public function verifyToken(): array
    {
        $result = $this->request('GET', '/tours', ['cnt_language' => 'en', 'currency' => 'USD', 'limit' => 1]);
        if (!$result['success'] && $result['http_code'] === 401) {
            return ['success' => false, 'error' => 'مفتاح GetYourGuide غير صحيح أو منتهي'];
        }
        if (!$result['success'] && $result['http_code'] === 0) {
            return ['success' => false, 'error' => $result['error'] ?? 'تعذر الاتصال بـ GetYourGuide'];
        }
        // أي رد غير 401 (حتى لو فيه تفاصيل خطأ تانية زي 404 على مسار معيّن)
        // معناه إن التوكن اتقبل واتحقق منه فعليًا من سيرفر GetYourGuide.
        return ['success' => true];
    }

    /** جلب منتجات (جولات/أنشطة) الشريك - لعرضها/مزامنتها مع الموقع */
    public function getTours(int $page = 1, int $limit = 50): array
    {
        return $this->request('GET', '/tours', [
            'cnt_language' => 'en',
            'currency' => 'USD',
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /** جلب حجز واحد بالتفصيل */
    public function getBooking(string $bookingHash): array
    {
        return $this->request('GET', '/bookings/' . rawurlencode($bookingHash));
    }

    private function request(string $method, string $path, array $query = [], array $body = []): array
    {
        $url = self::BASE_URL . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = ['Accept: application/json', 'X-ACCESS-TOKEN: ' . $this->accessToken];
        $requestBody = !empty($body) ? json_encode($body) : null;

        if ($this->transport !== null) {
            $result = call_user_func($this->transport, strtoupper($method), $url, $headers, $requestBody);
            $response = $result['response'] ?? null;
            $httpCode = (int) ($result['http_code'] ?? 0);
            $curlError = $result['error'] ?? null;
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => strtoupper($method),
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT => 15,
            ]);
            if ($requestBody !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
            }

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
        }

        if ($curlError) {
            $this->log('error', "GetYourGuide cURL error: {$curlError}");
            return ['success' => false, 'data' => null, 'error' => $curlError, 'http_code' => 0];
        }

        $decoded = json_decode((string) $response, true);
        if ($httpCode >= 400) {
            $this->log('warning', "GetYourGuide API error HTTP {$httpCode}", ['response' => (string) $response]);
            return ['success' => false, 'data' => $decoded, 'error' => "HTTP {$httpCode}", 'http_code' => $httpCode];
        }

        return ['success' => true, 'data' => $decoded, 'error' => null, 'http_code' => $httpCode];
    }

    private function log(string $level, string $message, array $context = []): void
    {
        // الترحيل: الاعتماد الأساسي على Logger الموجود في المشروع؛ مع fallback
        // للـ app_log القديمة (غير معرّفة في الكود الحالي فتعمل كـ no-op).
        if (class_exists('Logger')) {
            if ($level === 'error') {
                Logger::error($message, $context);
            } else {
                Logger::warning($message, $context);
            }
            return;
        }
        if (function_exists('app_log')) {
            app_log($level, $message, $context);
        }
    }
}
