<?php

/**
 * Tourfecto - Gemini API Client
 * عميل متقدم للتعامل مع Gemini API
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class GeminiClient
{
    /**
     * @var string $apiKey - مفتاح API
     */
    private $apiKey;

    /**
     * @var string $model - نموذج Gemini
     */
    private $model;

    /**
     * @var string $apiUrl - رابط API
     */
    private $apiUrl;

    /**
     * @var array $config - إعدادات الطلب
     */
    private $config;

    /**
     * @var int $maxRetries - عدد محاولات إعادة الطلب
     */
    private $maxRetries = 3;

    /**
     * @var int $timeout - مهلة الطلب
     */
    private $timeout = 60;

    /**
     * Constructor
     */
    public function __construct()
    {
        // تصحيح: بدل الاعتماد على GEMINI_API_KEY من .env بس (يحتاج
        // SSH/File Manager للتعديل)، بقى يقرا من إعدادات النظام القابلة
        // للتعديل من لوحة الأدمن الأول، ويرجع لـ .env كاحتياط آمن.
        $this->apiKey = class_exists('SystemSettingsService')
            ? (new SystemSettingsService())->get('gemini_api_key', GEMINI_API_KEY)
            : GEMINI_API_KEY;

        $this->model = GEMINI_MODEL;
        // تصحيح: GEMINI_API_URL هو الأساس بس (https://.../v1beta) من غير
        // مسار الموديل - أي طلب كان بيروح لرابط ناقص وبيرجع 404 دايمًا،
        // مهما كان مفتاح الـ API صحيح. لازم نضيف /models/{model}:generateContent.
        $this->apiUrl = GEMINI_API_URL . '/models/' . $this->model . ':generateContent';

        $this->config = [
            'temperature' => GEMINI_TEMPERATURE,
            'maxOutputTokens' => GEMINI_MAX_TOKENS,
            'topP' => GEMINI_TOP_P,
            'topK' => GEMINI_TOP_K
        ];

        $this->maxRetries = GEMINI_MAX_RETRIES;
        $this->timeout = GEMINI_TIMEOUT;
    }

    /**
     * توليد محتوى باستخدام Gemini API
     * @param string $prompt - نص الطلب
     * @param array $options - خيارات إضافية
     * @return array
     */
    public function generateContent(string $prompt, array $options = []): array
    {
        $startTime = microtime(true);

        try {
            // دمج الخيارات مع التكوين الأساسي
            $config = array_merge($this->config, $options);

            // بناء جسم الطلب
            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => $config,
                'safetySettings' => GEMINI_SAFETY_SETTINGS
            ];

            // محاولة إرسال الطلب مع إعادة المحاولة
            $response = $this->sendRequest($payload);

            $duration = (microtime(true) - $startTime) * 1000;

            // تسجيل الاستخدام
            $this->logUsage($response, $duration);

            return $response;

        } catch (Exception $e) {
            Logger::error('Gemini API Error', [
                'message' => $e->getMessage(),
                'prompt' => substr($prompt, 0, 200)
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * توليد صورة حقيقية من وصف نصي، بنفس مفتاح Gemini API المستخدم
     * للنصوص - مفيش حاجة تضاف في .env. بيستخدم موديل gemini-2.5-flash-image
     * ("Nano Banana")، فيه حصة مجانية سخية (لحد 500 طلب/يوم وقت كتابة
     * الكود ده) وبيدعم 1024x1024 كافي جدًا لصور سوشيال ميديا/GBP.
     * @param string $prompt وصف الصورة المطلوبة
     * @param string $aspectRatio '1:1' | '16:9' | '9:16' | '4:3' | '3:4'
     * @return array ['success'=>bool, 'image_base64'=>?string, 'mime_type'=>?string, 'error'=>?string]
     */
    public function generateImage(string $prompt, string $aspectRatio = '1:1'): array
    {
        $startTime = microtime(true);
        $imageModel = 'gemini-2.5-flash-image';
        $url = GEMINI_API_URL . '/models/' . $imageModel . ':generateContent?key=' . $this->apiKey;

        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'responseModalities' => ['Image'],
                'imageConfig' => ['aspectRatio' => $aspectRatio],
            ],
            'safetySettings' => GEMINI_SAFETY_SETTINGS,
        ];

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'Tourfecto/1.0',
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new Exception('cURL Error: ' . $curlError);
            }

            $data = json_decode($response, true);

            if ($httpCode !== 200) {
                $errorMessage = $data['error']['message'] ?? 'Unknown error';
                throw new Exception("Gemini Image API Error (HTTP {$httpCode}): {$errorMessage}");
            }

            $parts = $data['candidates'][0]['content']['parts'] ?? [];
            $imagePart = null;
            foreach ($parts as $part) {
                if (isset($part['inlineData']['data'])) {
                    $imagePart = $part['inlineData'];
                    break;
                }
            }

            if (!$imagePart) {
                // ممكن يرجّع نص بس لو الطلب اتحجب (Safety) أو مش مفهوم -
                // نديله فرصة يظهر في رسالة الخطأ بدل "صيغة غير متوقعة" غامضة
                $fallbackText = $parts[0]['text'] ?? null;
                throw new Exception($fallbackText ? "لم يتم توليد صورة: {$fallbackText}" : 'رد غير متوقع من Gemini - مفيش صورة في الرد');
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $this->logUsage(['success' => true, 'http_code' => $httpCode, 'tokens_used' => $data['usageMetadata']['totalTokenCount'] ?? 0, 'cost' => 0], $duration);

            return [
                'success' => true,
                'image_base64' => $imagePart['data'],
                'mime_type' => $imagePart['mimeType'] ?? 'image/png',
            ];
        } catch (Exception $e) {
            Logger::error('Gemini Image Generation Error', ['message' => $e->getMessage(), 'prompt' => substr($prompt, 0, 200)]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * إرسال طلب إلى Gemini API مع إعادة المحاولة
     * @param array $payload
     * @return array
     */
    private function sendRequest(array $payload): array
    {
        $attempt = 0;
        $lastError = null;

        // تصحيح (2026-08-20): لو مفتاح Gemini مش مُهيأ، كان الطلب بيروح
        // بـ "?key=" فاضي وGoogle بترجّع HTTP 403 "API key not valid" —
        // رسالة مربكة للعميل. بنوقف بكرامة برسالة واضحة توجّهه لمكان
        // إضافة المفتاح (لوحة الأدمن > الإعدادات أو .env).
        if (empty($this->apiKey)) {
            throw new Exception('مفتاح Gemini API غير مُهيأ — أضفه من الإعدادات (System Settings) أو متغير GEMINI_API_KEY في .env');
        }

        while ($attempt < $this->maxRetries) {
            try {
                $url = $this->apiUrl . '?key=' . $this->apiKey;

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Accept: application/json'
                    ],
                    CURLOPT_TIMEOUT => $this->timeout,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_USERAGENT => 'Tourfecto/1.0'
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($curlError) {
                    throw new Exception('cURL Error: ' . $curlError);
                }

                if ($httpCode !== 200) {
                    $errorData = json_decode($response, true);
                    $errorMessage = $errorData['error']['message'] ?? 'Unknown error';
                    throw new Exception("API Error (HTTP {$httpCode}): {$errorMessage}");
                }

                $data = json_decode($response, true);

                if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                    throw new Exception('Invalid API response format');
                }

                $text = $data['candidates'][0]['content']['parts'][0]['text'];

                // استخراج الإحصائيات
                $tokensUsed = $data['usageMetadata']['totalTokenCount'] ?? 0;
                // التكلفة لكل 1K رمز (مش لكل مليون) - نفس نمط باقي المزودين
                $costPer1k = defined('GEMINI_COST_PER_1K_INPUT_TOKENS') ? GEMINI_COST_PER_1K_INPUT_TOKENS : 0.000125;
                $cost = ($tokensUsed / 1000) * $costPer1k; // تقدير التكلفة

                return [
                    'success' => true,
                    'data' => $text,
                    'tokens_used' => $tokensUsed,
                    'cost' => $cost,
                    'http_code' => $httpCode,
                    'raw_response' => $data
                ];

            } catch (Exception $e) {
                $lastError = $e->getMessage();
                $attempt++;

                if ($attempt < $this->maxRetries) {
                    // تأخير مع زيادة تدريجية
                    $delay = pow(2, $attempt) * GEMINI_RETRY_DELAY;
                    sleep($delay);
                }
            }
        }

        return [
            'success' => false,
            'error' => $lastError ?? 'Max retries exceeded'
        ];
    }

    /**
     * تسجيل استخدام الـ API
     * @param array $response
     * @param float $duration
     */
    private function logUsage(array $response, float $duration): void
    {
        if (!$response['success']) {
            return;
        }

        try {
            $db = Database::getInstance();

            $sql = "INSERT INTO api_usage_logs (
                        api_type, endpoint, status_code, 
                        tokens_used, cost_in_usd, duration_ms
                    ) VALUES (
                        'gemini', :endpoint, :status_code,
                        :tokens_used, :cost, :duration
                    )";

            $db->query($sql, [
                ':endpoint' => $this->model,
                ':status_code' => $response['http_code'] ?? 200,
                ':tokens_used' => $response['tokens_used'] ?? 0,
                ':cost' => $response['cost'] ?? 0,
                ':duration' => $duration
            ]);

        } catch (Exception $e) {
            // تجاهل خطأ التسجيل
        }
    }

    /**
     * تغيير النموذج المستخدم
     * @param string $model
     */
    public function setModel(string $model): void
    {
        if (isset(GEMINI_AVAILABLE_MODELS[$model])) {
            $this->model = $model;
            // نفس تصحيح الـ constructor - كان بيعمل str_replace على نص
            // GEMINI_MODEL جوه GEMINI_API_URL، لكن GEMINI_API_URL أصلًا
            // مكانش فيه اسم موديل خالص، فكان بيرجع نفس الرابط الناقص.
            $this->apiUrl = GEMINI_API_URL . '/models/' . $model . ':generateContent';
        }
    }

    /**
     * تغيير مهلة الطلب
     * @param int $timeout
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * التحقق من صحة المفتاح
     * @return bool
     */
    public function validateApiKey(): bool
    {
        try {
            $testPrompt = "Hello, this is a test.";
            $response = $this->generateContent($testPrompt);
            return $response['success'];

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * الحصول على قائمة النماذج المتاحة
     * @return array
     */
    public function getAvailableModels(): array
    {
        return GEMINI_AVAILABLE_MODELS;
    }

    /**
     * التحقق من صلاحية النموذج
     * @param string $model
     * @return bool
     */
    public function isModelValid(string $model): bool
    {
        return isset(GEMINI_AVAILABLE_MODELS[$model]);
    }
}
