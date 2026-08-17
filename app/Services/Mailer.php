<?php

/**
 * Tourfecto - Mailer (SMTP خالص بـ PHP، من غير أي مكتبة خارجية زي
 * PHPMailer). السبب: السيرفر ده مفيهوش Terminal/SSH لتشغيل composer
 * require، فأي مكتبة خارجية محتاجة تتضاف كملفات يدويًا. بديل بسيط:
 * كلاس SMTP خفيف بيتكلم مباشرة مع سيرفر البريد عن طريق sockets،
 * بيدعم STARTTLS وAUTH LOGIN (كافي لأي مزوّد شائع زي Gmail/Hostinger).
 * @version 1.0.0
 */
class Mailer
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $encryption; // tls | ssl | ''
    private string $fromEmail;
    private string $fromName;
    private int $timeout = 15;

    public function __construct()
    {
        $this->host = defined('MAIL_HOST') ? MAIL_HOST : '';
        $this->port = defined('MAIL_PORT') ? (int) MAIL_PORT : 587;
        $this->username = defined('MAIL_USERNAME') ? MAIL_USERNAME : '';
        $this->password = defined('MAIL_PASSWORD') ? MAIL_PASSWORD : '';
        $this->encryption = defined('MAIL_ENCRYPTION') ? strtolower(MAIL_ENCRYPTION) : 'tls';
        $this->fromEmail = defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'noreply@tourfecto.com';
        $this->fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Tourfecto';
    }

    /** هل الإيميل متظبط أصلاً؟ (يوزر/باسورد حقيقيين مش placeholders) */
    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->username !== '' && $this->password !== ''
            && strpos($this->username, 'your-email') === false
            && strpos($this->password, 'your-app-password') === false;
    }

    /**
     * @return array ['success'=>bool, 'error'=>?string]
     */
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'إعدادات البريد (MAIL_USERNAME/MAIL_PASSWORD في .env) لسه مش متظبطة'];
        }

        $socketHost = ($this->encryption === 'ssl' ? 'ssl://' : '') . $this->host;

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client("{$socketHost}:{$this->port}", $errno, $errstr, $this->timeout);

        if (!$socket) {
            return ['success' => false, 'error' => "تعذر الاتصال بسيرفر البريد ({$errstr})"];
        }

        try {
            $this->expect($socket, '220');
            $this->command($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'tourfecto.pro'), '250');

            if ($this->encryption === 'tls') {
                $this->command($socket, "STARTTLS", '220');
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception('فشل تفعيل TLS مع سيرفر البريد');
                }
                $this->command($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'tourfecto.pro'), '250');
            }

            $this->command($socket, "AUTH LOGIN", '334');
            $this->command($socket, base64_encode($this->username), '334');
            $this->command($socket, base64_encode($this->password), '235');

            $this->command($socket, "MAIL FROM:<{$this->fromEmail}>", '250');
            $this->command($socket, "RCPT TO:<{$toEmail}>", '250');
            $this->command($socket, "DATA", '354');

            $boundaryDate = date('r');
            $fromNameEncoded = '=?UTF-8?B?' . base64_encode($this->fromName) . '?=';
            $toNameEncoded = '=?UTF-8?B?' . base64_encode($toName) . '?=';
            $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

            $headers = "Date: {$boundaryDate}\r\n"
                . "From: {$fromNameEncoded} <{$this->fromEmail}>\r\n"
                . "To: {$toNameEncoded} <{$toEmail}>\r\n"
                . "Subject: {$subjectEncoded}\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n";

            // كل سطر يبدأ بنقطة لازم يتضاعف (SMTP dot-stuffing)
            $escapedBody = preg_replace('/^\./m', '..', $htmlBody);

            $this->rawWrite($socket, $headers . "\r\n" . $escapedBody . "\r\n.\r\n");
            $this->expect($socket, '250');

            $this->rawWrite($socket, "QUIT\r\n");
            fclose($socket);

            return ['success' => true];
        } catch (Exception $e) {
            @fclose($socket);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function rawWrite($socket, string $data): void
    {
        fwrite($socket, $data);
    }

    private function command($socket, string $cmd, string $expectedCode): string
    {
        fwrite($socket, $cmd . "\r\n");
        return $this->expect($socket, $expectedCode);
    }

    private function expect($socket, string $expectedCode): string
    {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            // آخر سطر في رد SMTP بيكون فيه مسافة بعد الكود (مش شرطة -)
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        if (strpos($response, $expectedCode) !== 0) {
            throw new Exception("رد غير متوقع من سيرفر البريد: {$response}");
        }

        return $response;
    }
}
