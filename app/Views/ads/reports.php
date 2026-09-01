<?php
include __DIR__ . '/_tabs.php';
?>

<div class="p-card ads-card">
    <div class="p-card-head">
        <h3>📈 اتجاه الأداء اليومي</h3>
        <select id="trendDays" class="p-select" style="width:auto;" onchange="loadTrendChart()">
            <option value="7">آخر 7 أيام</option>
            <option value="30" selected>آخر 30 يوم</option>
            <option value="90">آخر 90 يوم</option>
        </select>
    </div>
    <div id="trendChartEmpty" class="ads-chart-empty">لا توجد بيانات كافية بعد لعرض الاتجاه</div>
    <canvas id="trendChart" height="90" class="ads-chart"></canvas>
</div>

<div class="p-card ads-card" id="reportsCard">
    <div class="p-card-head">
        <h3>📊 تقرير الأداء الآلي</h3>
        <select id="reportPeriod" class="p-select" style="width:auto;" onchange="loadAdsReport()">
            <option value="daily">يومي</option>
            <option value="weekly" selected>أسبوعي</option>
            <option value="monthly">شهري</option>
        </select>
    </div>
    <div id="reportBox"><div class="p-loading-row">جارِ التحميل...</div></div>
</div>

<div class="p-card ads-card" id="attributionCard">
    <div class="p-card-head">
        <h3>🔗 الإسناد (Attribution) - روابط UTM</h3>
        <span class="p-card-sub">نقرات حقيقية مسجّلة لكل رابط تتبّع أنشأته لحملاتك</span>
    </div>
    <div id="attributionBox"><div class="p-cell-muted">اختار حملة من صفحة "الحملات" لعرض روابط الـUTM بتاعتها وأداء النقرات.</div></div>
</div>
