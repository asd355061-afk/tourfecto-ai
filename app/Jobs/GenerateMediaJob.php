<?php
/**
 * Tourfecto - Generate Media Job (Creative Studio)
 * @version 2.0.0
 *
 * تفعيل كامل: بيستخدم GeminiClient::generateImage() (موديل
 * gemini-2.5-flash-image / "Nano Banana") بنفس GEMINI_API_KEY الموجود
 * فعلاً في .env - مفيش أي مفتاح API جديد أو حساب خارجي مطلوب.
 */
class GenerateMediaJob implements QueueJobInterface {
    public function handle(array $payload): void {
        $itemId = (int) ($payload['media_item_id'] ?? 0);
        $item = (new MediaItem())->find($itemId);

        if (!$item) {
            throw new Exception("MediaItem #{$itemId} غير موجود");
        }

        $item->setAttribute('status', 'generating');
        $item->save();

        try {
            $gemini = new GeminiClient();
            $promptToUse = (string) ($payload['final_prompt'] ?? $item->getAttribute('prompt'));
            $aspectRatio = (string) ($item->getAttribute('aspect_ratio') ?: '1:1');
            $result = $gemini->generateImage($promptToUse, $aspectRatio);

            if (!$result['success']) {
                $item->setAttribute('status', 'failed');
                $item->setAttribute('error_message', $result['error'] ?? 'خطأ غير معروف من Gemini');
                $item->save();
                Logger::error('Media Generation Failed', ['media_item_id' => $itemId, 'error' => $result['error'] ?? null]);
                return;
            }

            $imageData = base64_decode($result['image_base64']);
            if ($imageData === false) {
                throw new Exception('تعذر فك تشفير بيانات الصورة (base64)');
            }

            $extension = strpos($result['mime_type'], 'jpeg') !== false ? 'jpg' : 'png';
            $publicDir = ROOT_PATH . '/public_html/uploads/media';
            if (!is_dir($publicDir)) {
                @mkdir($publicDir, 0755, true);
            }
            if (!is_dir($publicDir) || !is_writable($publicDir)) {
                throw new Exception('تعذر الوصول لمجلد حفظ الصور على السيرفر (صلاحيات الكتابة)');
            }

            $filename = 'media_' . $itemId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
            $fullPath = $publicDir . '/' . $filename;

            if (file_put_contents($fullPath, $imageData) === false) {
                throw new Exception('تعذر حفظ ملف الصورة على السيرفر');
            }

            $dimensions = @getimagesize($fullPath);

            $item->setAttribute('file_path', '/uploads/media/' . $filename);
            $item->setAttribute('thumbnail_path', '/uploads/media/' . $filename); // نفس الصورة كثمبنيل (الحجم أصلاً 1024x1024 صغير)
            $item->setAttribute('width', $dimensions[0] ?? 1024);
            $item->setAttribute('height', $dimensions[1] ?? 1024);
            $item->setAttribute('status', 'completed');
            $item->setAttribute('error_message', null);
            $item->save();

            if (class_exists('Notification')) {
                Notification::notify(
                    (int) $item->getAttribute('user_id'),
                    'media_generated',
                    'تم توليد الصورة بنجاح',
                    'صورتك جاهزة في Creative Studio.',
                    '/creative-studio'
                );
            }

            Logger::info('Media Generated', ['media_item_id' => $itemId, 'file_path' => $item->getAttribute('file_path')]);

        } catch (Throwable $e) {
            $item->setAttribute('status', 'failed');
            $item->setAttribute('error_message', $e->getMessage());
            $item->save();

            Logger::error('Media Generation Exception', ['media_item_id' => $itemId, 'message' => $e->getMessage()]);
        }
    }
}
