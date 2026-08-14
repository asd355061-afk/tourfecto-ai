<?php
/**
 * Tourfecto - Meta Social Publishing API Client
 * نشر منشورات حقيقية على صفحة فيسبوك وحساب انستجرام العميل عن طريق
 * Meta Graph API الرسمي. نفس تطبيق Meta المستخدم لـ Meta Ads (نفس
 * META_APP_ID/META_APP_SECRET)، بس بصلاحيات إضافية (pages_manage_posts,
 * instagram_content_publish) اتضافت في MetaOAuthClient.
 * @version 1.0.0
 */
class MetaSocialAPI {
    private string $apiVersion;
    private string $accessToken;

    public function __construct(string $accessToken) {
        $this->apiVersion = env('META_API_VERSION') ?: 'v25.0';
        $this->accessToken = $accessToken;
    }

    /**
     * قائمة صفحات فيسبوك اللي صاحب التوكن أدمن عليها، ومعاها حساب
     * انستجرام بيزنس المربوط بيها لو موجود. كل صفحة برجع معاها
     * page_access_token خاص بيها (مختلف عن توكن المستخدم، وده اللي
     * بيتستخدم فعليًا للنشر).
     * @return array ['success'=>bool, 'pages'=>[['id','name','access_token','instagram_id']], 'error'=>?]
     */
    public function listPages(): array {
        $result = $this->get('me/accounts', [
            'fields' => 'id,name,access_token,instagram_business_account{id,username}',
        ]);

        if (!$result['success']) {
            return $result;
        }

        $pages = array_map(function ($p) {
            return [
                'id' => $p['id'],
                'name' => $p['name'] ?? $p['id'],
                'access_token' => $p['access_token'] ?? '',
                'instagram_id' => $p['instagram_business_account']['id'] ?? null,
                'instagram_username' => $p['instagram_business_account']['username'] ?? null,
            ];
        }, $result['data']['data'] ?? []);

        return ['success' => true, 'pages' => $pages];
    }

    /**
     * نشر منشور نصي (مع صورة اختيارية) على صفحة فيسبوك.
     * @param string $pageId
     * @param string $pageAccessToken توكن الصفحة (مش توكن المستخدم العام)
     * @param string $message نص المنشور
     * @param string|null $imageUrl رابط صورة عام (لازم يكون accessible من الإنترنت، مش رابط محلي)
     * @return array ['success'=>bool, 'post_id'=>?string, 'post_url'=>?string, 'error'=>?string]
     */
    public function publishToFacebookPage(string $pageId, string $pageAccessToken, string $message, ?string $imageUrl = null): array {
        $endpoint = $imageUrl ? "{$pageId}/photos" : "{$pageId}/feed";
        $payload = $imageUrl
            ? ['url' => $imageUrl, 'caption' => $message]
            : ['message' => $message];

        $result = $this->post($endpoint, $payload, $pageAccessToken);

        if (!$result['success']) {
            return $result;
        }

        $postId = $result['data']['post_id'] ?? $result['data']['id'] ?? null;

        return [
            'success' => true,
            'post_id' => $postId,
            'post_url' => $postId ? "https://www.facebook.com/{$postId}" : null,
        ];
    }

    /**
     * نشر صورة على انستجرام. خطوتين حسب Instagram Graph API الرسمي:
     * 1) إنشاء "media container" برابط الصورة والكابشن.
     * 2) نشر الـ container ده فعليًا.
     * @param string $igUserId معرف حساب انستجرام بيزنس (instagram_business_account.id)
     * @param string $pageAccessToken توكن صفحة الفيسبوك المرتبطة (انستجرام بيستخدم نفس توكن الصفحة)
     * @param string $imageUrl رابط صورة عام (مطلوب - انستجرام مش بيقبل نشر نص بس من غير صورة)
     * @param string $caption
     */
    public function publishToInstagram(string $igUserId, string $pageAccessToken, string $imageUrl, string $caption = ''): array {
        $containerResult = $this->post("{$igUserId}/media", [
            'image_url' => $imageUrl,
            'caption' => $caption,
        ], $pageAccessToken);

        if (!$containerResult['success']) {
            return $containerResult;
        }

        $containerId = $containerResult['data']['id'] ?? null;
        if (!$containerId) {
            return ['success' => false, 'error' => 'تعذر إنشاء container الصورة في انستجرام'];
        }

        $publishResult = $this->post("{$igUserId}/media_publish", [
            'creation_id' => $containerId,
        ], $pageAccessToken);

        if (!$publishResult['success']) {
            return $publishResult;
        }

        $postId = $publishResult['data']['id'] ?? null;

        return ['success' => true, 'post_id' => $postId, 'post_url' => null];
    }

    /**
     * نشر فيديو على صفحة فيسبوك عن طريق رابط عام (file_url) - فيسبوك
     * بيسحب الفيديو بنفسه ويعالجه، مفيش رفع bytes مباشر مطلوب هنا.
     * @param string $videoUrl رابط فيديو عام (mp4) - لازم يكون accessible من الإنترنت
     * @return array ['success'=>bool, 'post_id'=>?string, 'post_url'=>?string, 'error'=>?string]
     */
    public function publishVideoToFacebookPage(string $pageId, string $pageAccessToken, string $message, string $videoUrl): array {
        $result = $this->post("{$pageId}/videos", [
            'file_url' => $videoUrl,
            'description' => $message,
        ], $pageAccessToken);

        if (!$result['success']) {
            return $result;
        }

        $postId = $result['data']['id'] ?? null;

        return [
            'success' => true,
            'post_id' => $postId,
            'post_url' => $postId ? "https://www.facebook.com/{$pageId}/videos/{$postId}" : null,
        ];
    }

    /**
     * إنشاء "container" فيديو Reels في انستجرام - مرحلة أولى فقط.
     * انستجرام بيحتاج وقت لمعالجة الفيديو (async)، فمفيش نشر فوري هنا؛
     * لازم فحص checkInstagramContainerStatus() لحد ما status_code
     * يبقى FINISHED قبل النداء على publishInstagramContainer().
     * @return array ['success'=>bool, 'container_id'=>?string, 'error'=>?string]
     */
    public function createInstagramVideoContainer(string $igUserId, string $pageAccessToken, string $videoUrl, string $caption = ''): array {
        $result = $this->post("{$igUserId}/media", [
            'media_type' => 'REELS',
            'video_url' => $videoUrl,
            'caption' => $caption,
        ], $pageAccessToken);

        if (!$result['success']) {
            return $result;
        }

        $containerId = $result['data']['id'] ?? null;
        if (!$containerId) {
            return ['success' => false, 'error' => 'تعذر إنشاء container الفيديو في انستجرام'];
        }

        return ['success' => true, 'container_id' => $containerId];
    }

    /**
     * فحص حالة معالجة container الفيديو في انستجرام.
     * @return array ['success'=>bool, 'status'=>?string (IN_PROGRESS|FINISHED|ERROR|EXPIRED), 'error'=>?string]
     */
    public function checkInstagramContainerStatus(string $containerId, string $pageAccessToken): array {
        $result = $this->request('GET', $containerId, ['fields' => 'status_code', 'access_token' => $pageAccessToken]);

        if (!$result['success']) {
            return $result;
        }

        return ['success' => true, 'status' => $result['data']['status_code'] ?? 'IN_PROGRESS'];
    }

    /**
     * نشر container (صورة أو فيديو) بعد التأكد إنه جاهز.
     * @return array ['success'=>bool, 'post_id'=>?string, 'error'=>?string]
     */
    public function publishInstagramContainer(string $igUserId, string $pageAccessToken, string $containerId): array {
        $publishResult = $this->post("{$igUserId}/media_publish", [
            'creation_id' => $containerId,
        ], $pageAccessToken);

        if (!$publishResult['success']) {
            return $publishResult;
        }

        return ['success' => true, 'post_id' => $publishResult['data']['id'] ?? null];
    }

    private function get(string $path, array $query = []): array {
        $query['access_token'] = $this->accessToken;
        return $this->request('GET', $path, $query);
    }

    private function post(string $path, array $data, ?string $tokenOverride = null): array {
        $data['access_token'] = $tokenOverride ?? $this->accessToken;
        return $this->request('POST', $path, [], $data);
    }

    private function request(string $method, string $path, array $query = [], array $data = []): array {
        try {
            $url = "https://graph.facebook.com/{$this->apiVersion}/{$path}";
            if (!empty($query)) {
                $url .= '?' . http_build_query($query);
            }

            $ch = curl_init($url);
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_CUSTOMREQUEST => $method,
            ];

            if ($method === 'POST') {
                $options[CURLOPT_POSTFIELDS] = http_build_query($data);
            }

            curl_setopt_array($ch, $options);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                return ['success' => false, 'error' => 'cURL Error: ' . $curlError];
            }

            $decoded = json_decode($response, true);

            if ($httpCode < 200 || $httpCode >= 300 || isset($decoded['error'])) {
                return [
                    'success' => false,
                    'error' => $decoded['error']['message'] ?? "Meta API error (HTTP {$httpCode})",
                ];
            }

            return ['success' => true, 'data' => $decoded];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Meta Social API request failed', ['path' => $path, 'error' => $e->getMessage()]);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
