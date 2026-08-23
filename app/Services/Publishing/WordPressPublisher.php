<?php

/**
 * Tourfecto - WordPress Publisher
 * نشر مقال جاهز مباشرة على موقع ووردبريس الخاص بالعميل عن طريق
 * WP REST API (wp-json/wp/v2/posts) باستخدام Application Passwords
 * (ميزة أصلية في ووردبريس من نسخة 5.6+ ، مش إضافة/plugin خارجي).
 * @version 1.0.0
 *
 * ليه Application Passwords تحديدًا مش OAuth؟ لأن مواقع العملاء هنا
 * مخصوصة/متفرقة (مش منصة موحدة زي Google) - مفيش OAuth client مركزي
 * ممكن نسجله. Application Passwords بديل رسمي من ووردبريس نفسه، عبارة
 * عن يوزر + باسورد تطبيق العميل بيولّده بنفسه من لوحة تحكم موقعه
 * (Users → Profile → Application Passwords) ويلزقه هنا مرة واحدة.
 */
class WordPressPublisher
{
    private int $timeout = 30;

    /**
     * تجربة الاتصال (بنجيب بيانات المستخدم الحالي /users/me) للتأكد إن
     * الرابط + اليوزر + الباسورد صحيحين قبل ما نحفظهم.
     */
    public function testConnection(string $siteUrl, string $username, string $appPassword): array
    {
        $response = $this->request('GET', $siteUrl, '/wp-json/wp/v2/users/me', $username, $appPassword);

        if (!$response['success']) {
            return $response;
        }

        return [
            'success' => true,
            'user' => [
                'id' => $response['data']['id'] ?? null,
                'name' => $response['data']['name'] ?? null,
            ],
        ];
    }

    /**
     * نشر مقال جديد كـ post على ووردبريس.
     * @param string $status 'publish' (نشر مباشر) أو 'draft' (مسودة يراجعها العميل الأول)
     * @return array ['success'=>bool, 'post_id'=>?int, 'url'=>?string, 'error'=>?string]
     */
    public function createPost(string $siteUrl, string $username, string $appPassword, string $title, string $htmlContent, string $excerpt = '', string $status = 'publish'): array
    {
        $response = $this->request('POST', $siteUrl, '/wp-json/wp/v2/posts', $username, $appPassword, [
            'title' => $title,
            'content' => $htmlContent,
            'excerpt' => $excerpt,
            'status' => $status, // publish|draft
        ]);

        if (!$response['success']) {
            return $response;
        }

        return [
            'success' => true,
            'post_id' => $response['data']['id'] ?? null,
            'url' => $response['data']['link'] ?? null,
        ];
    }

    /**
     * تحديث post موجود (لو المقال اتنشر قبل كده وعايزين نعيد النشر بعد تعديل).
     */
    public function updatePost(string $siteUrl, string $username, string $appPassword, int $postId, string $title, string $htmlContent, string $excerpt = ''): array
    {
        $response = $this->request('POST', $siteUrl, '/wp-json/wp/v2/posts/' . $postId, $username, $appPassword, [
            'title' => $title,
            'content' => $htmlContent,
            'excerpt' => $excerpt,
        ]);

        if (!$response['success']) {
            return $response;
        }

        return [
            'success' => true,
            'post_id' => $response['data']['id'] ?? null,
            'url' => $response['data']['link'] ?? null,
        ];
    }

    private function request(string $method, string $siteUrl, string $path, string $username, string $appPassword, array $data = []): array
    {
        $url = rtrim($siteUrl, '/') . $path;

        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($username . ':' . $appPassword),
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Tourfecto/1.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ];

        if (!empty($data)) {
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'تعذر الوصول لموقعك: ' . $curlError];
        }

        $decoded = json_decode($response, true);

        if ($httpCode === 401 || $httpCode === 403) {
            return ['success' => false, 'error' => 'بيانات الدخول غلط أو الحساب مالوش صلاحية نشر (تأكد من اليوزر و Application Password)'];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = $decoded['message'] ?? "خطأ غير متوقع (HTTP {$httpCode})";
            return ['success' => false, 'error' => "WordPress API Error: {$errorMessage}", 'http_code' => $httpCode];
        }

        if (!is_array($decoded)) {
            return ['success' => false, 'error' => 'رد غير متوقع من الموقع - تأكد إن الرابط صحيح وإن REST API مفعّل'];
        }

        return ['success' => true, 'data' => $decoded, 'http_code' => $httpCode];
    }

    /**
     * تحويل مبسّط من Markdown (الصيغة اللي بيتولد بيها المقال) لـ HTML
     * صالح للنشر على ووردبريس. مش parser كامل، بس كافي لعناوين/فقرات/Bold
     * اللي بيولدها الـ AI Engine.
     */
    public static function markdownToHtml(string $markdown): string
    {
        $escaped = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');

        $escaped = preg_replace('/^### (.*)$/m', '<h4>$1</h4>', $escaped);
        $escaped = preg_replace('/^## (.*)$/m', '<h3>$1</h3>', $escaped);
        $escaped = preg_replace('/^# (.*)$/m', '<h2>$1</h2>', $escaped);
        $escaped = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $escaped);

        $blocks = preg_split('/\n{2,}/', $escaped);
        $html = array_map(function ($block) {
            $block = trim($block);
            if ($block === '') {
                return '';
            }
            if (strpos($block, '<h') === 0) {
                return $block;
            }
            return '<p>' . nl2br($block) . '</p>';
        }, $blocks);

        return implode("\n", array_filter($html));
    }
}
