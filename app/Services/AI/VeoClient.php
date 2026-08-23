<?php

/**
 * Tourfecto - Veo Video Generation Client
 * توليد فيديوهات قصيرة حقيقية (Veo 3.1 Fast) بنفس GEMINI_API_KEY
 * المستخدم فعلاً لكل خدمات الذكاء الاصطناعي في المنصة - مفيش حساب أو
 * مفتاح API جديد مطلوب. التوليد عملية غير متزامنة (long-running
 * operation) بتستغرق من دقيقة لحد ~6 دقايق، فالتصميم هنا مبني على
 * "بدء + فحص حالة" منفصلين (بدل استنى/sleep داخل نفس الطلب) عشان يتوافق
 * مع طابور المهام الحالي (GenerateVideoJob بيعيد جدولة نفسه للفحص - شوف
 * app/Jobs/GenerateVideoJob.php)، ومحدود بمهلة تنفيذ PHP القصيرة على
 * استضافة مشتركة عادةً.
 * @version 1.0.0
 */
class VeoClient
{
    /** @var string */
    private $apiKey;

    /** @var string */
    private $model;

    /** @var string */
    private $baseUrl;

    public function __construct()
    {
        $this->apiKey = class_exists('SystemSettingsService')
            ? (new SystemSettingsService())->get('gemini_api_key', GEMINI_API_KEY)
            : GEMINI_API_KEY;
        $this->model = VEO_MODEL;
        $this->baseUrl = GEMINI_API_URL; // https://generativelanguage.googleapis.com/v1beta
    }

    /**
     * بدء عملية توليد فيديو جديدة (نصّ فقط - text-to-video).
     * @param string $prompt وصف الفيديو (بعد إضافة الأسلوب/الأبعاد المطلوبة)
     * @param string $aspectRatio '16:9' | '9:16'
     * @param int    $durationSeconds 4 | 6 | 8
     * @return array ['success'=>bool, 'operation_name'=>?string, 'error'=>?string]
     */
    public function startGeneration(string $prompt, string $aspectRatio = '16:9', int $durationSeconds = 8): array
    {
        $url = $this->baseUrl . '/models/' . $this->model . ':predictLongRunning';

        $payload = [
            'instances' => [
                ['prompt' => $prompt],
            ],
            'parameters' => [
                'aspectRatio' => in_array($aspectRatio, ['16:9', '9:16'], true) ? $aspectRatio : '16:9',
                'durationSeconds' => (string) (in_array($durationSeconds, [4, 6, 8], true) ? $durationSeconds : 8),
                'personGeneration' => 'allow_all',
            ],
        ];

        $result = $this->request('POST', $url, $payload);

        if (!$result['success']) {
            return $result;
        }

        $operationName = $result['data']['name'] ?? null;
        if (!$operationName) {
            return ['success' => false, 'error' => 'رد غير متوقع من Veo - مفيش اسم عملية (operation name)'];
        }

        return ['success' => true, 'operation_name' => $operationName];
    }

    /**
     * فحص حالة عملية توليد الفيديو الحالية.
     * @param string $operationName الاسم اللي رجع من startGeneration()
     * @return array ['success'=>bool, 'done'=>bool, 'video_uri'=>?string, 'error'=>?string]
     */
    public function checkOperation(string $operationName): array
    {
        // operationName بييجي بالشكل "models/xxx/operations/yyy" - كامل المسار
        $url = $this->baseUrl . '/' . ltrim($operationName, '/');

        $result = $this->request('GET', $url, null);

        if (!$result['success']) {
            return ['success' => false, 'done' => false, 'error' => $result['error']];
        }

        $data = $result['data'];
        $done = (bool) ($data['done'] ?? false);

        if (!$done) {
            return ['success' => true, 'done' => false];
        }

        // فيديو فشل بسبب سياسات السلامة أو خطأ من الموديل
        if (!empty($data['error'])) {
            return ['success' => false, 'done' => true, 'error' => $data['error']['message'] ?? 'فشل توليد الفيديو من Veo'];
        }

        $videoUri = $data['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri'] ?? null;
        $filteredCount = $data['response']['generateVideoResponse']['raiMediaFilteredCount'] ?? 0;

        if (!$videoUri) {
            $reason = $data['response']['generateVideoResponse']['raiMediaFilteredReasons'][0] ?? null;
            $msg = $filteredCount > 0
                ? ('تم حجب الفيديو بواسطة فلاتر السلامة' . ($reason ? (": {$reason}") : ''))
                : 'اكتملت العملية لكن بدون رابط فيديو صالح';
            return ['success' => false, 'done' => true, 'error' => $msg];
        }

        return ['success' => true, 'done' => true, 'video_uri' => $videoUri];
    }

    /**
     * تحميل بيانات الفيديو الفعلية (bytes) من الرابط المؤقت اللي رجع من
     * checkOperation() بعد الاكتمال.
     * @return array ['success'=>bool, 'data'=>?string binary, 'error'=>?string]
     */
    public function downloadVideo(string $videoUri): array
    {
        try {
            $ch = curl_init($videoUri);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $this->apiKey],
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'Tourfecto/1.0',
            ]);

            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                return ['success' => false, 'error' => 'cURL Error: ' . $curlError];
            }
            if ($httpCode !== 200 || $body === false || $body === '') {
                return ['success' => false, 'error' => "فشل تحميل ملف الفيديو (HTTP {$httpCode})"];
            }

            return ['success' => true, 'data' => $body];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array ['success'=>bool, 'data'=>?array, 'error'=>?string] */
    private function request(string $method, string $url, ?array $payload): array
    {
        try {
            $ch = curl_init($url);
            $headers = ['x-goog-api-key: ' . $this->apiKey, 'Content-Type: application/json'];

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'Tourfecto/1.0',
            ];

            if ($method === 'POST') {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($payload);
            }

            curl_setopt_array($ch, $options);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new Exception('cURL Error: ' . $curlError);
            }

            $data = json_decode($response, true);

            if ($httpCode < 200 || $httpCode >= 300) {
                $errorMessage = $data['error']['message'] ?? "Veo API Error (HTTP {$httpCode})";
                throw new Exception($errorMessage);
            }

            return ['success' => true, 'data' => $data];
        } catch (Throwable $e) {
            Logger::error('Veo API Error', ['message' => $e->getMessage(), 'url' => $url]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
