<?php
/**
 * Tourfecto - Custom API Publisher
 * نشر المقال على أي موقع، حتى لو مبني ببرمجة خاصة (مش ووردبريس ولا أي
 * CMS معروف). الفكرة: مبرمج موقع العميل بيعمل عنده "نقطة استقبال" واحدة
 * (endpoint) بسيطة بتستقبل المقال بصيغة JSON، وإحنا بس بنبعتله عليها.
 * @version 1.0.0
 *
 * ده الحل الواقعي الوحيد لأي موقع مخصوص، لأن مفيش API عام موحّد لكل
 * المواقع زي ما بيحصل مع ووردبريس. العميل (أو مبرمجه) هو اللي بيقرر
 * شكل الـ endpoint، إحنا بس بنلتزم بعقد بسيط وثابت (شوف
 * docs/CUSTOM_PUBLISHING_INTEGRATION.md اللي بيتبعت لمبرمج العميل).
 */
class CustomApiPublisher {
    private int $timeout = 30;

    /**
     * إرسال المقال لنقطة الاستقبال بتاعة موقع العميل.
     * @param string $endpointUrl الرابط اللي مبرمج العميل جهّزه
     * @param string $authToken توكن/مفتاح سري (بيتبعت في Authorization header) - ممكن يكون فاضي لو الـ endpoint مفتوح
     * @param array $article بيانات المقال (title, content_html, content_markdown, meta_description, slug, keywords, article_id)
     * @param bool $isTest لو true، بنبعت is_test=true عشان مبرمج العميل يقدر يتأكد من الربط من غير ما ينشر حاجة فعليًا
     */
    public function publish(string $endpointUrl, string $authToken, array $article, bool $isTest = false): array {
        $payload = array_merge($article, ['is_test' => $isTest, 'source' => 'tourfecto']);

        $ch = curl_init($endpointUrl);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($authToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $authToken;
            $headers[] = 'X-Tourfecto-Secret: ' . $authToken; // بديل لمبرمجين مبيقروش Authorization header بسهولة
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Tourfecto/1.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'تعذر الوصول لنقطة الاستقبال: ' . $curlError];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return ['success' => false, 'error' => "موقعك رفض الطلب (HTTP {$httpCode}). راجع مبرمج الموقع.", 'http_code' => $httpCode, 'raw' => substr((string) $response, 0, 500)];
        }

        $decoded = json_decode((string) $response, true);
        $publishedUrl = is_array($decoded) ? ($decoded['url'] ?? $decoded['published_url'] ?? null) : null;

        return ['success' => true, 'url' => $publishedUrl, 'http_code' => $httpCode];
    }
}
