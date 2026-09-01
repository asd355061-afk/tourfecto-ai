<?php
/**
 * شريط أقسام موديول الإعلانات - Partial مشترك لكل صفحات /ads.
 * بياخد $adsActive من المتغيرات اللي بيمررها الـ Controller للـ View.
 */
$adsActive = $adsActive ?? 'dashboard';
$adsTabs = [
    'dashboard' => ['نظرة عامة', '/ads'],
    'campaigns' => ['الحملات', '/ads#campaignsTable'],
    'reports' => ['التقارير', '/ads/reports'],
    'budget' => ['الميزانية والإنفاق', '/ads/budget'],
    'market_research' => ['بحث الأسواق', '/ads/market-research'],
    'competitors' => ['المنافسون', '/ads/competitors'],
    'autopilot' => ['Autopilot', '/ads/autopilot'],
    'copilot' => ['AI Copilot', '/ads/copilot'],
    'alerts' => ['التنبيهات', '/ads/alerts'],
    'connections' => ['ربط المنصات', '/ads/connections'],
    'team' => ['فريق العمل', '/ads/team'],
];
?>
<nav class="p-tabs ads-tabs" aria-label="أقسام الإعلانات">
    <?php foreach ($adsTabs as $key => $item): ?>
        <?php [$label, $url] = $item; ?>
        <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
           class="p-tab<?= $key === $adsActive ? ' active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
</nav>
