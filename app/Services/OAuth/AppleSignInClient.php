<?php
/**
 * Tourfecto - Apple Sign In Client
 * "تسجيل الدخول بواسطة Apple" مختلف عن باقي المنصات: مفيش client_secret
 * ثابت - لازم نولّده إحنا كـ JWT موقّع بمفتاح Apple الخاص (ES256) في كل
 * طلب. وكمان الاستجابة بترجع كـ POST (form_post) مش GET زي باقي المنصات،
 * والبيانات الشخصية (الاسم) بتوصل مرة واحدة بس أول مرة يوافق فيها
 * المستخدم (في حقل "user" منفصل، JSON) - المرات اللي بعد كده id_token
 * بس فيه الإيميل والـ sub (المعرّف الفريد).
 *
 * المتطلبات (خارج الكود، من Apple Developer):
 *  1) Apple Developer Program account (فيه اشتراك سنوي مدفوع من آبل نفسها).
 *  2) App ID عليه قدرة "Sign in with Apple" مفعّلة.
 *  3) Services ID (ده اللي بيتحط في oauth_apple_client_id - مش الـ App ID
 *     نفسه) - وفيه لازم تضيف Return URL (redirectUri() تحت) بالظبط.
 *  4) مفتاح خاص (.p8) من Certificates, Identifiers & Profiles > Keys -
 *     محتواه الكامل (يبدأ بـ -----BEGIN PRIVATE KEY-----) بيتحط في
 *     oauth_apple_private_key، والـ Key ID بتاعه في oauth_apple_key_id.
 *  5) oauth_apple_team_id = Team ID بتاعك (موجود أعلى يمين أي صفحة في
 *     Apple Developer Portal).
 * @version 1.0.0
 */
class AppleSignInClient {
    private const AUTH_URL = 'https://appleid.apple.com/auth/authorize';
    private const TOKEN_URL = 'https://appleid.apple.com/auth/token';
    private const AUDIENCE = 'https://appleid.apple.com';

    private string $clientId;
    private string $teamId;
    private string $keyId;
    private string $privateKey;
    private string $redirectUri;

    public function __construct() {
        $settings = class_exists('SystemSettingsService') ? new SystemSettingsService() : null;
        $this->clientId = $settings ? $settings->get('oauth_apple_client_id', '') : '';
        $this->teamId = $settings ? $settings->get('oauth_apple_team_id', '') : '';
        $this->keyId = $settings ? $settings->get('oauth_apple_key_id', '') : '';
        $this->privateKey = $settings ? $settings->get('oauth_apple_private_key', '') : '';
        $this->redirectUri = self::redirectUri();
    }

    public static function redirectUri(): string {
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        return "{$base}/auth/apple/callback";
    }

    public function isConfigured(): bool {
        return $this->clientId !== '' && $this->teamId !== '' && $this->keyId !== '' && $this->privateKey !== '';
    }

    public function buildAuthUrl(string $state): string {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'name email',
            'response_mode' => 'form_post', // مطلوب لما بنطلب scope (name/email)
            'state' => $state,
        ];
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /** @return array ['success'=>bool, 'id_token'=>?string, 'error'=>?string] */
    public function exchangeCodeForToken(string $code): array {
        $clientSecret = $this->generateClientSecret();
        if ($clientSecret === null) {
            return ['success' => false, 'error' => 'تعذر توليد Apple client secret - تأكد من صحة المفتاح الخاص (.p8) في الإعدادات'];
        }

        $fields = [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ];

        try {
            $ch = curl_init(self::TOKEN_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($fields),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
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
            if ($httpCode !== 200 || !isset($data['id_token'])) {
                return ['success' => false, 'error' => $data['error_description'] ?? $data['error'] ?? "Apple OAuth error (HTTP {$httpCode})"];
            }

            return ['success' => true, 'id_token' => $data['id_token']];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('AppleSignInClient token exchange failed', ['error' => $e->getMessage()]);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * فك تشفير id_token (JWT) لاستخراج بيانات المستخدم - قراءة الـ payload
     * بس (Apple هي مصدر الاتصال المباشر والقناة HTTPS موثوقة، فمفيش داعي
     * لتعقيد التحقق من التوقيع بمفاتيح Apple العامة هنا).
     * @return array|null ['id'=>string,'email'=>?string]
     */
    public function decodeIdToken(string $idToken): ?array {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode(self::base64UrlDecode($parts[1]), true);
        if (!is_array($payload) || empty($payload['sub'])) {
            return null;
        }
        return [
            'id' => (string) $payload['sub'],
            'email' => $payload['email'] ?? null,
        ];
    }

    /** يولّد client_secret كـ JWT موقّع ES256 صالح لمدة 5 دقايق (كفاية لتبديل كود واحد) */
    private function generateClientSecret(): ?string {
        if (!$this->isConfigured() || !function_exists('openssl_sign')) {
            return null;
        }

        $now = time();
        $header = ['alg' => 'ES256', 'kid' => $this->keyId];
        $payload = [
            'iss' => $this->teamId,
            'iat' => $now,
            'exp' => $now + 300,
            'aud' => self::AUDIENCE,
            'sub' => $this->clientId,
        ];

        $signingInput = self::base64UrlEncode(json_encode($header)) . '.' . self::base64UrlEncode(json_encode($payload));

        $privateKey = openssl_pkey_get_private($this->privateKey);
        if ($privateKey === false) {
            return null;
        }

        $derSignature = '';
        $ok = openssl_sign($signingInput, $derSignature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            return null;
        }

        $rawSignature = self::derToRawEcdsaSignature($derSignature, 32);
        if ($rawSignature === null) {
            return null;
        }

        return $signingInput . '.' . self::base64UrlEncode($rawSignature);
    }

    /**
     * openssl_sign بيرجّع توقيع ECDSA بصيغة DER (ASN.1)، لكن JWT ES256 محتاج
     * صيغة "raw" (r و s متسلسلين، كل واحد بـ padding لطول ثابت). الدالة دي
     * بتفك ترميز DER البسيط ده يدويًا (مفيش أي مكتبة JWT متاحة في المشروع).
     */
    private static function derToRawEcdsaSignature(string $der, int $partLength): ?string {
        $offset = 0;
        if (($der[$offset++] ?? '') !== "\x30") {
            return null;
        }
        $offset += (ord($der[$offset]) & 0x80) ? (ord($der[$offset]) & 0x0F) + 1 : 1; // تخطي طول الـ SEQUENCE (short أو long form)

        if (($der[$offset++] ?? '') !== "\x02") {
            return null;
        }
        $rLen = ord($der[$offset++]);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;

        if (($der[$offset++] ?? '') !== "\x02") {
            return null;
        }
        $sLen = ord($der[$offset++]);
        $s = substr($der, $offset, $sLen);

        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        $r = str_pad($r, $partLength, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, $partLength, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string {
        $data = strtr($data, '-_', '+/');
        $padding = strlen($data) % 4;
        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }
        return (string) base64_decode($data);
    }
}
