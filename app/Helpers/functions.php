<?php

/**
 * Tourfecto - Functions Helper
 * دوال مساعدة عامة للاستخدام في جميع أنحاء التطبيق
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

if (!function_exists('dd')) {
    /**
     * Dump and Die - عرض محتويات المتغير وإيقاف التنفيذ
     * @param mixed ...$vars
     */
    function dd(...$vars): void
    {
        echo '<pre style="background: #1a1a1a; color: #f8f8f8; padding: 20px; border-radius: 8px; font-family: monospace; direction: ltr; text-align: left;">';
        foreach ($vars as $var) {
            var_dump($var);
            echo "\n\n";
        }
        echo '</pre>';
        die;
    }
}

if (!function_exists('dump')) {
    /**
     * Dump - عرض محتويات المتغير
     * @param mixed ...$vars
     */
    function dump(...$vars): void
    {
        echo '<pre style="background: #1a1a1a; color: #f8f8f8; padding: 20px; border-radius: 8px; font-family: monospace; direction: ltr; text-align: left;">';
        foreach ($vars as $var) {
            var_dump($var);
            echo "\n\n";
        }
        echo '</pre>';
    }
}

if (!function_exists('env')) {
    /**
     * الحصول على قيمة من متغيرات البيئة
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function env(string $key, $default = null)
    {
        // ملاحظة مهمة: على استضافات مشتركة كتير (زي Hostinger) بتكون دالة putenv()
        // ممنوعة (disable_functions) لأسباب أمنية. مكتبة vlucas/phpdotenv بتكتشف
        // ده تلقائيًا وبترجع None لأدابتر الـ putenv (من غير أي خطأ)، فبتفضل بس
        // تكتب القيم في $_ENV و $_SERVER. يعني getenv() لوحدها بترجع false دايمًا
        // في الحالة دي حتى لو .env اتحمّل صح فعلاً — فلازم ندوّر في $_ENV/$_SERVER
        // الأول قبل ما نلجأ لـ getenv() كخيار أخير.
        if (array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
        } elseif (array_key_exists($key, $_SERVER)) {
            $value = $_SERVER[$key];
        } else {
            $value = getenv($key);
        }

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        // تحويل القيم المنطقية
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'null':
            case '(null)':
                return null;
        }

        // إزالة علامات الاقتباس
        if (strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) {
            $value = substr($value, 1, -1);
        }

        if (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }
}

if (!function_exists('config')) {
    /**
     * الحصول على قيمة من ملفات التكوين
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function config(string $key, $default = null)
    {
        static $config = [];

        $parts = explode('.', $key);
        $file = $parts[0] ?? '';
        $configKey = $parts[1] ?? null;

        if (!isset($config[$file])) {
            $path = TOURFECTO_APP . "/Config/{$file}.php";
            if (file_exists($path)) {
                $config[$file] = require $path;
            } else {
                return $default;
            }
        }

        if ($configKey === null) {
            return $config[$file];
        }

        return $config[$file][$configKey] ?? $default;
    }
}

if (!function_exists('json_response')) {
    /**
     * إرجاع استجابة JSON
     * @param mixed $data
     * @param int $statusCode
     * @param bool $pretty
     */
    function json_response($data, int $statusCode = 200, bool $pretty = false): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        $flags = JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        echo json_encode($data, $flags);
        exit;
    }
}

if (!function_exists('success_response')) {
    /**
     * إرجاع استجابة نجاح
     * @param array $data
     * @param string $message
     * @param int $statusCode
     */
    function success_response(array $data = [], string $message = 'Success', int $statusCode = 200): void
    {
        json_response([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }
}

if (!function_exists('error_response')) {
    /**
     * إرجاع استجابة خطأ
     * @param string $message
     * @param int $statusCode
     * @param array $errors
     */
    function error_response(string $message = 'Error', int $statusCode = 400, array $errors = []): void
    {
        $response = [
            'success' => false,
            'error' => $message,
            'code' => $statusCode
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        json_response($response, $statusCode);
    }
}

if (!function_exists('generate_uuid')) {
    /**
     * توليد UUID v4
     * @return string
     */
    function generate_uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('generate_random_string')) {
    /**
     * توليد سلسلة عشوائية
     * @param int $length
     * @param string $characters
     * @return string
     */
    function generate_random_string(int $length = 32, string $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'): string
    {
        $string = '';
        $max = strlen($characters) - 1;

        for ($i = 0; $i < $length; $i++) {
            $string .= $characters[random_int(0, $max)];
        }

        return $string;
    }
}

if (!function_exists('generate_secure_token')) {
    /**
     * توليد توكن آمن
     * @param int $length
     * @return string
     */
    function generate_secure_token(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }
}

if (!function_exists('is_valid_email')) {
    /**
     * التحقق من صحة البريد الإلكتروني
     * @param string $email
     * @return bool
     */
    function is_valid_email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('is_valid_url')) {
    /**
     * التحقق من صحة الرابط
     * @param string $url
     * @return bool
     */
    function is_valid_url(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}

if (!function_exists('is_valid_phone')) {
    /**
     * التحقق من صحة رقم الهاتف
     * @param string $phone
     * @return bool
     */
    function is_valid_phone(string $phone): bool
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        return strlen($phone) >= 8 && strlen($phone) <= 15;
    }
}

if (!function_exists('is_valid_ip')) {
    /**
     * التحقق من صحة عنوان IP
     * @param string $ip
     * @return bool
     */
    function is_valid_ip(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}

if (!function_exists('get_client_ip')) {
    /**
     * الحصول على IP العميل
     * @return string
     */
    function get_client_ip(): string
    {
        $ips = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['HTTP_X_REAL_IP'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ];

        foreach ($ips as $ip) {
            if ($ip) {
                $ip = explode(',', $ip)[0];
                $ip = trim($ip);

                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}

if (!function_exists('get_user_agent')) {
    /**
     * الحصول على User Agent
     * @return string
     */
    function get_user_agent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }
}

if (!function_exists('is_ajax_request')) {
    /**
     * التحقق من طلب AJAX
     * @return bool
     */
    function is_ajax_request(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

if (!function_exists('is_https')) {
    /**
     * التحقق من اتصال HTTPS
     * @return bool
     */
    function is_https(): bool
    {
        return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }
}

if (!function_exists('get_current_url')) {
    /**
     * الحصول على الرابط الحالي
     * @param bool $withQuery
     * @return string
     */
    function get_current_url(bool $withQuery = true): string
    {
        $protocol = is_https() ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        if (!$withQuery) {
            $uri = strtok($uri, '?');
        }

        return $protocol . $host . $uri;
    }
}

if (!function_exists('redirect')) {
    /**
     * إعادة توجيه
     * @param string $url
     * @param int $statusCode
     */
    function redirect(string $url, int $statusCode = 302): void
    {
        http_response_code($statusCode);
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('back')) {
    /**
     * العودة إلى الصفحة السابقة
     */
    function back(): void
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        redirect($referer);
    }
}

if (!function_exists('get_file_size')) {
    /**
     * الحصول على حجم الملف بصيغة قابلة للقراءة
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    function get_file_size(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

if (!function_exists('get_time_ago')) {
    /**
     * الحصول على الوقت المنقضي بصيغة قابلة للقراءة
     * @param string $datetime
     * @return string
     */
    function get_time_ago(string $datetime): string
    {
        $time = strtotime($datetime);
        $diff = time() - $time;

        if ($diff < 60) {
            return 'منذ ' . $diff . ' ثانية' . ($diff != 1 ? 'ات' : '');
        }

        $diff = floor($diff / 60);
        if ($diff < 60) {
            return 'منذ ' . $diff . ' دقيقة' . ($diff != 1 ? '' : '');
        }

        $diff = floor($diff / 60);
        if ($diff < 24) {
            return 'منذ ' . $diff . ' ساعة' . ($diff != 1 ? '' : '');
        }

        $diff = floor($diff / 24);
        if ($diff < 30) {
            return 'منذ ' . $diff . ' يوم' . ($diff != 1 ? '' : '');
        }

        $diff = floor($diff / 30);
        if ($diff < 12) {
            return 'منذ ' . $diff . ' شهر' . ($diff != 1 ? '' : '');
        }

        $diff = floor($diff / 12);
        return 'منذ ' . $diff . ' سنة' . ($diff != 1 ? '' : '');
    }
}

if (!function_exists('format_datetime')) {
    /**
     * تنسيق التاريخ والوقت
     * @param string $datetime
     * @param string $format
     * @return string
     */
    function format_datetime(string $datetime, string $format = 'Y-m-d H:i:s'): string
    {
        return date($format, strtotime($datetime));
    }
}

if (!function_exists('format_date')) {
    /**
     * تنسيق التاريخ
     * @param string $date
     * @param string $format
     * @return string
     */
    function format_date(string $date, string $format = 'Y-m-d'): string
    {
        return date($format, strtotime($date));
    }
}

if (!function_exists('format_time')) {
    /**
     * تنسيق الوقت
     * @param string $time
     * @param string $format
     * @return string
     */
    function format_time(string $time, string $format = 'H:i:s'): string
    {
        return date($format, strtotime($time));
    }
}

if (!function_exists('get_days_between')) {
    /**
     * الحصول على عدد الأيام بين تاريخين
     * @param string $start
     * @param string $end
     * @return int
     */
    function get_days_between(string $start, string $end): int
    {
        $start = strtotime($start);
        $end = strtotime($end);
        $diff = $end - $start;
        return (int) floor($diff / (60 * 60 * 24));
    }
}

if (!function_exists('get_age')) {
    /**
     * حساب العمر من تاريخ الميلاد
     * @param string $birthdate
     * @return int
     */
    function get_age(string $birthdate): int
    {
        $birth = new DateTime($birthdate);
        $now = new DateTime();
        $age = $now->diff($birth);
        return $age->y;
    }
}

if (!function_exists('truncate_text')) {
    /**
     * قص النص إلى طول معين
     * @param string $text
     * @param int $length
     * @param string $suffix
     * @return string
     */
    function truncate_text(string $text, int $length = 100, string $suffix = '...'): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length) . $suffix;
    }
}

if (!function_exists('strip_html')) {
    /**
     * إزالة علامات HTML
     * @param string $text
     * @return string
     */
    function strip_html(string $text): string
    {
        return strip_tags($text);
    }
}

if (!function_exists('sanitize_text')) {
    /**
     * تنظيف النص
     * @param string $text
     * @param bool $stripTags
     * @return string
     */
    function sanitize_text(string $text, bool $stripTags = true): string
    {
        if ($stripTags) {
            $text = strip_tags($text);
        }

        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        return $text;
    }
}

if (!function_exists('slugify')) {
    /**
     * تحويل النص إلى Slug
     * @param string $text
     * @param string $separator
     * @return string
     */
    function slugify(string $text, string $separator = '-'): string
    {
        // تحويل النص إلى أحرف صغيرة
        $text = mb_strtolower($text, 'UTF-8');

        // استبدال الأحرف العربية بأحرف لاتينية
        $arabicToLatin = [
            'ا' => 'a', 'ب' => 'b', 'ت' => 't', 'ث' => 'th', 'ج' => 'j',
            'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'dh', 'ر' => 'r',
            'ز' => 'z', 'س' => 's', 'ش' => 'sh', 'ص' => 's', 'ض' => 'd',
            'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'gh', 'ف' => 'f',
            'ق' => 'q', 'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n',
            'ه' => 'h', 'و' => 'w', 'ي' => 'y', 'ى' => 'a', 'ة' => 'h'
        ];

        $text = str_replace(array_keys($arabicToLatin), array_values($arabicToLatin), $text);

        // إزالة الأحرف غير المسموحة
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);

        // استبدال المسافات بالفاصل
        $text = preg_replace('/[\s-]+/', $separator, $text);

        // إزالة الفاصل من البداية والنهاية
        $text = trim($text, $separator);

        return $text;
    }
}
