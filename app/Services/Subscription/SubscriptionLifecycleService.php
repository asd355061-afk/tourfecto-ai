<?php
/**
 * Tourfecto - Subscription Lifecycle Service
 * @version 1.0.0
 * @date 2026-08-14
 *
 * دورة حياة الاشتراك (Section 7) - مبنية على قيم status ENUM الحقيقية
 * المؤكدة من قاعدة البيانات الفعلية (active/trialing/past_due/cancelled/paused)
 * - مفيش أي ALTER TABLE هنا، القيم دي موجودة بالفعل ومحدش كان بيستخدمها.
 *
 * الانتقالات:
 *   active   → past_due   (current_period_end انتهت من غير تجديد - بداية فترة سماح)
 *   trialing → past_due   (trial_ends_at انتهت - محتاج دفع فعلي)
 *   past_due → cancelled  (فترة السماح خلصت - GRACE_PERIOD_DAYS يوم بعد current_period_end)
 *
 * مفيش Cron حقيقي في المشروع (نفس القيد اللي واجهناه في MRR Snapshot) -
 * فالدالة دي "كسولة" (Lazy) برضه: بتتنفذ لما أدمن يفتح صفحة الاشتراكات،
 * مش على جدول زمني حقيقي. لو حبيت جدولة حقيقية، لازم Cron/Job runner
 * فعلي يستدعي runLifecycleChecks() دوريًا - مش موجود في نطاق المشروع
 * الحالي.
 */
class SubscriptionLifecycleService {
    /** فترة السماح بعد انتهاء current_period_end قبل ما الاشتراك يتلغي نهائيًا */
    private const GRACE_PERIOD_DAYS = 7;

    /** كام يوم قبل التجديد نبعت تذكير "Renewal Upcoming" */
    private const RENEWAL_REMINDER_DAYS = 3;

    /** @var Database */
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * تشغيل كل فحوصات دورة الحياة دفعة واحدة. Idempotent بالكامل - آمن
     * يتنفذ أي عدد مرات (كل صف بيتنقل مرة واحدة بس لأن الشرط بيبقى
     * مش صحيح تاني بعد الانتقال).
     *
     * @return array{moved_to_past_due: int, moved_to_cancelled: int, trials_ended: int, renewal_reminders_sent: int}
     */
    public function runLifecycleChecks(): array {
        return [
            'moved_to_past_due' => $this->transitionExpiredActiveToPastDue(),
            'trials_ended' => $this->transitionExpiredTrialsToPastDue(),
            'moved_to_cancelled' => $this->transitionExpiredGraceToCancelled(),
            'renewal_reminders_sent' => $this->sendRenewalReminders(),
        ];
    }

    /** active + current_period_end انتهت → past_due (بداية فترة السماح) */
    private function transitionExpiredActiveToPastDue(): int {
        try {
            $rows = $this->db->query(
                "SELECT id, user_id FROM subscriptions WHERE status = 'active' AND current_period_end <= NOW()"
            );
            foreach ($rows as $row) {
                $this->db->exec("UPDATE subscriptions SET status = 'past_due', updated_at = NOW() WHERE id = ?", [(int) $row['id']]);
                $this->notifyAndLog((int) $row['id'], (int) $row['user_id'], 'past_due',
                    'انتهت فترة اشتراكك', 'حصل تأخير في تجديد اشتراكك - عندك 7 أيام لتجديده قبل ما يتوقف تلقائيًا.');
            }
            return count($rows);
        } catch (Exception $e) {
            Logger::error('SubscriptionLifecycleService::transitionExpiredActiveToPastDue failed', ['message' => $e->getMessage()]);
            return 0;
        }
    }

    /** trialing + trial_ends_at انتهت → past_due (التجربة خلصت، محتاج دفع) */
    private function transitionExpiredTrialsToPastDue(): int {
        try {
            $rows = $this->db->query(
                "SELECT id, user_id FROM subscriptions WHERE status = 'trialing' AND trial_ends_at IS NOT NULL AND trial_ends_at <= NOW()"
            );
            foreach ($rows as $row) {
                $this->db->exec("UPDATE subscriptions SET status = 'past_due', updated_at = NOW() WHERE id = ?", [(int) $row['id']]);
                $this->notifyAndLog((int) $row['id'], (int) $row['user_id'], 'trial_ended',
                    'انتهت فترة التجربة المجانية', 'خلصت فترة تجربتك المجانية - جدّد اشتراكك عشان تستمر في استخدام الباقة.');
            }
            return count($rows);
        } catch (Exception $e) {
            Logger::error('SubscriptionLifecycleService::transitionExpiredTrialsToPastDue failed', ['message' => $e->getMessage()]);
            return 0;
        }
    }

    /** past_due + فترة السماح خلصت (GRACE_PERIOD_DAYS يوم) → cancelled */
    private function transitionExpiredGraceToCancelled(): int {
        try {
            $rows = $this->db->query(
                "SELECT id, user_id FROM subscriptions
                 WHERE status = 'past_due' AND current_period_end <= DATE_SUB(NOW(), INTERVAL ? DAY)",
                [self::GRACE_PERIOD_DAYS]
            );
            foreach ($rows as $row) {
                $this->db->exec("UPDATE subscriptions SET status = 'cancelled', updated_at = NOW() WHERE id = ?", [(int) $row['id']]);
                $this->notifyAndLog((int) $row['id'], (int) $row['user_id'], 'expired',
                    'انتهى اشتراكك', 'انتهت فترة السماح ({$this::GRACE_PERIOD_DAYS} أيام) من غير تجديد - اشتراكك بقى غير فعّال.');
            }
            return count($rows);
        } catch (Exception $e) {
            Logger::error('SubscriptionLifecycleService::transitionExpiredGraceToCancelled failed', ['message' => $e->getMessage()]);
            return 0;
        }
    }

    /** تذكير "التجديد قريب" - مرة واحدة بس لكل اشتراك (dedup عبر activity_logs) */
    private function sendRenewalReminders(): int {
        try {
            $rows = $this->db->query(
                "SELECT id, user_id FROM subscriptions
                 WHERE status = 'active'
                 AND current_period_end BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? DAY)",
                [self::RENEWAL_REMINDER_DAYS]
            );
            $sent = 0;
            foreach ($rows as $row) {
                $subId = (int) $row['id'];
                // Dedup: لو فيه تذكير اتسجّل قبل كده لنفس الاشتراك ده، متبعتش تاني
                $already = $this->db->query(
                    "SELECT id FROM activity_logs WHERE module = 'subscription' AND action = 'subscription.renewal_reminder'
                     AND JSON_EXTRACT(meta, '$.subscription_id') = ? LIMIT 1",
                    [$subId]
                );
                if (!empty($already)) {
                    continue;
                }
                $this->notifyAndLog($subId, (int) $row['user_id'], 'renewal_reminder',
                    'اشتراكك هيتجدد قريب', 'باقتك هتنتهي خلال ' . self::RENEWAL_REMINDER_DAYS . ' أيام - تأكد إن رصيد محفظتك كافي للتجديد التلقائي.');
                $sent++;
            }
            return $sent;
        } catch (Exception $e) {
            Logger::error('SubscriptionLifecycleService::sendRenewalReminders failed', ['message' => $e->getMessage()]);
            return 0;
        }
    }

    private function notifyAndLog(int $subscriptionId, int $userId, string $eventKey, string $title, string $body): void {
        if (class_exists('Notification')) {
            Notification::notify($userId, 'subscription_' . $eventKey, $title, $body, '/subscription');
        }
        $actionMap = [
            'past_due' => 'subscription.became_past_due',
            'trial_ended' => 'subscription.trial_ended',
            'expired' => 'subscription.grace_period_expired',
            'renewal_reminder' => 'subscription.renewal_reminder',
        ];
        ActivityLog::record('subscription', $actionMap[$eventKey] ?? 'subscription.lifecycle_event', [
            'user_id' => $userId,
            'subject_type' => 'subscriptions',
            'subject_id' => $subscriptionId,
            'meta' => ['subscription_id' => $subscriptionId, 'event' => $eventKey],
        ]);
    }
}
