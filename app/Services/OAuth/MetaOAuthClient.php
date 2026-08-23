<?php

/**
 * Tourfecto - Meta (Facebook) OAuth Client
 * تدفّق OAuth 2.0 القياسي من Meta لربط حسابات Meta Ads الإعلانية
 * @version 1.0.0
 *
 * المتطلبات قبل ما ده يشتغل (خارج الكود، من Meta for Developers):
 *  1) تطبيق Meta من نوع "Business" على developers.facebook.com، مربوط
 *     بحساب Business Manager بتاعك.
 *  2) إضافة منتج "Marketing API" للتطبيق.
 *  3) صلاحيات (Permissions) مطلوبة: ads_management, ads_read,
 *     business_management - في وضع Development دول شغالين بس على
 *     حسابات الإعلانات اللي انت admin عليها؛ عشان تشتغل مع عملاء تانيين
 *     لازم App Review رسمي من Meta.
 *  4) Valid OAuth Redirect URI في إعدادات التطبيق، لازم يطابق
 *     META_OAUTH_REDIRECT_URI تحت بالظبط.
 *  5) القيم دي في .env: META_APP_ID, META_APP_SECRET, META_OAUTH_REDIRECT_URI
 *
 * ملحوظة نسخة الـ API: Meta بتصدر نسخة جديدة كل ~3 شهور وبتوقف نسخ
 * قديمة بعد ~سنتين. النسخة الحالية وقت كتابة الكود ده v25.0 - لو
 * حصل خطأ "Unsupported API version" في المستقبل، حدّث META_API_VERSION
 * في .env لأحدث نسخة من developers.facebook.com/docs/graph-api/changelog.
 */
class MetaOAuthClient
{
    private string $apiVersion;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct()
    {
        $this->apiVersion = env('META_API_VERSION') ?: 'v25.0';
        $this->clientId = env('META_APP_ID') ?: '';
        $this->clientSecret = env('META_APP_SECRET') ?: '';
        $this->redirectUri = env('META_OAUTH_REDIRECT_URI') ?: '';
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '' && $this->redirectUri !== '';
    }

    /**
     * بناء رابط "موافقة Meta" اللي هنوجّه العميل ليه.
     * @param string $state قيمة عشوائية موقّعة نتحقق منها وقت الرجوع (CSRF protection)
     */
    public function buildAuthUrl(string $state): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'ads_management,ads_read,business_management,pages_show_list',
            'state' => $state,
        ];

        return "https://www.facebook.com/{$this->apiVersion}/dialog/oauth?" . http_build_query($params);
    }

    /**
     * تبديل authorization code بتوكن وصول قصير المدى، وبعدين تلقائيًا
     * بتوكن طويل المدى (~60 يوم) عشان مانحتاجش نطلب من العميل يوافق
     * تاني كل شوية.
     * @return array ['success'=>bool, 'access_token'=>?, 'expires_in'=>?, 'error'=>?]
     */
    public function exchangeCodeForTokens(string $code): array
    {
        $shortLived = $this->httpGet('oauth/access_token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'code' => $code,
        ]);

        if (!$shortLived['success']) {
            return $shortLived;
        }

        // تبديل التوكن قصير المدى بتوكن طويل المدى (long-lived token)
        return $this->httpGet('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'fb_exchange_token' => $shortLived['access_token'],
        ]);
    }

    /**
     * طلب GET عام لـ Graph API، مستخدم هنا لتبادل التوكنات.
     * @return array
     */
    private function httpGet(string $path, array $query): array
    {
        try {
            $url = "https://graph.facebook.com/{$this->apiVersion}/{$path}?" . http_build_query($query);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                return ['success' => false, 'error' => 'cURL Error: ' . $curlError];
            }

            $data = json_decode($response, true);

            if ($httpCode !== 200 || !isset($data['access_token'])) {
                return [
                    'success' => false,
                    'error' => $data['error']['message'] ?? "Meta OAuth error (HTTP {$httpCode})",
                ];
            }

            return [
                'success' => true,
                'access_token' => $data['access_token'],
                'expires_in' => (int) ($data['expires_in'] ?? 5184000), // ~60 يوم افتراضي للتوكن الطويل
            ];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Meta OAuth token request failed', ['error' => $e->getMessage()]);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
