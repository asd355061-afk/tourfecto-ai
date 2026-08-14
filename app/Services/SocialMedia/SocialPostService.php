<?php
/**
 * Tourfecto - Social Post Service
 * منطق عمل السوشيال ميديا: إنشاء منشور، توليد نص/هاشتاجات بالذكاء
 * الاصطناعي (يعيد استخدام GeminiClient الموجود فعلاً - نفس محرك مقالات
 * SEO - بدل عميل AI منفصل كان في الموديول الأصلي)، وجدولة النشر لكل
 * منصة عبر جدول jobs الموحّد.
 * @version 1.0.0
 */
class SocialPostService {
    /** @var GeminiClient */
    private $ai;

    public function __construct(?GeminiClient $ai = null) {
        $this->ai = $ai ?? new GeminiClient();
    }

    /**
     * توليد نص منشور + هاشتاجات مقترحة بالذكاء الاصطناعي.
     * @return array ['success'=>bool,'content'=>string,'hashtags'=>array,'error'=>?string]
     */
    public function generateCaption(string $topic, string $platform, string $language = 'ar'): array {
        $languageName = $language === 'ar' ? 'العربية' : 'English';
        $prompt = <<<PROMPT
اكتب منشور سوشيال ميديا قصير وجذاب لمنصة {$platform} عن: "{$topic}".
اللغة: {$languageName}. الأسلوب: تسويقي، طبيعي، بدون مبالغة.
رجّع الرد بصيغة JSON فقط بالشكل: {"content": "نص المنشور", "hashtags": ["tag1","tag2","tag3"]}
PROMPT;

        $response = $this->ai->generateContent($prompt, ['maxOutputTokens' => 4096, 'responseMimeType' => 'application/json']);

        if (!($response['success'] ?? false)) {
            return ['success' => false, 'error' => $response['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي'];
        }

        $raw = trim((string) ($response['data'] ?? ''));
        // fallback احتياطي لو رجّع الرد ملفوف بـ ```json رغم responseMimeType
        $raw = preg_replace('/^```(json)?|```$/m', '', $raw);
        $parsed = json_decode(trim($raw), true);

        if (!is_array($parsed) || empty($parsed['content'])) {
            return ['success' => false, 'error' => 'تعذّر تحليل رد الذكاء الاصطناعي'];
        }

        return [
            'success' => true,
            'content' => (string) $parsed['content'],
            'hashtags' => $parsed['hashtags'] ?? [],
        ];
    }

    /**
     * إنشاء منشور + أهداف النشر لكل منصة مطلوبة (مباشر أو مجدول).
     * @param int   $userId
     * @param array $data ['content','website_id','media_item_id','hashtags']
     * @param array $targets كل عنصر: ['platform_connection_id'=>int,'scheduled_at'=>?string]
     */
    public function createPost(int $userId, array $data, array $targets): SocialPost {
        $post = new SocialPost([
            'user_id' => $userId,
            'website_id' => $data['website_id'] ?? null,
            'content' => $data['content'],
            'media_item_id' => $data['media_item_id'] ?? null,
            'hashtags' => isset($data['hashtags']) ? json_encode($data['hashtags'], JSON_UNESCAPED_UNICODE) : null,
            'status' => empty($targets) ? 'draft' : 'scheduled',
        ]);
        $post->save();
        $postId = (int) $post->getAttribute('id');

        $queue = Container::getInstance()->make(QueueManager::class);

        foreach ($targets as $target) {
            $scheduledAt = $target['scheduled_at'] ?? null;

            $postTarget = new SocialPostTarget([
                'social_post_id' => $postId,
                'platform_connection_id' => $target['platform_connection_id'],
                'scheduled_at' => $scheduledAt,
                'status' => 'scheduled',
            ]);
            $postTarget->save();

            // ننشر فورًا لو مفيش موعد مجدول، أو نأجّل التنفيذ الفعلي حتى الموعد
            $delaySeconds = 0;
            if ($scheduledAt) {
                $delaySeconds = max(0, strtotime($scheduledAt) - time());
            }

            $queue->push(PublishSocialPostJob::class, [
                'social_post_target_id' => (int) $postTarget->getAttribute('id'),
            ], 'social', $delaySeconds);
        }

        ActivityLog::record('social', 'post.created', [
            'user_id' => $userId,
            'subject_type' => 'social_posts',
            'subject_id' => $postId,
            'meta' => ['targets' => count($targets)],
        ]);

        return $post;
    }
}
