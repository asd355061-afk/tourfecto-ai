<?php

/**
 * Tourfecto - Generate Video Job (Creative Studio)
 * توليد فيديو قصير حقيقي بـ Veo 3.1 Fast (عبر Gemini API، نفس
 * GEMINI_API_KEY الموجود فعلاً - مفيش مفتاح جديد). التوليد عملية طويلة
 * غير متزامنة (بين دقيقة و~6 دقايق)، ومهلة تنفيذ PHP على استضافة
 * مشتركة عادةً قصيرة (30-60 ثانية) - فبدل ما نعمل sleep() جوه نفس
 * التنفيذ زي أمثلة Google الرسمية، بنعيد جدولة نفس المهمة كـ job جديد
 * في الطابور كل ~20 ثانية لحد ما العملية تخلص. نفس فكرة إعادة الجدولة
 * المستخدمة فعلاً في PublishSocialPostJob بس هنا بتتكرر أكتر من مرة.
 * @version 1.0.0
 */
class GenerateVideoJob implements QueueJobInterface
{
    /** أقصى عدد محاولات فحص (poll) قبل ما نستسلم - 40 × ~20 ثانية = ~13 دقيقة (أكتر من أقصى مهلة Veo موثّقة وهي 6 دقايق) */
    private const MAX_POLL_ATTEMPTS = 40;
    private const POLL_DELAY_SECONDS = 20;

    public function handle(array $payload): void
    {
        $itemId = (int) ($payload['media_item_id'] ?? 0);
        $item = (new MediaItem())->find($itemId);

        if (!$item) {
            throw new Exception("MediaItem #{$itemId} غير موجود");
        }

        $veo = new VeoClient();
        $operationName = (string) ($item->getAttribute('provider_ref') ?: '');

        try {
            if ($operationName === '') {
                $this->startOperation($item, $veo, (string) ($payload['final_prompt'] ?? $item->getAttribute('prompt')));
                return;
            }

            $this->pollOperation($item, $veo, $operationName);

        } catch (Throwable $e) {
            $item->setAttribute('status', 'failed');
            $item->setAttribute('error_message', $e->getMessage());
            $item->save();
            Logger::error('Video Generation Exception', ['media_item_id' => $itemId, 'message' => $e->getMessage()]);
        }
    }

    private function startOperation(MediaItem $item, VeoClient $veo, string $prompt): void
    {
        $aspectRatio = (string) ($item->getAttribute('aspect_ratio') ?: '16:9');
        $duration = (int) ($item->getAttribute('duration_seconds') ?: 8);

        $result = $veo->startGeneration($prompt, $aspectRatio, $duration);

        if (!$result['success']) {
            $item->setAttribute('status', 'failed');
            $item->setAttribute('error_message', $result['error'] ?? 'تعذر بدء توليد الفيديو');
            $item->save();
            Logger::error('Video Generation Start Failed', ['media_item_id' => $item->getAttribute('id'), 'error' => $result['error'] ?? null]);
            return;
        }

        $item->setAttribute('provider_ref', $result['operation_name']);
        $item->setAttribute('status', 'generating');
        $item->save();

        $this->requeue((int) $item->getAttribute('id'));
    }

    private function pollOperation(MediaItem $item, VeoClient $veo, string $operationName): void
    {
        $itemId = (int) $item->getAttribute('id');
        $attempts = (int) $item->getAttribute('poll_attempts');

        if ($attempts >= self::MAX_POLL_ATTEMPTS) {
            $item->setAttribute('status', 'failed');
            $item->setAttribute('error_message', 'انتهت مهلة انتظار توليد الفيديو - حاول مرة أخرى');
            $item->save();
            return;
        }

        $status = $veo->checkOperation($operationName);

        if (!$status['done']) {
            $item->setAttribute('poll_attempts', $attempts + 1);
            $item->save();
            $this->requeue($itemId);
            return;
        }

        if (!$status['success']) {
            $item->setAttribute('status', 'failed');
            $item->setAttribute('error_message', $status['error'] ?? 'فشل توليد الفيديو');
            $item->save();
            Logger::error('Video Generation Failed', ['media_item_id' => $itemId, 'error' => $status['error'] ?? null]);
            return;
        }

        $download = $veo->downloadVideo((string) $status['video_uri']);
        if (!$download['success']) {
            $item->setAttribute('status', 'failed');
            $item->setAttribute('error_message', $download['error'] ?? 'تعذر تحميل ملف الفيديو');
            $item->save();
            return;
        }

        $publicDir = ROOT_PATH . '/public_html/uploads/media';
        if (!is_dir($publicDir)) {
            @mkdir($publicDir, 0755, true);
        }
        if (!is_dir($publicDir) || !is_writable($publicDir)) {
            throw new Exception('تعذر الوصول لمجلد حفظ الفيديوهات على السيرفر (صلاحيات الكتابة)');
        }

        $filename = 'video_' . $itemId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.mp4';
        $fullPath = $publicDir . '/' . $filename;

        if (file_put_contents($fullPath, $download['data']) === false) {
            throw new Exception('تعذر حفظ ملف الفيديو على السيرفر');
        }

        $item->setAttribute('file_path', '/uploads/media/' . $filename);
        $item->setAttribute('status', 'completed');
        $item->setAttribute('error_message', null);
        $item->setAttribute('provider_ref', null);
        $item->save();

        if (class_exists('Notification')) {
            Notification::notify(
                (int) $item->getAttribute('user_id'),
                'media_generated',
                'تم توليد الفيديو بنجاح',
                'فيديوك القصير جاهز في Creative Studio.',
                '/creative-studio'
            );
        }

        Logger::info('Video Generated', ['media_item_id' => $itemId, 'file_path' => $item->getAttribute('file_path')]);
    }

    private function requeue(int $itemId): void
    {
        $queue = Container::getInstance()->make(QueueManager::class);
        $queue->push(GenerateVideoJob::class, ['media_item_id' => $itemId], 'media', self::POLL_DELAY_SECONDS);
    }
}
