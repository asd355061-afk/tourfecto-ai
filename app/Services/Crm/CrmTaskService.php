<?php
/** Tourfecto - CRM Task / Follow-up Service (بند 11 و13) @version 1.0.0 */
class CrmTaskService {
    public function create(int $userId, array $data): CrmTask {
        if (empty($data['title'])) {
            throw new Exception('عنوان المهمة مطلوب');
        }
        $task = new CrmTask([
            'user_id' => $userId,
            'created_by_user_id' => $userId,
            'assigned_to_user_id' => $data['assigned_to_user_id'] ?? $userId,
            'related_type' => $data['related_type'] ?? null,
            'related_id' => $data['related_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
            'status' => 'open',
        ]);
        $task->save();

        ActivityLog::record('crm', 'task.created', [
            'user_id' => $userId, 'subject_type' => 'crm_tasks', 'subject_id' => (int) $task->getAttribute('id'),
        ]);

        return $task;
    }

    public function findOwned(int $userId, int $taskId): CrmTask {
        $task = (new CrmTask())->find($taskId);
        if (!$task || (int) $task->getAttribute('user_id') !== $userId) {
            throw new Exception('المهمة غير موجودة', 404);
        }
        return $task;
    }

    public function updateStatus(int $userId, int $taskId, string $status): CrmTask {
        $allowed = ['open', 'in_progress', 'done', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            throw new Exception('حالة غير صحيحة');
        }
        $task = $this->findOwned($userId, $taskId);
        $task->setAttribute('status', $status);
        $task->setAttribute('completed_at', $status === 'done' ? date('Y-m-d H:i:s') : null);
        $task->save();

        ActivityLog::record('crm', 'task.status_changed', [
            'user_id' => $userId, 'subject_type' => 'crm_tasks', 'subject_id' => $taskId, 'meta' => ['status' => $status],
        ]);

        return $task;
    }

    public function listForUser(int $userId, int $limit = 200): array {
        return (new CrmTask())->allForUser($userId, $limit);
    }

    public function overdue(int $userId): array {
        return (new CrmTask())->overdue($userId);
    }
}
