<?php
include __DIR__ . '/_tabs.php';
?>

<div id="budgetKpis" class="ads-kpi-grid">
    <div class="p-loading-row">جارِ التحميل...</div>
</div>

<div class="p-card ads-card">
    <div class="p-card-head"><h3>📉 اتجاه الإنفاق مقابل التحويلات</h3></div>
    <div id="budgetTrendEmpty" class="ads-chart-empty">لا توجد بيانات كافية بعد</div>
    <canvas id="budgetTrendChart" height="90" class="ads-chart"></canvas>
</div>

<div class="p-card">
    <div class="p-card-head">
        <h3>⚖️ مقارنة الحملات</h3>
        <select id="cmpPeriod" class="p-select" style="width:auto;" onchange="loadComparisonChart()">
            <option value="weekly" selected>آخر 7 أيام</option>
            <option value="monthly">آخر 30 يوم</option>
        </select>
    </div>
    <div id="comparisonEmpty" class="ads-chart-empty">لا توجد بيانات كافية بعد</div>
    <canvas id="comparisonChart" height="100" class="ads-chart"></canvas>
</div>
