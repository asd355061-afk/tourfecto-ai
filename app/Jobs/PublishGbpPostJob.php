<?php

/**
 * Tourfecto - Publish Google Business Profile Post Job
 * @version 2.0.0
 *
 * تم التفعيل الكامل: GoogleBusinessAPI::publishPost() بقت موجودة فعليًا
 * (Local Posts API الرسمي)، فالـ Job ده بقى بينفّذ النشر الحقيقي بدل ما
 * يقف عند حالة "processing" بس.
 */
class PublishGbpPostJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $id = (int) ($payload['gbp_scheduled_post_id'] ?? 0);
        $scheduled = (new GbpScheduledPost())->find($id);

        if (!$scheduled) {
            throw new Exception("GbpScheduledPost #{$id} غير موجود");
        }

        $content = (new GbpContent())->find((int) $scheduled->getAttribute('gbp_content_id'));
        $connection = (new PlatformConnection())->find((int) $scheduled->getAttribute('platform_connection_id'));

        if (!$content || !$connection) {
            $scheduled->setAttribute('status', 'failed');
            $scheduled->setAttribute('error_message', 'المحتوى أو الاتصال غير موجود');
            $scheduled->save();
            return;
        }

        // Round 7 (2026-08-14 - Production Finalization): حماية Idempotency
        // حقيقية - لو الحالة بالفعل 'published' أو 'processing'، منعملش
        // حاجة تاني. ده مهم لأن QueueManager عنده STALE_LOCK_SECONDS=300:
        // لو النشر على Google استغرق أكتر من 5 دقايق (شبكة بطيئة مثلاً)،
        // ممكن الـ job يترجع 'pending' تاني ويتلقط مرة تانية وهو لسه شغال
        // فعليًا - من غير الحماية دي كان ممكن يتنشر نفس البوست مرتين على
        // حساب العميل الحقيقي على Google.
        $currentStatus = $scheduled->getAttribute('status');
        if ($currentStatus === 'published') {
            Logger::info('GBP Post Publish: already published, skipping (idempotency guard)', ['scheduled_post_id' => $id]);
            return;
        }
        if ($currentStatus === 'processing') {
            Logger::info('GBP Post Publish: already processing (possible concurrent run), skipping', ['scheduled_post_id' => $id]);
            return;
        }

        $scheduled->setAttribute('status', 'processing');
        $scheduled->save();

        try {
            $syncService = new GoogleReviewSyncService();
            $accessToken = $syncService->getValidAccessToken($connection);

            $api = new GoogleBusinessAPI(
                $accessToken,
                $connection->getAttribute('external_account_id'),
                $connection->getAttribute('external_location_id')
            );

            $website = (new Website())->find((int) $content->getAttribute('website_id'));
            $language = $website ? (string) $website->getAttribute('target_language') : 'ar';
            $ctaUrl = $website ? (string) $website->getAttribute('main_url') : null;

            $result = $api->publishPost(
                (string) $content->getAttribute('generated_text'),
                $language ?: 'ar',
                $ctaUrl ?: null
            );

            if (!$result['success']) {
                $scheduled->setAttribute('status', 'failed');
                $scheduled->setAttribute('error_message', $result['error'] ?? 'خطأ غير معروف من Google');
                $scheduled->setAttribute('attempts', (int) $scheduled->getAttribute('attempts') + 1);
                $scheduled->save();

                Logger::error('GBP Post Publish Failed', [
                    'scheduled_post_id' => $id,
                    'error' => $result['error'] ?? null,
                ]);
                if (class_exists('GbpAuditLogger')) {
                    GbpAuditLogger::log('post_publish', (int) $content->getAttribute('website_id'), (int) $content->getAttribute('user_id'), 'failed', ['scheduled_post_id' => $id, 'error_code' => $result['error_code'] ?? null]);
                }
                return;
            }

            $scheduled->setAttribute('status', 'published');
            $scheduled->setAttribute('google_post_id', $result['post_id'] ?? null);
            $scheduled->setAttribute('published_at', date('Y-m-d H:i:s'));
            $scheduled->setAttribute('error_message', null);
            $scheduled->save();

            $content->setAttribute('status', 'published');
            $content->save();

            if (class_exists('Notification')) {
                Notification::notify(
                    (int) $content->getAttribute('user_id'),
                    'gbp_post_published',
                    'تم نشر منشور Google Business',
                    'منشورك اتنشر بنجاح على Google Business Profile.',
                    '/gbp-content'
                );
            }

            Logger::info('GBP Post Published', ['scheduled_post_id' => $id, 'google_post_id' => $result['post_id'] ?? null]);

            // GBP Module Upgrade (2026-08-09): PostPublished event مطلوب صراحة بالسبيك ومكانش بيتبعت
            if (function_exists('event')) {
                event('PostPublished', [
                    'website_id' => (int) $content->getAttribute('website_id'),
                    'user_id' => (int) $content->getAttribute('user_id'),
                    'scheduled_post_id' => $id,
                    'google_post_id' => $result['post_id'] ?? null,
                ]);
            }
            if (class_exists('GbpAuditLogger')) {
                GbpAuditLogger::log('post_publish', (int) $content->getAttribute('website_id'), (int) $content->getAttribute('user_id'), 'success', ['scheduled_post_id' => $id, 'google_post_id' => $result['post_id'] ?? null]);
            }

        } catch (Throwable $e) {
            $scheduled->setAttribute('status', 'failed');
            $scheduled->setAttribute('error_message', $e->getMessage());
            $scheduled->setAttribute('attempts', (int) $scheduled->getAttribute('attempts') + 1);
            $scheduled->save();

            Logger::error('GBP Post Publish Exception', [
                'scheduled_post_id' => $id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
