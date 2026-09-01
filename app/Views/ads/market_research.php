<?php
include __DIR__ . '/_tabs.php';
?>

<div class="p-card ads-card">
    <div class="p-card-head">
        <h3>🌍 بحث الأسواق والدول (AI)</h3>
        <span class="p-card-sub">توصية استشارية مبنية على معرفة السوق - مش بيانات طلب بحث حقيقية</span>
    </div>
    <textarea id="mrGoalDesc" class="p-select" style="width:100%;min-height:60px;" placeholder="مثال: عايز أجيب حجوزات سياحية من أوروبا لمصر"></textarea>
    <button type="button" class="p-btn primary xs" style="margin-top:8px;" onclick="runMarketResearch()">تحليل الأسواق</button>
    <div id="marketResearchResults" style="margin-top:10px;"></div>
</div>

<div class="p-card" id="mrHistoryCard">
    <div class="p-card-head"><h3>📜 أرشيف التحليلات السابقة</h3></div>
    <div id="mrHistoryBox"><div class="p-loading-row">جارِ التحميل...</div></div>
</div>
