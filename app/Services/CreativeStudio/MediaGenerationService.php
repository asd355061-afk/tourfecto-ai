<?php

/**
 * Tourfecto - Media Generation Service (Creative Studio)
 * توليد صور تسويقية بالذكاء الاصطناعي (GeminiClient::generateImage -
 * gemini-2.5-flash-image / "Nano Banana") + فيديوهات قصيرة احترافية
 * (VeoClient - Veo 3.1 Fast)، بنفس GEMINI_API_KEY المستخدم فعلاً في
 * المشروع لكل خدمات الذكاء الاصطناعي - مفيش أي مفتاح/حساب جديد مطلوب.
 * @version 2.0.0
 */
class MediaGenerationService
{
    private const SUPPORTED_TYPES = [
        'social_image', 'marketing_image', 'instagram_post', 'facebook_cover',
        'youtube_thumbnail', 'story', 'reels_cover', 'short_video',
    ];

    /** نوع الوسائط -> نسبة الأبعاد الاحترافية الصحيحة لكل منصة (صور فقط) */
    private const IMAGE_ASPECT_RATIOS = [
        'social_image' => '1:1',
        'marketing_image' => '4:3',
        'instagram_post' => '1:1',
        'facebook_cover' => '16:9',
        'youtube_thumbnail' => '16:9',
        'story' => '9:16',
        'reels_cover' => '9:16',
    ];

    /** إضافات أسلوب احترافية بتتحط على وصف المستخدم قبل التوليد */
    private const STYLE_PRESETS = [
        'photo' => 'professional commercial photography, sharp focus, natural balanced lighting, high detail, 4k quality',
        'cinematic' => 'cinematic lighting, dramatic composition, shallow depth of field, high-end commercial film look',
        'product' => 'clean studio background, professional product photography, soft shadows, advertising catalog style',
        'illustration' => 'modern flat illustration style, clean vector shapes, vibrant harmonious colors',
        'minimal' => 'minimalist design, plenty of negative space, elegant and simple composition',
    ];

    /** منصة الفيديو -> نسبة الأبعاد (فيديو بس بيدعم 16:9 أو 9:16) */
    private const VIDEO_ASPECT_RATIOS = [
        'tiktok' => '9:16',
        'instagram_reels' => '9:16',
        'youtube_shorts' => '9:16',
        'general_landscape' => '16:9',
    ];

    public function isSupportedType(string $type): bool
    {
        return in_array($type, self::SUPPORTED_TYPES, true);
    }

    public static function stylePresets(): array
    {
        return self::STYLE_PRESETS;
    }

    /**
     * توليد صورة تسويقية احترافية.
     * @param string $style مفتاح من STYLE_PRESETS (اختياري)
     */
    public function requestGeneration(int $userId, string $type, string $prompt, string $style = 'photo'): MediaItem
    {
        if (!in_array($type, self::SUPPORTED_TYPES, true) || $type === 'short_video') {
            throw new InvalidArgumentException("نوع وسائط غير مدعوم: {$type}");
        }

        $aspectRatio = self::IMAGE_ASPECT_RATIOS[$type] ?? '1:1';
        $finalPrompt = $this->buildImagePrompt($prompt, $style);

        $item = new MediaItem([
            'user_id' => $userId,
            'type' => $type,
            'prompt' => $prompt,
            'aspect_ratio' => $aspectRatio,
            'status' => 'generating',
        ]);
        $item->save();

        $queue = Container::getInstance()->make(QueueManager::class);
        $queue->push(GenerateMediaJob::class, [
            'media_item_id' => (int) $item->getAttribute('id'),
            'final_prompt' => $finalPrompt,
        ], 'media');

        ActivityLog::record('creative_studio', 'media.requested', [
            'user_id' => $userId, 'subject_type' => 'media_items', 'subject_id' => (int) $item->getAttribute('id'),
        ]);

        return $item;
    }

    /**
     * توليد فيديو قصير احترافي (Veo) - نفس فكرة requestGeneration لكن
     * بمهلة أطول (عملية غير متزامنة) عبر GenerateVideoJob.
     * @param string $platform tiktok | instagram_reels | youtube_shorts | general_landscape
     * @param int    $durationSeconds 4 | 6 | 8
     * @param string $style مفتاح من STYLE_PRESETS
     */
    public function requestVideoGeneration(int $userId, string $prompt, string $platform = 'instagram_reels', int $durationSeconds = 8, string $style = 'cinematic'): MediaItem
    {
        $aspectRatio = self::VIDEO_ASPECT_RATIOS[$platform] ?? '9:16';
        $durationSeconds = in_array($durationSeconds, [4, 6, 8], true) ? $durationSeconds : 8;
        $finalPrompt = $this->buildVideoPrompt($prompt, $style);

        $item = new MediaItem([
            'user_id' => $userId,
            'type' => 'short_video',
            'prompt' => $prompt,
            'aspect_ratio' => $aspectRatio,
            'duration_seconds' => $durationSeconds,
            'status' => 'generating',
        ]);
        $item->save();

        $queue = Container::getInstance()->make(QueueManager::class);
        $queue->push(GenerateVideoJob::class, [
            'media_item_id' => (int) $item->getAttribute('id'),
            'final_prompt' => $finalPrompt,
        ], 'media');

        ActivityLog::record('creative_studio', 'video.requested', [
            'user_id' => $userId, 'subject_type' => 'media_items', 'subject_id' => (int) $item->getAttribute('id'),
        ]);

        return $item;
    }

    private function buildImagePrompt(string $prompt, string $style): string
    {
        $styleDescriptor = self::STYLE_PRESETS[$style] ?? self::STYLE_PRESETS['photo'];
        return trim($prompt) . '. Style: ' . $styleDescriptor . '.';
    }

    private function buildVideoPrompt(string $prompt, string $style): string
    {
        $styleDescriptor = self::STYLE_PRESETS[$style] ?? self::STYLE_PRESETS['cinematic'];
        return trim($prompt) . '. Visual style: ' . $styleDescriptor . '. Smooth camera motion, professional social-media advertising video.';
    }
}
