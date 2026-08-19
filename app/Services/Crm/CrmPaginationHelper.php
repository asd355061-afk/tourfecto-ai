<?php

/**
 * Tourfecto - CRM Pagination Helper (بند 37)
 * @version 1.0.0
 *
 * مستخلص من `CrmContactService::search()` (المرحلة 8) عشان يُستخدم بنفس
 * الشكل في Tasks/Companies/Appointments/Deals/Leads بدل تكرار نفس كود
 * COUNT+LIMIT/OFFSET في كل Service (بند 33: لا تنشئ أنظمة مكررة).
 */
trait CrmPaginationHelper
{
    /**
     * @param string $table اسم الجدول
     * @param string $whereSql شرط WHERE جاهز (بدون كلمة WHERE نفسها)
     * @param array $params قيم الشرط بنفس ترتيب علامات الاستفهام
     * @param string $orderBy عمود/اتجاه الترتيب (مُتحقَّق منه مسبقًا من المستدعي - لا يُمرَّر مباشرة من المستخدم أبدًا لتفادي SQL Injection)
     */
    protected function paginateQuery(string $table, string $whereSql, array $params, int $page, int $perPage, string $orderBy = 'created_at DESC'): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $db = Database::getInstance();
        $total = (int) ($db->query("SELECT COUNT(*) AS c FROM `{$table}` WHERE {$whereSql}", $params)[0]['c'] ?? 0);
        $items = $db->query(
            "SELECT * FROM `{$table}` WHERE {$whereSql} ORDER BY {$orderBy} LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }
}
