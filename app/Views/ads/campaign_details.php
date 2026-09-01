<?php
/**
 * تفاصيل حملة /ads/campaigns/{id}
 * المتغيرات: $campaignId, $nameSafe
 */
include __DIR__ . '/_tabs.php';
?>
<div class="ads-card">
    <a href="/ads#campaignsTable" class="p-cell-muted ads-back-link">← رجوع لقائمة الحملات</a>
</div>

<div class="p-card ads-card" id="campaignOverviewCard">
    <div class="p-loading-row">جارِ التحميل...</div>
</div>

<div class="p-card ads-card">
    <div class="p-card-head"><h3>📈 أداء الحملة</h3></div>
    <div id="campaignTrendEmpty" class="ads-chart-empty">لا توجد بيانات كافية بعد</div>
    <canvas id="campaignTrendChart" height="90" class="ads-chart"></canvas>
</div>

<div class="ads-grid-2" id="campaignTwoCol">
    <div class="p-card">
        <div class="p-card-head"><h3>🎯 الاستهداف والجمهور</h3></div>
        <div id="campaignAudienceBox"><div class="p-loading-row">جارِ التحميل...</div></div>
    </div>
    <div class="p-card">
        <div class="p-card-head"><h3>🌐 صفحة الهبوط</h3></div>
        <div id="campaignLandingPageBox">
            <input type="text" id="lpUrl" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="https://example.com/landing-page">
            <button type="button" class="p-btn primary xs" onclick="analyzeCampaignLandingPage()">تحليل الصفحة</button>
            <div id="lpResults" style="margin-top:10px;font-size:13px;"></div>
        </div>
    </div>
</div>

<div class="p-card ads-card" id="adGroupsCard">
    <div class="p-card-head">
        <h3>📁 المجموعات الإعلانية (Ad Groups)</h3>
        <span class="p-card-sub">تنظيم محلي للكلمات/الإعلانات - مش مزامنة حقيقية مع Ad Set على المنصة</span>
    </div>
    <div class="ads-toolbar" style="margin-bottom:10px;">
        <input type="text" id="newAdGroupName" class="p-select ads-search" placeholder="اسم مجموعة إعلانية جديدة">
        <button type="button" class="p-btn primary xs" onclick="createAdGroup()">+ إضافة</button>
    </div>
    <div id="adGroupsBox"><div class="p-loading-row">جارِ التحميل...</div></div>
</div>

<div class="p-card ads-card">
    <div class="p-card-head"><h3>✍️ الإعلانات (Creatives)</h3></div>
    <div id="campaignCopiesBox"><div class="p-loading-row">جارِ التحميل...</div></div>
</div>

<div class="p-card ads-card">
    <div class="p-card-head">
        <h3>🔑 الكلمات المفتاحية</h3>
        <button type="button" class="p-btn outline xs" onclick="generateCampaignKeywords()">توليد بالذكاء الاصطناعي</button>
    </div>
    <textarea id="kwGoalDesc" class="p-select" style="width:100%;min-height:50px;margin-bottom:8px;display:none;" placeholder="وصف مختصر للعرض (اختياري)"></textarea>
    <div id="kwResults"><div class="p-loading-row">جارِ التحميل...</div></div>
</div>

<div class="p-card ads-card">
    <div class="p-card-head"><h3>🔗 روابط UTM</h3></div>
    <input type="text" id="utmDest" class="p-select" style="width:100%;margin-bottom:6px;" placeholder="رابط الوجهة (صفحة الهبوط)">
    <div class="ads-grid-2" style="margin-bottom:0;gap:8px;">
        <input type="text" id="utmSource" class="p-select" placeholder="utm_source" value="google">
        <input type="text" id="utmMedium" class="p-select" placeholder="utm_medium" value="cpc">
    </div>
    <button type="button" class="p-btn primary xs" style="margin-top:8px;" onclick="createCampaignUtmLink()">إنشاء رابط</button>
    <div id="utmResults" style="margin-top:10px;font-size:13px;"></div>
    <div id="utmListBox" style="margin-top:10px;"></div>
</div>

<div id="campaignDetailsConfig" data-campaign-id="<?= (int) $campaignId ?>" style="display:none;"></div>

<div class="p-card">
    <div class="p-card-head"><h3>📜 سجل النشاط والقرارات</h3></div>
    <div id="campaignLogBox"><div class="p-loading-row">جارِ التحميل...</div></div>
</div>
