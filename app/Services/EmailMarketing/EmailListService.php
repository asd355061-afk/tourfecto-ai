<?php

/**
 * Tourfecto - Email Marketing: Audience (Lists + Subscribers) Service
 * @version 1.0.0
 *
 * إدارة قوائم الجمهور والمشتركين:
 *   - CRUD للقوائم مع عداد فعلي للمشتركين
 *   - اشتراك/إلغاء اشتراك/حذف (عزل كامل لكل مستخدم - tenant)
 *   - استيراد دفعة (array/CSV) مع تجاهل المكرر على (user_id, email)
 *   - توليد توكن إلغاء اشتراك فريد
 */
class EmailListService
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ============================ Lists ============================

    public function lists(int $userId): array
    {
        return $this->db->query(
            "SELECT l.*,
                    (SELECT COUNT(*) FROM email_list_subscriber els WHERE els.list_id = l.id) AS actual_count
             FROM email_lists l
             WHERE l.user_id = ?
             ORDER BY l.created_at DESC",
            [$userId]
        );
    }

    public function createList(int $userId, string $name, ?string $description = null): array
    {
        if (trim($name) === '') {
            return ['success' => false, 'error' => 'اسم القائمة مطلوب'];
        }

        $list = new EmailList([
            'user_id' => $userId,
            'name' => trim($name),
            'description' => $description !== null ? trim($description) : null,
            'subscriber_count' => 0,
        ]);
        $id = $list->save();

        return $id ? ['success' => true, 'id' => (int) $id] : ['success' => false, 'error' => 'تعذر إنشاء القائمة'];
    }

    public function updateList(int $userId, int $listId, array $data): array
    {
        $list = (new EmailList())->find($listId);
        if (!$list || (int) $list->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'القائمة غير موجودة'];
        }
        if (isset($data['name'])) {
            if (trim((string) $data['name']) === '') {
                return ['success' => false, 'error' => 'اسم القائمة مطلوب'];
            }
            $list->setAttribute('name', trim((string) $data['name']));
        }
        if (array_key_exists('description', $data)) {
            $list->setAttribute('description', $data['description'] !== null ? trim((string) $data['description']) : null);
        }
        $list->save();
        return ['success' => true];
    }

    public function deleteList(int $userId, int $listId): array
    {
        $list = (new EmailList())->find($listId);
        if (!$list || (int) $list->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'القائمة غير موجودة'];
        }
        // حذف القائمة يمسح الربط تلقائيًا (FK cascade) - لا نحذف المشتركين أنفسهم
        $list->delete();
        return ['success' => true];
    }

    // ============================ Subscribers ============================

    public function subscribers(int $userId, int $listId, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        $where = 's.user_id = ?';
        $params = [$userId];

        if ($listId > 0) {
            $where .= ' AND EXISTS (SELECT 1 FROM email_list_subscriber els WHERE els.subscriber_id = s.id AND els.list_id = ?)';
            $params[] = $listId;
        }
        if (!empty($filters['status'])) {
            $where .= ' AND s.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['q'])) {
            $where .= ' AND (s.email LIKE ? OR s.name LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $countRow = $this->db->query(
            "SELECT COUNT(*) AS total FROM email_subscribers s WHERE {$where}",
            $params
        );
        $total = (int) ($countRow[0]['total'] ?? 0);

        $offset = ($page - 1) * $perPage;
        $rows = $this->db->query(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM email_list_subscriber els WHERE els.subscriber_id = s.id) AS list_count
             FROM email_subscribers s
             WHERE {$where}
             ORDER BY s.created_at DESC
             LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
            $params
        );

        return [
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * اشتراك جديد (أو تفعيل مجدد لو كان unsubscribed/bounced).
     * @return array ['success'=>bool, 'id'=>?int, 'created'=>bool, 'error'=>?string]
     */
    public function subscribe(int $userId, string $email, array $data = [], ?int $listId = null): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'بريد إلكتروني غير صالح'];
        }

        $existing = (new EmailSubscriber())->where(['user_id' => $userId, 'email' => $email]);
        $subscriber = $existing[0] ?? null;

        $created = false;
        if ($subscriber) {
            $subscriber->setAttribute('status', 'subscribed');
            $subscriber->setAttribute('unsubscribed_at', null);
            if (!empty($data['name'])) {
                $subscriber->setAttribute('name', trim((string) $data['name']));
            }
            if (isset($data['source'])) {
                $subscriber->setAttribute('source', (string) $data['source']);
            }
            $subscriber->save();
            $id = (int) $subscriber->getAttribute('id');
        } else {
            $token = $this->uniqueUnsubscribeToken();
            $sub = new EmailSubscriber([
                'user_id' => $userId,
                'email' => $email,
                'name' => trim((string) ($data['name'] ?? '')),
                'attributes' => !empty($data['attributes']) ? json_encode($data['attributes'], JSON_UNESCAPED_UNICODE) : null,
                'status' => 'subscribed',
                'unsubscribe_token' => $token,
                'source' => (string) ($data['source'] ?? 'manual'),
            ]);
            $id = (int) $sub->save();
            $created = true;
        }

        if ($listId !== null && $listId > 0) {
            $this->attachToList($userId, $id, $listId);
        }

        return ['success' => true, 'id' => $id, 'created' => $created];
    }

    public function attachToList(int $userId, int $subscriberId, int $listId): bool
    {
        $list = (new EmailList())->find($listId);
        if (!$list || (int) $list->getAttribute('user_id') !== $userId) {
            return false;
        }
        $sub = (new EmailSubscriber())->find($subscriberId);
        if (!$sub || (int) $sub->getAttribute('user_id') !== $userId) {
            return false;
        }

        $this->db->query(
            "INSERT IGNORE INTO email_list_subscriber (list_id, subscriber_id) VALUES (?, ?)",
            [$listId, $subscriberId]
        );
        $this->refreshListCount($listId);
        return true;
    }

    public function detachFromList(int $userId, int $subscriberId, int $listId): bool
    {
        $this->db->query(
            "DELETE FROM email_list_subscriber WHERE list_id = ? AND subscriber_id = ?",
            [$listId, $subscriberId]
        );
        $this->refreshListCount($listId);
        return true;
    }

    public function unsubscribeByToken(string $token): bool
    {
        $sub = (new EmailSubscriber())->where(['unsubscribe_token' => $token]);
        if (empty($sub)) {
            return false;
        }
        $subscriber = $sub[0];
        $subscriber->setAttribute('status', 'unsubscribed');
        $subscriber->setAttribute('unsubscribed_at', date('Y-m-d H:i:s'));
        $subscriber->save();
        return true;
    }

    public function deleteSubscriber(int $userId, int $subscriberId): array
    {
        $sub = (new EmailSubscriber())->find($subscriberId);
        if (!$sub || (int) $sub->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'المشترك غير موجود'];
        }
        $sub->delete();
        return ['success' => true];
    }

    /**
     * استيراد دفعة مشتركين: array of ['email'=>..., 'name'=>...]
     * المكرر يتم تجاهله (upsert على user_id+email).
     */
    public function import(int $userId, array $rows, ?int $listId = null): array
    {
        $added = 0;
        $updated = 0;
        $invalid = 0;

        foreach ($rows as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalid++;
                continue;
            }
            $result = $this->subscribe($userId, $email, [
                'name' => (string) ($row['name'] ?? ''),
                'source' => 'import',
            ], $listId);
            if ($result['success']) {
                $result['created'] ? $added++ : $updated++;
            }
        }

        return ['success' => true, 'added' => $added, 'updated' => $updated, 'invalid' => $invalid];
    }

    // ============================ Helpers ============================

    public function uniqueUnsubscribeToken(): string
    {
        do {
            $token = bin2hex(random_bytes(20));
            $exists = $this->db->query(
                "SELECT id FROM email_subscribers WHERE unsubscribe_token = ? LIMIT 1",
                [$token]
            );
        } while (!empty($exists));
        return $token;
    }

    private function refreshListCount(int $listId): void
    {
        $this->db->query(
            "UPDATE email_lists l
             SET l.subscriber_count = (SELECT COUNT(*) FROM email_list_subscriber els WHERE els.list_id = l.id)
             WHERE l.id = ?",
            [$listId]
        );
    }
}
