<?php
/**
 * Tourfecto - Social Login Client (تسجيل الدخول بواسطة Google / Facebook / Microsoft)
 * تدفّق OAuth 2.0 القياسي (Authorization Code) لأغراض تسجيل الدخول فقط
 * (openid/email/profile) - مش نفس الـ OAuth بتاع ربط تكاملات Google
 * Business Profile أو Meta Ads (اللي ليهم عملاء منفصلين في GoogleOAuthClient
 * و MetaOAuthClient)، لكن بيعيد استخدام نفس بيانات اعتماد Google/Meta
 * (google_client_id/secret و meta_app_id/secret) لأن غالبًا نفس تطبيق
 * Google Cloud / Meta for Developers بيدعم أكتر من منتج (Business Profile
 * API + Google Sign-In، أو Marketing API + Facebook Login) - المطلوب بس
 * إضافة رابط الـ Redirect URI الخاص بتسجيل الدخول (تحت) في لوحة كل منصة.
 *
 * المتطلبات (خارج الكود):
 *  - Google: من Google Cloud Console، ضيف الرابط اللي بيرجّعه
 *    redirectUri('google') هنا كـ "Authorized redirect URI" على نفس
 *    الـ OAuth Client Id المستخدم بالفعل (google_client_id/secret من
 *    إعدادات لوحة الأدمن).
 *  - Facebook: من Meta for Developers، فعّل منتج "Facebook Login" على نفس
 *    التطبيق، وضيف نفس الرابط كـ "Valid OAuth Redirect URI"
 *    (meta_app_id/secret من إعدادات لوحة الأدمن).
 *  - Microsoft: من Azure Portal (App registrations)، سجّل تطبيق ويب،
 *    وضيف الرابط كـ Redirect URI، واحفظ oauth_microsoft_client_id /
 *    oauth_microsoft_client_secret من لوحة الأدمن (oauth_microsoft_tenant
 *    اختياري - افتراضيًا "common" يعني أي حساب Microsoft شخصي أو عمل).
 * @version 1.0.0
 */
class SocialLoginClient {
    private const PROVIDERS = [
        'google' => [
            'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'userinfo_url' => 'https://www.googleapis.com/oauth2/v3/userinfo',
            'scope' => 'openid email profile',
            'client_id_key' => 'google_client_id',
            'client_secret_key' => 'google_client_secret',
        ],
        'facebook' => [
            'auth_url' => 'https://www.facebook.com/v21.0/dialog/oauth',
            'token_url' => 'https://graph.facebook.com/v21.0/oauth/access_token',
            'userinfo_url' => 'https://graph.facebook.com/me',
            'scope' => 'email public_profile',
            'client_id_key' => 'meta_app_id',
            'client_secret_key' => 'meta_app_secret',
        ],
        'microsoft' => [
            // {tenant} بيتعوّض ديناميكيًا في buildAuthUrl/postToken
            'auth_url' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
            'userinfo_url' => 'https://graph.microsoft.com/oidc/userinfo',
            'scope' => 'openid email profile',
            'client_id_key' => 'oauth_microsoft_client_id',
            'client_secret_key' => 'oauth_microsoft_client_secret',
        ],
    ];

    private string $provider;
    private array $config;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $tenant;

    public function __construct(string $provider) {
        if (!isset(self::PROVIDERS[$provider])) {
            throw new InvalidArgumentException("منصة تسجيل دخول غير مدعومة: {$provider}");
        }
        $this->provider = $provider;
        $this->config = self::PROVIDERS[$provider];

        $settings = class_exists('SystemSettingsService') ? new SystemSettingsService() : null;
        $this->clientId = $settings ? $settings->get($this->config['client_id_key'], '') : '';
        $this->clientSecret = $settings ? $settings->get($this->config['client_secret_key'], '') : '';
        $this->tenant = $settings ? ($settings->get('oauth_microsoft_tenant', '') ?: 'common') : 'common';
        $this->redirectUri = self::redirectUri($provider);
    }

    public static function redirectUri(string $provider): string {
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        return "{$base}/auth/{$provider}/callback";
    }

    public function isConfigured(): bool {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    private function url(string $key): string {
        return str_replace('{tenant}', $this->tenant, $this->config[$key]);
    }

    public function buildAuthUrl(string $state): string {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => $this->config['scope'],
            'state' => $state,
        ];
        if ($this->provider === 'google') {
            $params['prompt'] = 'select_account';
        }
        return $this->url('auth_url') . '?' . http_build_query($params);
    }

    /** @return array ['success'=>bool, 'access_token'=>?, 'error'=>?] */
    public function exchangeCodeForToken(string $code): array {
        $fields = [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ];

        try {
            $ch = curl_init($this->url('token_url'));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($fields),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
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
                return ['success' => false, 'error' => $data['error_description'] ?? $data['error'] ?? "OAuth token error (HTTP {$httpCode})"];
            }

            return ['success' => true, 'access_token' => $data['access_token']];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('SocialLoginClient token exchange failed', ['provider' => $this->provider, 'error' => $e->getMessage()]);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array|null ['id'=>string,'email'=>?string,'name'=>?string] أو null لو فشل */
    public function fetchProfile(string $accessToken): ?array {
        $url = $this->url('userinfo_url');
        if ($this->provider === 'facebook') {
            $url .= '?' . http_build_query(['fields' => 'id,name,email', 'access_token' => $accessToken]);
        }

        try {
            $ch = curl_init($url);
            $headers = ['Accept: application/json'];
            if ($this->provider !== 'facebook') {
                $headers[] = 'Authorization: Bearer ' . $accessToken;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                return null;
            }
            $data = json_decode($response, true);
            if (!is_array($data)) {
                return null;
            }

            $id = (string) ($data['sub'] ?? $data['id'] ?? '');
            if ($id === '') {
                return null;
            }

            return [
                'id' => $id,
                'email' => $data['email'] ?? null,
                'name' => $data['name'] ?? trim(($data['given_name'] ?? '') . ' ' . ($data['family_name'] ?? '')) ?: null,
            ];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('SocialLoginClient fetchProfile failed', ['provider' => $this->provider, 'error' => $e->getMessage()]);
            }
            return null;
        }
    }
}
