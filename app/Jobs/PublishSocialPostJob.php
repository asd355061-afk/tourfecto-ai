<?php

/**
 * Tourfecto - Publish Social Post Job
 * تنفيذ فعلي لنشر هدف واحد (social_post_targets) على منصته عبر
 * platform_connections. يُنفَّذ بواسطة cron/process_queue.php الموجود
 * فعلاً في المشروع - لا حاجة لأي worker جديد.
 * @version 3.0.0
 *
 * دعم الفيديو (Creative Studio -> Veo): فيسبوك بينشر الفيديو مباشرة
 * برابط عام (/videos + file_url). انستجرام بيحتاج مرحلة معالجة غير
 * متزامنة (container REELS) قبل النشر الفعلي - فبنستخدم نفس فكرة
 * "ابدأ + أعد الجدولة للفحص" المستخدمة في GenerateVideoJob، عن طريق
 * provider_ref/poll_attempts على social_post_targets.
 */
class PublishSocialPostJob implements QueueJobInterface
{
    private const MAX_POLL_ATTEMPTS = 30;
    private const POLL_DELAY_SECONDS = 15;

    public function handle(array $payload): void
    {
        $targetId = (int) ($payload['social_post_target_id'] ?? 0);
        if (!$targetId) {
            throw new Exception('social_post_target_id مفقود في payload');
        }

        $target = (new SocialPostTarget())->find($targetId);
        if (!$target) {
            throw new Exception("SocialPostTarget #{$targetId} غير موجود");
        }

        $post = (new SocialPost())->find((int) $target->getAttribute('social_post_id'));
        $connection = (new PlatformConnection())->find((int) $target->getAttribute('platform_connection_id'));

        if (!$post || !$connection) {
            $target->setAttribute('status', 'failed');
            $target->setAttribute('last_error', 'المنشور أو اتصال المنصة غير موجود');
            $target->save();
            return;
        }

        if ($connection->getAttribute('status') !== 'connected') {
            $target->setAttribute('status', 'failed');
            $target->setAttribute('last_error', 'اتصال المنصة غير فعّال (status != connected) - يحتاج إعادة ربط OAuth');
            $target->save();
            return;
        }

        $platform = (string) $connection->getAttribute('platform');

        if (!in_array($platform, ['facebook', 'instagram'], true)) {
            $target->setAttribute('status', 'failed');
            $target->setAttribute('last_error', "النشر الفعلي على منصة {$platform} لسه مش متاح (بس فيسبوك وانستجرام حاليًا).");
            $target->save();
            return;
        }

        try {
            $encryption = new Encryption();
            $pageAccessToken = $encryption->decrypt((string) $connection->getAttribute('access_token'));
            $api = new MetaSocialAPI($pageAccessToken);
            $pageId = (string) $connection->getAttribute('external_location_id');

            $message = (string) $post->getAttribute('content');
            $hashtags = (string) $post->getAttribute('hashtags');
            if ($hashtags) {
                $message .= "\n\n" . $hashtags;
            }

            $media = $this->resolveMedia((int) $post->getAttribute('media_item_id'));
            $mediaUrl = $media ? $this->toPublicUrl((string) $media->getAttribute('file_path')) : null;
            $isVideo = $media && $media->getAttribute('type') === 'short_video';

            if ($platform === 'instagram' && !$mediaUrl) {
                $this->fail($target, 'انستجرام محتاج صورة أو فيديو إجباريًا - المنشور ده مفيهوش وسائط. ولّد صورة/فيديو من Creative Studio الأول.');
                return;
            }

            if ($platform === 'facebook') {
                $result = $isVideo
                    ? $api->publishVideoToFacebookPage($pageId, $pageAccessToken, $message, $mediaUrl)
                    : $api->publishToFacebookPage($pageId, $pageAccessToken, $message, $mediaUrl);

                $this->finishOrFail($target, $post, $platform, $result);
                return;
            }

            // انستجرام
            if ($isVideo) {
                $this->handleInstagramVideo($target, $post, $api, $pageId, $pageAccessToken, $mediaUrl, $message);
            } else {
                $result = $api->publishToInstagram($pageId, $pageAccessToken, $mediaUrl, $message);
                $this->finishOrFail($target, $post, $platform, $result);
            }

        } catch (Throwable $e) {
            $target->setAttribute('status', 'failed');
            $target->setAttribute('last_error', $e->getMessage());
            $target->save();

            Logger::error('Social Post Publish Exception', [
                'target_id' => $targetId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * تدفق نشر فيديو انستجرام (Reels) غير المتزامن: إنشاء container أول
     * مرة، وبعدين فحص حالته كل ~15 ثانية لحد ما يخلص معالجة، وبعدين
     * النشر الفعلي - بإعادة جدولة نفس المهمة زي GenerateVideoJob.
     */
    private function handleInstagramVideo(SocialPostTarget $target, SocialPost $post, MetaSocialAPI $api, string $igUserId, string $pageAccessToken, string $videoUrl, string $caption): void
    {
        $containerId = (string) ($target->getAttribute('provider_ref') ?: '');

        if ($containerId === '') {
            $result = $api->createInstagramVideoContainer($igUserId, $pageAccessToken, $videoUrl, $caption);
            if (!$result['success']) {
                $this->fail($target, $result['error'] ?? 'تعذر إنشاء container فيديو انستجرام');
                return;
            }

            $target->setAttribute('provider_ref', $result['container_id']);
            $target->setAttribute('status', 'publishing');
            $target->save();

            $this->requeue((int) $target->getAttribute('id'));
            return;
        }

        $attempts = (int) $target->getAttribute('poll_attempts');
        if ($attempts >= self::MAX_POLL_ATTEMPTS) {
            $this->fail($target, 'انتهت مهلة معالجة فيديو انستجرام - حاول مرة أخرى');
            return;
        }

        $status = $api->checkInstagramContainerStatus($containerId, $pageAccessToken);
        if (!$status['success']) {
            $this->fail($target, $status['error'] ?? 'تعذر فحص حالة معالجة الفيديو');
            return;
        }

        if ($status['status'] === 'ERROR' || $status['status'] === 'EXPIRED') {
            $this->fail($target, 'فشلت معالجة الفيديو في انستجرام (status: ' . $status['status'] . ')');
            return;
        }

        if ($status['status'] !== 'FINISHED') {
            $target->setAttribute('poll_attempts', $attempts + 1);
            $target->save();
            $this->requeue((int) $target->getAttribute('id'));
            return;
        }

        $publishResult = $api->publishInstagramContainer($igUserId, $pageAccessToken, $containerId);
        $this->finishOrFail($target, $post, 'instagram', ['success' => $publishResult['success'], 'post_id' => $publishResult['post_id'] ?? null, 'error' => $publishResult['error'] ?? null]);
    }

    private function finishOrFail(SocialPostTarget $target, SocialPost $post, string $platform, array $result): void
    {
        $targetId = (int) $target->getAttribute('id');

        if (!$result['success']) {
            $this->fail($target, $result['error'] ?? 'خطأ غير معروف من Meta');
            Logger::error('Social Post Publish Failed', ['target_id' => $targetId, 'platform' => $platform, 'error' => $result['error'] ?? null]);
            return;
        }

        $target->setAttribute('status', 'published');
        $target->setAttribute('external_post_id', $result['post_id'] ?? null);
        $target->setAttribute('published_at', date('Y-m-d H:i:s'));
        $target->setAttribute('last_error', null);
        $target->setAttribute('provider_ref', null);
        $target->save();

        if (class_exists('Notification')) {
            Notification::notify(
                (int) $post->getAttribute('user_id'),
                'social_post_published',
                'تم نشر منشورك',
                "اتنشر منشورك على " . ($platform === 'facebook' ? 'فيسبوك' : 'انستجرام') . " بنجاح.",
                '/social'
            );
        }

        Logger::info('Social Post Published', ['target_id' => $targetId, 'platform' => $platform, 'post_id' => $result['post_id'] ?? null]);
    }

    private function fail(SocialPostTarget $target, string $message): void
    {
        $target->setAttribute('status', 'failed');
        $target->setAttribute('last_error', $message);
        $target->save();
    }

    private function requeue(int $targetId): void
    {
        $queue = Container::getInstance()->make(QueueManager::class);
        $queue->push(PublishSocialPostJob::class, ['social_post_target_id' => $targetId], 'social', self::POLL_DELAY_SECONDS);
    }

    private function resolveMedia(int $mediaItemId): ?MediaItem
    {
        if (!$mediaItemId) {
            return null;
        }
        $media = (new MediaItem())->find($mediaItemId);
        if (!$media || $media->getAttribute('status') !== 'completed' || !$media->getAttribute('file_path')) {
            return null;
        }
        return $media;
    }

    /**
     * فيسبوك وانستجرام بيطلبوا رابط عام (يقدروا يجيبوه هم بنفسهم من
     * الإنترنت)، مش مسار ملف محلي على السيرفر.
     */
    private function toPublicUrl(string $filePath): ?string
    {
        if (!$filePath) {
            return null;
        }
        if (strpos($filePath, 'uploads/') === 0 || strpos($filePath, '/uploads/') === 0) {
            $appUrl = rtrim(defined('APP_URL') ? APP_URL : '', '/');
            return $appUrl . '/' . ltrim($filePath, '/');
        }
        return null;
    }
}
