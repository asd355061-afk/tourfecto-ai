<?php

/**
 * Tourfecto - Google Business Profile Content Service
 * توليد محتوى منشورات GBP بالذكاء الاصطناعي + جدولة نشرها. النشر
 * الفعلي يعتمد على GoogleBusinessAPI.php الموجود بالفعل في المشروع
 * (نفس اتصال OAuth المستخدم لاستيراد المراجعات) - لا عميل API منفصل.
 * @version 1.0.0
 */
class GbpContentService
{
    /** @var GeminiClient */
    private $ai;

    public function __construct(?GeminiClient $ai = null)
    {
        $this->ai = $ai ?? new GeminiClient();
    }

    public function generate(int $userId, int $websiteId, string $type, string $prompt): GbpContent
    {
        $typeLabels = ['update' => 'تحديث عام', 'offer' => 'عرض خاص', 'event' => 'فعالية', 'product' => 'منتج/خدمة'];
        $label = $typeLabels[$type] ?? 'تحديث عام';

        $aiPrompt = "اكتب منشور {$label} قصير لصفحة Google Business Profile عن: \"{$prompt}\". "
            . "بحد أقصى 1500 حرف، أسلوب مباشر وجذاب باللغة العربية.";

        $response = $this->ai->generateContent($aiPrompt, ['maxOutputTokens' => 512]);

        $content = new GbpContent([
            'user_id' => $userId,
            'website_id' => $websiteId,
            'type' => $type,
            'prompt' => $prompt,
            'status' => ($response['success'] ?? false) ? 'ready' : 'failed',
        ]);

        if ($response['success'] ?? false) {
            $content->setAttribute('generated_text', trim((string) ($response['data'] ?? '')));
        }

        $content->save();

        if (!($response['success'] ?? false)) {
            if (class_exists('GbpAuditLogger')) {
                GbpAuditLogger::log('post_create', $websiteId, $userId, 'failed', ['type' => $type]);
            }
            throw new Exception($response['error'] ?? 'فشل توليد المحتوى');
        }

        if (class_exists('GbpAuditLogger')) {
            GbpAuditLogger::log('post_create', $websiteId, $userId, 'success', ['type' => $type, 'content_id' => (int) $content->getAttribute('id')]);
        }

        return $content;
    }

    public function schedule(int $contentId, int $platformConnectionId, string $scheduledAt): GbpScheduledPost
    {
        $connection = (new PlatformConnection())->find($platformConnectionId);
        if (!$connection || $connection->getAttribute('platform') !== 'google_business') {
            throw new Exception('اتصال Google Business غير موجود أو غير صحيح');
        }

        $scheduled = new GbpScheduledPost([
            'gbp_content_id' => $contentId,
            'platform_connection_id' => $platformConnectionId,
            'scheduled_at' => $scheduledAt,
            'status' => 'pending',
        ]);
        $scheduled->save();

        $delay = max(0, strtotime($scheduledAt) - time());
        $jobId = Container::getInstance()->make(QueueManager::class)->push(
            PublishGbpPostJob::class,
            ['gbp_scheduled_post_id' => (int) $scheduled->getAttribute('id')],
            'gbp',
            $delay
        );

        // Round 6 (2026-08-11): نخزّن معرف المهمة في الطابور عشان نقدر
        // نلغيها بأمان لو المستخدم عمل Cancel قبل وقت النشر (شوف
        // cancelScheduled() تحت). لو push() فشل (jobs table مش موجود)،
        // بنسيب الحقل null - المنشور هيفضل "pending" من غير جدولة فعلية،
        // مش هنكسر أي حاجة.
        if (is_int($jobId)) {
            $scheduled->setAttribute('queue_job_id', $jobId);
            $scheduled->save();
        }

        if (class_exists('GbpAuditLogger')) {
            $content = (new GbpContent())->find($contentId);
            GbpAuditLogger::log(
                'post_update',
                $content ? (int) $content->getAttribute('website_id') : null,
                $content ? (int) $content->getAttribute('user_id') : null,
                'success',
                ['action_detail' => 'scheduled', 'content_id' => $contentId, 'scheduled_at' => $scheduledAt]
            );
        }

        return $scheduled;
    }

    /**
     * تعديل نص منشور GBP قبل ما يتنشر. مسموح بس لو لسه مفيهوش جدولة
     * "pending" فعلية (يعني عدّل النص وأنت لسه في مرحلة المسودة/التوليد)
     * - لو المنشور اتجدول بالفعل، لازم تلغي الجدولة الأول (cancelScheduled)
     * قبل ما تقدر تعدّل، عشان منخليش نص يتغيّر تحت مهمة شغالة في الطابور.
     */
    public function editContent(int $contentId, int $userId, string $newText): GbpContent
    {
        $content = (new GbpContent())->find($contentId);
        if (!$content || (int) $content->getAttribute('user_id') !== $userId) {
            throw new Exception('المنشور غير موجود');
        }

        $activeSchedule = $this->findActiveSchedule($contentId);
        if ($activeSchedule) {
            throw new Exception('المنشور ده مجدول بالفعل - لازم تلغي الجدولة الأول قبل التعديل');
        }

        $trimmed = trim($newText);
        if ($trimmed === '') {
            throw new Exception('نص المنشور لا يمكن أن يكون فارغًا');
        }
        if (mb_strlen($trimmed) > 1500) {
            throw new Exception('نص المنشور يجب ألا يتجاوز 1500 حرف (حد Google الرسمي لمنشورات Local Posts)');
        }

        $content->setAttribute('generated_text', $trimmed);
        $content->save();

        if (class_exists('GbpAuditLogger')) {
            GbpAuditLogger::log('post_update', (int) $content->getAttribute('website_id'), $userId, 'success', ['action_detail' => 'text_edited', 'content_id' => $contentId]);
        }

        return $content;
    }

    /**
     * إلغاء منشور مجدول قبل ما ينشر فعليًا. بيشيل صف الـ Job من طابور
     * الانتظار (لو لسه "pending" ومجاش دوره) + يحدّث حالة الجدولة لـ
     * 'cancelled'. لو المنشور نشر بالفعل أو قيد التنفيذ دلوقتي، بيرفض
     * الإلغاء بدل ما يدّعي نجاح وهمي.
     */
    public function cancelScheduled(int $scheduledPostId, int $userId): GbpScheduledPost
    {
        $scheduled = (new GbpScheduledPost())->find($scheduledPostId);
        if (!$scheduled) {
            throw new Exception('الجدولة غير موجودة');
        }

        $content = (new GbpContent())->find((int) $scheduled->getAttribute('gbp_content_id'));
        if (!$content || (int) $content->getAttribute('user_id') !== $userId) {
            throw new Exception('الجدولة غير موجودة');
        }

        $status = $scheduled->getAttribute('status');
        if ($status === 'published') {
            throw new Exception('المنشور اتنشر بالفعل على Google - مينفعش يتلغى');
        }
        if ($status === 'processing') {
            throw new Exception('المنشور قيد النشر دلوقتي - استنى لحد ما تخلص العملية');
        }
        if ($status === 'cancelled') {
            throw new Exception('الجدولة دي ملغاة بالفعل');
        }

        $jobId = $scheduled->getAttribute('queue_job_id');
        if ($jobId) {
            try {
                // بنمسح صف الـ Job بس لو لسه 'pending' (يعني مجاش دوره في
                // الطابور لسه) - لو اتحرّك لـ processing جوه سباق (race)
                // نادر، منمسحوش عشان منسيبش نشر ناقص، وهيرجع نفسه لـ
                // pending تلقائيًا لو عَلَق (STALE_LOCK_SECONDS في QueueManager).
                Database::getInstance()->query(
                    "DELETE FROM jobs WHERE id = ? AND status = 'pending'",
                    [$jobId]
                );
            } catch (Throwable $e) {
                Logger::error('GBP cancel scheduled: تعذر حذف صف الـ job', ['job_id' => $jobId, 'error' => $e->getMessage()]);
            }
        }

        $scheduled->setAttribute('status', 'cancelled');
        $scheduled->save();

        if (class_exists('GbpAuditLogger')) {
            GbpAuditLogger::log('post_update', (int) $content->getAttribute('website_id'), $userId, 'success', ['action_detail' => 'schedule_cancelled', 'scheduled_post_id' => $scheduledPostId]);
        }

        return $scheduled;
    }

    /** تحقق: فيه جدولة "pending" فعلية لسه شغالة لنفس المحتوى؟ */
    private function findActiveSchedule(int $contentId): ?GbpScheduledPost
    {
        $rows = (new GbpScheduledPost())->where(['gbp_content_id' => $contentId, 'status' => 'pending'], [], 1);
        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * حذف مسودة منشور (مش منشور على Google أبدًا ولا مجدول حاليًا).
     * لو اتنشر فعلاً على Google أو مجدول pending، بيرفض الحذف صراحة -
     * الحذف هنا محلي بس (سجل Tourfecto)؛ مفيش endpoint فعلي من Google
     * لحذف Local Post بعد نشره من غير Google Business Profile UI نفسه،
     * فمعملناش استدعاء وهمي هنا.
     */
    public function deleteContent(int $contentId, int $userId): void
    {
        $content = (new GbpContent())->find($contentId);
        if (!$content || (int) $content->getAttribute('user_id') !== $userId) {
            throw new Exception('المنشور غير موجود');
        }

        $schedules = (new GbpScheduledPost())->where(['gbp_content_id' => $contentId]);
        foreach ($schedules as $schedule) {
            $status = $schedule->getAttribute('status');
            if (in_array($status, ['pending', 'processing', 'published'], true)) {
                throw new Exception('المنشور ده مجدول أو منشور بالفعل - لازم تلغي الجدولة الأول قبل الحذف');
            }
        }

        // لازم نلقط website_id قبل delete() - delete() بتفضّي $content->attributes
        // بعد النجاح، فأي getAttribute() بعدها هيرجع null (باگ حقيقي كان
        // هيبوّظ الـ Audit Log لولا الالتقاط ده).
        $websiteId = (int) $content->getAttribute('website_id');

        $content->delete();

        if (class_exists('GbpAuditLogger')) {
            GbpAuditLogger::log('post_delete', $websiteId, $userId, 'success', ['content_id' => $contentId]);
        }
    }
}
