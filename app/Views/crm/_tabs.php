<?php
/**
 * شريط تابات مشترك لكل صفحات /crm - Partial بياخد $crmActive من الـ Controller.
 * كمان بيعرّف $__tr مساعد ترجمة مُهرب (HTML-escaped) لكل الفيو اللي بتضمّن الـ partial ده.
 */
$crmActive = $crmActive ?? 'overview';
$__tr = static function (string $key): string {
    return htmlspecialchars(t($key), ENT_QUOTES, 'UTF-8');
};
$crmTabs = [
    'overview' => ['crm.tab.overview', '/crm'],
    'leads' => ['crm.tab.leads', '/crm/leads'],
    'deals' => ['crm.tab.deals', '/crm/deals'],
    'contacts' => ['crm.tab.contacts', '/crm/contacts'],
    'companies' => ['crm.tab.companies', '/crm/companies'],
    'tasks' => ['crm.tab.tasks', '/crm/tasks'],
    'appointments' => ['crm.tab.appointments', '/crm/appointments'],
    'automation' => ['crm.tab.automation', '/crm/automation'],
    'team' => ['crm.tab.team', '/crm/team'],
    'reports' => ['crm.tab.reports', '/crm/reports'],
];
?>
<nav class="p-tabs crm-tabs" aria-label="أقسام إدارة العملاء">
    <?php foreach ($crmTabs as $key => $item): ?>
        <?php [$labelKey, $url] = $item; ?>
        <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
           class="p-tab<?= $key === $crmActive ? ' active' : '' ?>"><?= $__tr($labelKey) ?></a>
    <?php endforeach; ?>
</nav>
