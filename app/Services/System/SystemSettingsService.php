<?php

/**
 * Tourfecto - System Settings Service
 * القراءة والكتابة الآمنة لكل إعدادات النظام (مفاتيح API، بيانات الربط)
 * القابلة للتعديل من لوحة الأدمن. القيم الحساسة بتتشفّر في قاعدة
 * البيانات، ومفيش قيمة حساسة كاملة بترجع للواجهة الأمامية أبدًا.
 * @version 1.0.0
 */
class SystemSettingsService
{
    /** @var Database */
    private $db;
    /** @var Encryption */
    private $encryption;

    /** @var array السجل الكامل لكل إعدادات النظام المعروفة */
    private static array $registry = [
        'gemini_api_key' => ['category' => 'ai', 'label' => 'Gemini API Key', 'is_secret' => true, 'constant' => 'GEMINI_API_KEY'],

        'google_client_id' => ['category' => 'google', 'label' => 'Google Client ID', 'is_secret' => false, 'constant' => 'GOOGLE_CLIENT_ID'],
        'google_client_secret' => ['category' => 'google', 'label' => 'Google Client Secret', 'is_secret' => true, 'constant' => 'GOOGLE_CLIENT_SECRET'],
        'google_maps_api_key' => ['category' => 'google', 'label' => 'Google Maps / Places API Key', 'is_secret' => true, 'constant' => 'GOOGLE_MAPS_API_KEY'],

        'meta_app_id' => ['category' => 'meta', 'label' => 'Meta App ID', 'is_secret' => false, 'constant' => 'META_APP_ID'],
        'meta_app_secret' => ['category' => 'meta', 'label' => 'Meta App Secret', 'is_secret' => true, 'constant' => 'META_APP_SECRET'],

        // === Google Ads (بيستخدم google_client_id/secret فوق لتسجيل الدخول، ومحتاج Developer Token خاص بيه) ===
        'google_ads_developer_token' => ['category' => 'google_ads', 'label' => 'Google Ads Developer Token', 'is_secret' => true, 'constant' => 'GOOGLE_ADS_DEVELOPER_TOKEN'],
        'google_ads_login_customer_id' => ['category' => 'google_ads', 'label' => 'Manager Account ID (MCC) - اختياري', 'is_secret' => false, 'constant' => 'GOOGLE_ADS_LOGIN_CUSTOMER_ID'],

        'support_whatsapp_number' => ['category' => 'whatsapp', 'label' => 'رقم واتساب الدعم/الاشتراكات', 'is_secret' => false, 'constant' => 'SUPPORT_WHATSAPP_NUMBER'],

        // === تسجيل الدخول الاجتماعي (Google/Facebook بيستخدموا نفس بيانات الاعتماد فوق) ===
        'oauth_microsoft_client_id' => ['category' => 'oauth_login', 'label' => 'Microsoft Client ID', 'is_secret' => false, 'constant' => ''],
        'oauth_microsoft_client_secret' => ['category' => 'oauth_login', 'label' => 'Microsoft Client Secret', 'is_secret' => true, 'constant' => ''],
        'oauth_microsoft_tenant' => ['category' => 'oauth_login', 'label' => 'Microsoft Tenant (افتراضي: common)', 'is_secret' => false, 'constant' => ''],
        'oauth_apple_client_id' => ['category' => 'oauth_login', 'label' => 'Apple Services ID (client_id)', 'is_secret' => false, 'constant' => ''],
        'oauth_apple_team_id' => ['category' => 'oauth_login', 'label' => 'Apple Team ID', 'is_secret' => false, 'constant' => ''],
        'oauth_apple_key_id' => ['category' => 'oauth_login', 'label' => 'Apple Key ID', 'is_secret' => false, 'constant' => ''],
        'oauth_apple_private_key' => ['category' => 'oauth_login', 'label' => 'Apple Private Key (.p8)', 'is_secret' => true, 'constant' => ''],

        'mail_host' => ['category' => 'mail', 'label' => 'SMTP Host', 'is_secret' => false, 'constant' => 'MAIL_HOST'],
        'mail_port' => ['category' => 'mail', 'label' => 'SMTP Port', 'is_secret' => false, 'constant' => 'MAIL_PORT'],
        'mail_username' => ['category' => 'mail', 'label' => 'SMTP Username', 'is_secret' => false, 'constant' => 'MAIL_USERNAME'],
        'mail_password' => ['category' => 'mail', 'label' => 'SMTP Password', 'is_secret' => true, 'constant' => 'MAIL_PASSWORD'],
        'mail_encryption' => ['category' => 'mail', 'label' => 'SMTP Encryption (tls/ssl)', 'is_secret' => false, 'constant' => 'MAIL_ENCRYPTION'],
        'mail_from_address' => ['category' => 'mail', 'label' => 'Mail From Address', 'is_secret' => false, 'constant' => 'MAIL_FROM_ADDRESS'],
        'mail_from_name' => ['category' => 'mail', 'label' => 'Mail From Name', 'is_secret' => false, 'constant' => 'MAIL_FROM_NAME'],

        // === هوية الموقع (الاسم، اللوجو، بيانات التواصل) ===
        'site_name' => ['category' => 'branding', 'label' => 'اسم الموقع', 'is_secret' => false, 'constant' => 'APP_NAME'],
        'site_logo_url' => ['category' => 'branding', 'label' => 'رابط اللوجو', 'is_secret' => false, 'constant' => ''],
        'site_logo_height' => ['category' => 'branding', 'label' => 'ارتفاع اللوجو (بكسل)', 'is_secret' => false, 'constant' => ''],
        'site_favicon_url' => ['category' => 'branding', 'label' => 'رابط أيقونة المتصفح (Favicon)', 'is_secret' => false, 'constant' => ''],
        'contact_phone' => ['category' => 'branding', 'label' => 'رقم الهاتف العام', 'is_secret' => false, 'constant' => ''],
        'contact_email' => ['category' => 'branding', 'label' => 'البريد الإلكتروني العام', 'is_secret' => false, 'constant' => ''],
        'site_address' => ['category' => 'branding', 'label' => 'العنوان', 'is_secret' => false, 'constant' => ''],

        // === المحتوى القانوني (شروط الخدمة وسياسة الخصوصية) ===
        'terms_content' => ['category' => 'legal', 'label' => 'شروط الخدمة', 'is_secret' => false, 'constant' => ''],
        'privacy_content' => ['category' => 'legal', 'label' => 'سياسة الخصوصية', 'is_secret' => false, 'constant' => ''],
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->encryption = new Encryption();
    }

    /**
     * قراءة إعداد - بيفحص قاعدة البيانات الأول، ولو مش موجود أو فاضي
     * بيرجع للقيمة الافتراضية (عادةً القيمة الحالية في .env). آمن 100%
     * حتى لو الجدول مش موجود أو فاضي.
     */
    public function get(string $key, string $default = ''): string
    {
        try {
            $rows = $this->db->query("SELECT setting_value, is_secret FROM system_settings WHERE setting_key = ? LIMIT 1", [$key]);
            if (empty($rows) || $rows[0]['setting_value'] === null || $rows[0]['setting_value'] === '') {
                return $default;
            }
            $value = $rows[0]['setting_value'];
            return (bool) $rows[0]['is_secret'] ? $this->encryption->decrypt($value) : $value;
        } catch (Exception $e) {
            return $default;
        }
    }

    /** حفظ إعداد واحد - بيشفّر القيمة تلقائيًا لو الإعداد ده معروف إنه حساس */
    public function set(string $key, string $value): void
    {
        $meta = self::$registry[$key] ?? ['category' => 'general', 'is_secret' => false];
        $isSecret = (bool) $meta['is_secret'];
        $storedValue = $isSecret && $value !== '' ? $this->encryption->encrypt($value) : $value;

        $this->db->exec(
            "INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret)",
            [$key, $storedValue, $isSecret ? 1 : 0, $meta['category']]
        );
    }

    /**
     * كل الإعدادات المعروفة مجهّزة لعرضها في لوحة الأدمن، مجمّعة حسب
     * التصنيف. القيم الحساسة بترجع مقنّعة (مفيش عرض خالص) - الأدمن
     * بيكتب قيمة جديدة كاملة لو عايز يغيّرها، مش بيشوف القديمة أبدًا.
     */
    public function getAllForAdmin(): array
    {
        $grouped = [];
        foreach (self::$registry as $key => $meta) {
            $default = '';
            if ($key === 'terms_content' && class_exists('LegalController')) {
                $default = LegalController::getDefaultTermsHtml();
            } elseif ($key === 'privacy_content' && class_exists('LegalController')) {
                $default = LegalController::getDefaultPrivacyHtml();
            } elseif (defined($meta['constant'])) {
                $default = (string) constant($meta['constant']);
            }

            $currentValue = $this->get($key, $default);
            $isSet = $currentValue !== '';

            $displayValue = ($isSet && !$meta['is_secret']) ? $currentValue : '';

            $grouped[$meta['category']][] = [
                'key' => $key,
                'label' => $meta['label'],
                'is_secret' => $meta['is_secret'],
                'is_set' => $isSet,
                'value' => $displayValue,
            ];
        }
        return $grouped;
    }
}
