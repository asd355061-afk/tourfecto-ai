<?php

/**
 * Tourfecto - Revenue Action Executor (طبقة التنفيذ) v1.0.0
 *
 * الوحدة دي هي "طبقة التنفيذ" اللي بتحوّل توصيات الإيرادات (اللي كانت
 * بتحلل بس) لإجراءات حقيقية على النظام:
 *   - إنشاء مهمة CRM (بمرجع لجهة الاتصال لو متاحة) بتاريخ استحقاق وأولوية
 *     بناءً على خطورة الإجراء.
 *   - إشعار داخلي فوري للأعمال عالية الخطورة (severity = high).
 *   - تسجيل التنفيذ في `revai_action_executions` لمنع التكرار (dedup) مع
 *     نافذة زمنية، ووسم مصدر الرؤية في `revai_insights` بحالة actioned.
 *
 * ملاحظة: بيشتغل مع أي منفّذ خارجي عن طريق حقن دوال (taskCreator/notifier)
 * عشان يكون قابلًا للاختبار offline — في الإنتاج بيتأسسوا تلقائيًا على
 * CrmTaskService + Notification::notify.
 */
class RevenueActionExecutor
{
    /** @var Database */
    private $db;

    /** @var callable|null */
    private $taskCreator;

    /** @var callable|null */
    private $notifier;

    public function __construct(?Database $db = null, ?callable $taskCreator = null, ?callable $notifier = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->taskCreator = $taskCreator;
        $this->notifier = $notifier;
    }

    /**
     * تحويل قائمة الإجراءات (من RevenueActionService::getNextBestActions)
     * إلى "نوايا تنفيذ" نقيّة وقابلة للفحص. Pure function - من غير أي كتابة.
     *
     * @param array $actions قائمة الإجراءات كما تُرجعها RevenueActionService
     * @param int $windowDays نافذة منع التكرار بالأيام
     * @return array قائمة نوايا تنفيذ
     */
    public function planActions(array $actions, int $windowDays = 7): array
    {
        $intents = [];
        foreach ($actions as $action) {
            $intents[] = $this->planOne($action, $windowDays);
        }
        return $intents;
    }

    /**
     * تخطيط إجراء واحد → نية تنفيذ.
     */
    public function planOne(array $action, int $windowDays = 7): array
    {
        $severity = $action['severity'] ?? null;
        $confidence = (string) ($action['confidence'] ?? 'medium');
        $affected = (string) ($action['affected_area'] ?? '');
        $affected = $affected === '' ? 'all' : $affected;

        $actionKey = implode(':', [
            (string) ($action['source_type'] ?? 'action'),
            (string) ($action['source_category'] ?? 'general'),
            $affected,
        ]);
        if (!empty($action['period'])) {
            $actionKey .= ':' . (string) $action['period'];
        }

        $relatedType = null;
        $relatedId = null;
        if (preg_match('/^customer:(\d+)$/', $affected, $m)) {
            $relatedType = 'crm_contacts';
            $relatedId = (int) $m[1];
        }

        $priority = 'medium';
        $dueOffsetDays = 7;
        if ($severity === 'high' || ($severity === null && $confidence === 'high')) {
            $priority = 'high';
            $dueOffsetDays = 1;
        } elseif ($severity === 'medium') {
            $priority = 'medium';
            $dueOffsetDays = 3;
        } elseif ($severity === 'low') {
            $priority = 'low';
            $dueOffsetDays = 7;
        }

        $title = '⚡ ' . $this->taskTitle($action);
        $description = $this->taskDescription($action);

        return [
            'action_key' => $actionKey,
            'action' => (string) ($action['action'] ?? 'Follow-up'),
            'source_type' => $action['source_type'] ?? null,
            'source_category' => $action['source_category'] ?? null,
            'affected_area' => $affected,
            'severity' => $severity,
            'confidence' => $confidence,
            'priority' => $priority,
            'due_date' => date('Y-m-d H:i:s', strtotime("+{$dueOffsetDays} days")),
            'task' => [
                'title' => $title,
                'description' => $description,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
            ],
            'notify' => ($severity === 'high'),
            'window_days' => (int) $windowDays,
        ];
    }

    /**
     * هل تم تنفيذ نفس الإجراء لنفس المستخدم داخل النافذة الزمنية؟
     */
    public function alreadyExecuted(int $userId, string $actionKey, int $windowDays = 7): bool
    {
        $rows = $this->db->query(
            "SELECT id FROM revai_action_executions
              WHERE user_id = ? AND action_key = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 1",
            [$userId, $actionKey, $windowDays]
        );
        return !empty($rows);
    }

    /**
     * تنفيذ قائمة إجراءات فعلًا، مع منع التكرار وتسجيل التاريخ.
     *
     * @param int $userId
     * @param array $actions إجراءات من RevenueActionService::getNextBestActions
     * @param array $opts ['create_tasks'=>bool, 'notify'=>bool, 'window_days'=>int, 'dry_run'=>bool]
     * @return array ملخص التنفيذ
     */
    public function executeActions(int $userId, array $actions, array $opts = []): array
    {
        $createTasks = $opts['create_tasks'] ?? true;
        $notify = $opts['notify'] ?? true;
        $windowDays = (int) ($opts['window_days'] ?? 7);
        $dryRun = !empty($opts['dry_run']);

        $summary = [
            'planned' => 0,
            'executed' => 0,
            'tasks_created' => 0,
            'notifications_sent' => 0,
            'skipped' => 0,
            'errors' => 0,
            'executed_items' => [],
        ];

        foreach ($actions as $action) {
            $summary['planned']++;
            $intent = $this->planOne($action, $windowDays);

            if (!$dryRun && $this->alreadyExecuted($userId, $intent['action_key'], $windowDays)) {
                $summary['skipped']++;
                continue;
            }

            $taken = [];
            try {
                if (!$dryRun && $createTasks && !empty($intent['task']['title'])) {
                    $this->createTask($userId, $intent['task'], $intent['priority'], $intent['due_date']);
                    $taken[] = 'crm_task';
                    $summary['tasks_created']++;
                }
                if (!$dryRun && $notify && $intent['notify']) {
                    $this->notifyUser($userId, $intent);
                    $taken[] = 'notification';
                    $summary['notifications_sent']++;
                }
                if (!$dryRun) {
                    if (!empty($taken)) {
                        $this->recordExecution($userId, $intent, $taken);
                        $this->markInsightActioned($userId, $intent);
                    }
                } else {
                    $taken[] = 'crm_task';
                    if ($intent['notify']) {
                        $taken[] = 'notification';
                    }
                }
                $summary['executed']++;
                $summary['executed_items'][] = [
                    'action_key' => $intent['action_key'],
                    'action' => $intent['action'],
                    'severity' => $intent['severity'],
                    'taken' => $taken,
                ];
            } catch (Throwable $e) {
                $summary['errors']++;
                if (class_exists('Logger')) {
                    Logger::warning('Revenue action execute failed', ['user_id' => $userId, 'key' => $intent['action_key'], 'error' => $e->getMessage()]);
                }
            }
        }

        return $summary;
    }

    /** تاريخ آخر عمليات التنفيذ للمستخدم (حد $limit). */
    public function history(int $userId, int $limit = 20): array
    {
        return $this->db->query(
            "SELECT id, action_key, source_type, source_category, affected_area, severity, actions_taken, created_at
               FROM revai_action_executions
              WHERE user_id = ?
              ORDER BY id DESC LIMIT ?",
            [$userId, $limit]
        );
    }

    // ==================== internal ====================

    private function createTask(int $userId, array $taskData, string $priority, string $dueDate): void
    {
        $payload = array_merge($taskData, ['priority' => $priority, 'due_date' => $dueDate]);
        if ($this->taskCreator !== null) {
            call_user_func($this->taskCreator, $userId, $payload);
            return;
        }
        (new CrmTaskService())->create($userId, $payload);
    }

    private function notifyUser(int $userId, array $intent): void
    {
        if ($this->notifier !== null) {
            call_user_func($this->notifier, $userId, $intent);
            return;
        }
        $body = isset($intent['task']['description']) && $intent['task']['description'] !== ''
            ? $intent['task']['description']
            : $intent['action'];
        Notification::notify($userId, 'revenue_action', $intent['action'], $body, '/revenue/intelligence');
    }

    private function recordExecution(int $userId, array $intent, array $taken): void
    {
        $this->db->exec(
            "INSERT INTO revai_action_executions
               (user_id, action_key, source_type, source_category, affected_area, severity, actions_taken)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $userId,
                $intent['action_key'],
                $intent['source_type'],
                $intent['source_category'],
                $intent['affected_area'],
                $intent['severity'],
                json_encode($taken, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    /** وسم الرؤية المصدر (risk/opportunity/anomaly) بحالة actioned. */
    private function markInsightActioned(int $userId, array $intent): void
    {
        $this->db->exec(
            "UPDATE revai_insights SET status = 'actioned'
              WHERE user_id = ? AND type = ? AND category = ? AND status = 'active'",
            [$userId, $intent['source_type'], $intent['source_category']]
        );
    }

    private function taskTitle(array $action): string
    {
        return 'إجراء إيرادات: ' . (string) ($action['action'] ?? 'Follow-up');
    }

    private function taskDescription(array $action): string
    {
        $parts = [];
        if (!empty($action['recommended_action'])) {
            $parts[] = (string) $action['recommended_action'];
        }
        if (!empty($action['reason'])) {
            $parts[] = (string) $action['reason'];
        }
        return implode("\n", $parts);
    }
}
