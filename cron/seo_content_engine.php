<?php

/**
 * Tourfecto - SEO Content Engine Cron (Phase 24)
 * @version 1.0.0
 *
 * محرك محتوى SEO تلقائي بحلقة مغلقة يشتغل من الـ Cron من غير تدخل يدوي:
 *   queued -> توليد (Job خلفي) -> indexed (IndexNow) -> A/B title -> published
 *                    ^_________________________|
 *   (عند اكتمال تجربة A/B، يتم تطبيق العنوان الفائز تلقائيًا)
 *
 * إعداد Cron Job في cPanel (Hostinger) - كل 10-15 دقيقة:
 *   php /home/USERNAME/domains/YOURSITE.com/cron/seo_content_engine.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/seo_content_engine.log 2>&1
 *
 * التوليد بيتسند لطابور المهام (cron/process_queue.php بينفّذه)، عشان
 * أي Cron واحد ميستناش عشرات المقالات على التوالي. الفهرسة والتجارب
 * والتطبيق بيتموا هنا بشكل متزامن ومحدود (20 عنصر لكل خطوة).
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    $db = Database::getInstance();
    $service = new SeoContentService($db);

    // ---------- 1) توليد: جدولة حملات ليها عناصر queued ----------
    $enqueued = 0;
    $pendingCampaigns = [];
    foreach ($service->pendingGenerationItems(20) as $item) {
        $pendingCampaigns[(int) $item['campaign_id']] = true;
    }
    if (!empty($pendingCampaigns)) {
        $queue = new QueueManager($db);
        foreach (array_keys($pendingCampaigns) as $campaignId) {
            if ($queue->push('SeoContentGenerateJob', ['campaign_id' => $campaignId])) {
                $enqueued++;
            }
        }
    }

    // ---------- 2) فهرسة IndexNow (عناصر generated) ----------
    $indexed = 0;
    foreach ($service->pendingIndexItems(20) as $item) {
        try {
            $res = $service->indexItem((int) $item['id']);
            if (!empty($res['success'])) {
                $indexed++;
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('SEO content engine: index failed', ['item_id' => $item['id'], 'error' => $e->getMessage()]);
            }
        }
    }

    // ---------- 3) تجارب A/B على العناوين (عناصر indexed من غير تجربة) ----------
    $abCreated = 0;
    foreach ($service->pendingAbTestItems(20) as $item) {
        try {
            $res = $service->createTitleAbTest((int) $item['id']);
            if (!empty($res['success'])) {
                $abCreated++;
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('SEO content engine: A/B create failed', ['item_id' => $item['id'], 'error' => $e->getMessage()]);
            }
        }
    }

    // ---------- 4) تطبيق العنوان الفائز (تجارب مكتملة) ----------
    $applied = 0;
    foreach ($service->pendingWinnerApplyItems(20) as $item) {
        try {
            $res = $service->applyWinningTitleToItem((int) $item['id']);
            if (!empty($res['success'])) {
                $applied++;
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('SEO content engine: winner apply failed', ['item_id' => $item['id'], 'error' => $e->getMessage()]);
            }
        }
    }

    $durationMs = round((microtime(true) - $startedAt) * 1000);
    fwrite(STDOUT, sprintf(
        "[%s] SEO Content Engine: %d campaigns enqueued, %d indexed, %d A/B created, %d winner applied (%dms)\n",
        date('Y-m-d H:i:s'),
        $enqueued,
        $indexed,
        $abCreated,
        $applied,
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] SEO content engine error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('SEO content engine failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}
