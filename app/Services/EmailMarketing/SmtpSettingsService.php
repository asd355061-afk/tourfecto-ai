<?php

/**
 * Tourfecto - Email Marketing SMTP Settings Service (المرحلة 4)
 * @version 1.0.0
 *
 * إدارة إعدادات SMTP لكل مستخدم (منافس Brevo/Mailchimp): المستخدم بيحدد
 * سيرفر البريد بتاعه (Hostinger/Gmail/...)، والخدمة بتبني Mailer مظبوط
 * ليه مع fallback لإعدادات .env العامة لو مفيهوش إعدادات مخصوصة.
 */
class SmtpSettingsService
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * جلب إعدادات SMTP الخاصة بمستخدم، أو null لو مفيش.
     */
    public function get(int $userId): ?array
    {
        $rows = $this->db->query(
            "SELECT * FROM email_smtp_settings WHERE user_id = ? LIMIT 1",
            [$userId]
        );
        return $rows[0] ?? null;
    }

    /**
     * جلب الإعدادات مع fallback لثوابت .env — المفتاح الأمثل لبناء Mailer.
     * @return array host/port/username/password/encryption/from_email/from_name
     */
    public function settingsForUser(int $userId): array
    {
        $row = $this->get($userId);
        // لو فيه إعدادات محفوظة لكن معطّلة => نرجع للـ .env العام
        if ($row && !(int) ($row['is_active'] ?? 0)) {
            $row = null;
        }
        return [
            'host' => ($row['host'] ?? '') !== '' ? $row['host'] : (defined('MAIL_HOST') ? MAIL_HOST : ''),
            'port' => !empty($row['port']) ? (int) $row['port'] : (defined('MAIL_PORT') ? (int) MAIL_PORT : 587),
            'username' => ($row['username'] ?? '') !== '' ? $row['username'] : (defined('MAIL_USERNAME') ? MAIL_USERNAME : ''),
            'password' => ($row['password'] ?? '') !== '' ? $row['password'] : (defined('MAIL_PASSWORD') ? MAIL_PASSWORD : ''),
            'encryption' => ($row['encryption'] ?? '') !== '' ? $row['encryption'] : (defined('MAIL_ENCRYPTION') ? strtolower(MAIL_ENCRYPTION) : 'tls'),
            'from_email' => ($row['from_email'] ?? '') !== '' ? $row['from_email'] : (defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'noreply@tourfecto.com'),
            'from_name' => ($row['from_name'] ?? '') !== '' ? $row['from_name'] : (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Tourfecto'),
        ];
    }

    /**
     * حفظ/تحديث إعدادات SMTP للمستخدم (upsert).
     * @return array ['success'=>bool, 'error'=>?string]
     */
    public function save(int $userId, array $data): array
    {
        if (isset($data['host']) && trim((string) $data['host']) === '') {
            return ['success' => false, 'error' => 'مضيف SMTP مطلوب'];
        }
        if (isset($data['username']) && trim((string) $data['username']) === '') {
            return ['success' => false, 'error' => 'اسم المستخدم مطلوب'];
        }
        if (isset($data['password']) && trim((string) $data['password']) === '') {
            return ['success' => false, 'error' => 'كلمة المرور مطلوبة'];
        }

        $existing = $this->get($userId);
        $row = $existing ?? [
            'user_id' => $userId,
            'host' => '',
            'port' => 587,
            'username' => '',
            'password' => '',
            'encryption' => 'tls',
            'from_email' => null,
            'from_name' => null,
            'is_active' => 0,
        ];

        foreach (['host', 'username', 'password', 'encryption', 'from_email', 'from_name'] as $field) {
            if (array_key_exists($field, $data)) {
                $row[$field] = $data[$field] !== null ? trim((string) $data[$field]) : null;
            }
        }
        if (array_key_exists('port', $data)) {
            $row['port'] = max(1, (int) $data['port']);
        }
        if (array_key_exists('is_active', $data)) {
            $row['is_active'] = !empty($data['is_active']) ? 1 : 0;
        }

        if ($existing) {
            $this->db->query(
                "UPDATE email_smtp_settings
                 SET host = ?, port = ?, username = ?, password = ?, encryption = ?,
                     from_email = ?, from_name = ?, is_active = ?
                 WHERE user_id = ?",
                [
                    $row['host'], (int) $row['port'], $row['username'], $row['password'],
                    $row['encryption'], $row['from_email'], $row['from_name'],
                    (int) $row['is_active'], $userId,
                ]
            );
        } else {
            $this->db->query(
                "INSERT INTO email_smtp_settings
                 (user_id, host, port, username, password, encryption, from_email, from_name, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $userId, $row['host'], (int) $row['port'], $row['username'], $row['password'],
                    $row['encryption'], $row['from_email'], $row['from_name'], (int) $row['is_active'],
                ]
            );
        }

        return ['success' => true];
    }

    /**
     * حذف إعدادات SMTP الخاصة بمستخدم نهائيًا (يرجع للـ fallback env).
     * @return array ['success'=>bool, 'deleted'=>bool]
     */
    public function delete(int $userId): array
    {
        $existing = $this->get($userId);
        if (!$existing) {
            return ['success' => true, 'deleted' => false];
        }
        $this->db->query(
            "DELETE FROM email_smtp_settings WHERE user_id = ?",
            [$userId]
        );
        return ['success' => true, 'deleted' => true];
    }

    /**
     * اختبار إعدادات SMTP (باتصال فعلي بالمصادقة). يقبل بيانات مؤقتة أو
     * بيستخدم المحفوظة.
     * @return array ['success'=>bool, 'error'=>?string]
     */
    public function test(int $userId, ?array $data = null): array
    {
        if ($data !== null && (!empty($data['host']) || !empty($data['username']))) {
            $settings = $this->settingsForUser($userId);
            $overrides = ['host', 'port', 'username', 'password', 'encryption', 'from_email', 'from_name'];
            foreach ($overrides as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                    $settings[$field] = $data[$field];
                }
            }
        } else {
            $settings = $this->settingsForUser($userId);
        }

        $mailer = new Mailer();
        $mailer->configure($settings);
        return $mailer->testConnection();
    }

    /**
     * بناء Mailer مظبوط لإعدادات المستخدم (مع fallback للـ .env).
     */
    public function mailerForUser(int $userId): Mailer
    {
        $mailer = new Mailer();
        $mailer->configure($this->settingsForUser($userId));
        return $mailer;
    }

    /**
     * هل إعدادات المستخدم جاهزة للإرسال فعليًا؟
     */
    public function isReady(int $userId): bool
    {
        $s = $this->settingsForUser($userId);
        return $s['host'] !== '' && $s['username'] !== ''
            && strpos($s['username'], 'your-email') === false
            && strpos($s['password'], 'your-app-password') === false;
    }
}
