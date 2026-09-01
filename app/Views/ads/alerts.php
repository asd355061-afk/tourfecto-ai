<?php
include __DIR__ . '/_tabs.php';
?>

<div class="p-card ads-card">
    <div class="p-card-head">
        <h3>🔔 التنبيهات الاستباقية</h3>
        <span class="p-card-sub">قواعد آلية تراقب أداء حملاتك الحقيقي (إنفاق/CPC/CTR/صفحة هبوط) وتنبهك عند حدوث مشكلة</span>
    </div>
    <div class="ads-toolbar">
        <button type="button" class="p-btn primary" onclick="runAlertsNow()">تقييم فوري الآن</button>
        <button type="button" class="p-btn" onclick="markAllRead()">تعليم الكل كمقروء</button>
    </div>
    <div id="alertsList"><div class="p-loading-row">جارِ التحميل...</div></div>
</div>

<div class="p-card">
    <div class="p-card-head">
        <h3>⚙️ إعدادات القواعد</h3>
        <span class="p-card-sub">فعّل/عطّل كل قاعدة واضبط حدّها</span>
    </div>
    <div id="rulesBox"><div class="p-loading-row">جارِ التحميل...</div></div>
</div>
