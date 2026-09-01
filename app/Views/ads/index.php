<?php
/**
 * لوحة نظرة عامة /ads — Dashboard
 * المتغيرات: $objectiveOptionsHtml, $ctasJson
 */
include __DIR__ . '/_tabs.php';
?>

<section class="ads-hero">
    <div>
        <h2 class="ads-hero-title">لوحة إدارة الحملات</h2>
        <p class="ads-hero-sub">راقب أداء حملاتك عبر Meta وGoogle في مكان واحد، وولّد حملات ونصوص إعلانية بالذكاء الاصطناعي بضغطة واحدة.</p>
    </div>
    <div class="ads-hero-actions">
        <button type="button" class="p-btn primary" onclick="openAiWizard()">✨ حملة بالذكاء الاصطناعي</button>
        <button type="button" class="p-btn outline" onclick="document.getElementById('newCampaignModal').classList.add('open')">+ حملة يدوية</button>
    </div>
</section>

<div class="p-card ads-card" id="dashboardFilters">
    <div class="ads-filters">
        <div class="ads-filter-field">
            <label for="dashPeriod">الفترة</label>
            <select id="dashPeriod" class="p-select" onchange="loadDashboardSummary()">
                <option value="daily">آخر يوم</option>
                <option value="weekly" selected>آخر 7 أيام</option>
                <option value="monthly">آخر 30 يوم</option>
            </select>
        </div>
        <div class="ads-filter-field">
            <label for="dashPlatform">المنصة</label>
            <select id="dashPlatform" class="p-select" onchange="loadDashboardSummary()">
                <option value="">كل المنصات</option>
                <option value="meta_ads">Meta Ads</option>
                <option value="google_ads">Google Ads</option>
            </select>
        </div>
        <div class="ads-filter-field">
            <label for="dashStatus">الحالة</label>
            <select id="dashStatus" class="p-select" onchange="loadDashboardSummary()">
                <option value="">كل الحالات</option>
                <option value="active">نشطة</option>
                <option value="paused">متوقفة</option>
            </select>
        </div>
    </div>
</div>

<div id="dashboardKpis" class="ads-kpi-grid">
    <div class="p-loading-row">جارِ التحميل...</div>
</div>

<div class="p-card ads-card" id="dashboardRecommendationsCard">
    <div class="p-card-head">
        <h3>💡 توصيات الذكاء الاصطناعي</h3>
        <span class="p-card-sub">مبنية على أداء حسابك الفعلي - راجع صفحة Autopilot لتفاصيل كل توصية</span>
    </div>
    <div id="dashboardRecommendations"><div class="p-loading-row">جارِ التحميل...</div></div>
</div>

<div class="p-card ads-card" id="metaConnectCard">
    <div class="p-card-head">
        <h3>Meta Ads (Facebook / Instagram)</h3>
        <span class="p-card-sub">اربط حساب إعلاناتك عشان تسحب حملات وإنفاق حقيقي</span>
    </div>
    <div id="metaConnectionStatus"><div class="p-loading-row">جارِ التحميل...</div></div>
</div>

<div class="p-card ads-card" id="googleAdsConnectCard">
    <div class="p-card-head">
        <h3>Google Ads</h3>
        <span class="p-card-sub">اربط حساب Google Ads عشان تسحب حملات وإنفاق حقيقي</span>
    </div>
    <div id="googleAdsConnectionStatus"><div class="p-loading-row">جارِ التحميل...</div></div>
</div>

<div class="ads-toolbar">
    <input type="text" id="campaignSearch" class="p-select ads-search" placeholder="ابحث باسم الحملة...">
    <select id="campaignStatusFilter" class="p-select">
        <option value="">كل الحالات</option>
        <option value="active">نشطة</option>
        <option value="paused">متوقفة</option>
        <option value="draft">مسودة</option>
    </select>
    <select id="campaignSort" class="p-select">
        <option value="created_at">الأحدث</option>
        <option value="name">الاسم</option>
        <option value="spend">الإنفاق</option>
        <option value="daily_budget">الميزانية</option>
    </select>
</div>

<div id="bulkActionBar" class="ads-bulk-bar">
    <span id="bulkSelectedCount" class="p-cell-muted"></span>
    <button type="button" class="p-btn outline xs" onclick="bulkUpdateStatus('active')">▶ استئناف المحدّد</button>
    <button type="button" class="p-btn outline xs" onclick="bulkUpdateStatus('paused')">⏸ إيقاف المحدّد</button>
</div>

<div class="p-card no-pad ads-table-card">
    <div class="p-table-scroll">
        <table class="p-table" id="campaignsTable">
            <thead>
                <tr>
                    <th><input type="checkbox" id="selectAllCampaigns" onchange="toggleSelectAll()"></th>
                    <th>الاسم</th>
                    <th>الميزانية اليومية</th>
                    <th>الحالة</th>
                    <th>الإنفاق</th>
                    <th>النصوص الإعلانية</th>
                </tr>
            </thead>
            <tbody><tr class="p-loading-row"><td colspan="6">جارِ التحميل...</td></tr></tbody>
        </table>
    </div>
    <div id="campaignsPagination" class="ads-pagination"></div>
</div>

<div class="p-modal-overlay" id="campaignToolsModal">
    <div class="p-modal wide">
        <div class="p-modal-head">
            <h3>🛠 أدوات الحملة: <span id="toolsCampaignName"></span></h3>
            <button type="button" class="p-modal-close" onclick="document.getElementById('campaignToolsModal').classList.remove('open')">×</button>
        </div>
        <div class="p-modal-body">
            <div class="p-card ads-card">
                <div class="p-card-head"><h3>🔑 كلمات مفتاحية (AI Keyword Strategist)</h3></div>
                <textarea id="kwGoalDesc" class="p-select" style="width:100%;min-height:60px;" placeholder="وصف مختصر للعرض (لو فاضي هيستخدم product_or_service المسجّل بالفعل)"></textarea>
                <button type="button" class="p-btn primary xs ads-card" style="margin-top:8px;" onclick="generateCampaignKeywords()">توليد الكلمات المفتاحية</button>
                <div id="kwResults" style="margin-top:10px;font-size:13px;"></div>
            </div>

            <div class="p-card ads-card">
                <div class="p-card-head"><h3>🎯 تحليل صفحة الهبوط</h3></div>
                <input type="text" id="lpUrl" class="p-select" style="width:100%;" placeholder="https://example.com/landing-page">
                <button type="button" class="p-btn primary xs" style="margin-top:8px;" onclick="analyzeCampaignLandingPage()">تحليل الصفحة</button>
                <div id="lpResults" style="margin-top:10px;font-size:13px;"></div>
            </div>

            <div class="p-card">
                <div class="p-card-head"><h3>🔗 رابط UTM جديد</h3></div>
                <input type="text" id="utmDest" class="p-select" style="width:100%;margin-bottom:6px;" placeholder="رابط الوجهة (صفحة الهبوط)">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <input type="text" id="utmSource" class="p-select" placeholder="utm_source (مثال: google)" value="google">
                    <input type="text" id="utmMedium" class="p-select" placeholder="utm_medium (مثال: cpc)" value="cpc">
                </div>
                <button type="button" class="p-btn primary xs" style="margin-top:8px;" onclick="createCampaignUtmLink()">إنشاء الرابط</button>
                <div id="utmResults" style="margin-top:10px;font-size:13px;"></div>
            </div>
        </div>
    </div>
</div>

<div id="adsWizardConfig" data-ctas="<?= $ctasJson ?>" style="display:none;"></div>

<div class="p-modal-overlay" id="newCampaignModal">
    <div class="p-modal">
        <div class="p-modal-head">
            <h3>حملة إعلانية جديدة (يدوي)</h3>
            <button type="button" class="p-modal-close" onclick="document.getElementById('newCampaignModal').classList.remove('open')">×</button>
        </div>
        <div class="p-modal-body">
            <label for="campaignName">اسم الحملة</label>
            <input type="text" id="campaignName" class="p-select" style="width:100%;margin-bottom:10px;">
            <label for="campaignBudget">الميزانية اليومية (USD)</label>
            <input type="number" id="campaignBudget" class="p-select" style="width:100%;">
        </div>
        <div class="p-modal-foot">
            <button type="button" class="p-btn" onclick="createCampaign()">إنشاء</button>
        </div>
    </div>
</div>

<div class="p-modal-overlay" id="aiWizardModal">
    <div class="p-modal wide">
        <div class="p-modal-head">
            <h3>✨ حملة إعلانية بالذكاء الاصطناعي</h3>
            <button type="button" class="p-modal-close" onclick="closeAiWizard()">×</button>
        </div>
        <div class="p-modal-body">
            <div id="aiWizardStep1">
                <label for="aiObjective">الهدف من الحملة</label>
                <select id="aiObjective" class="p-select" style="width:100%;margin-bottom:14px;"><?= $objectiveOptionsHtml ?></select>

                <label for="aiGoalDescription">وصف مختصر لعرضك</label>
                <textarea id="aiGoalDescription" class="p-select" rows="3" style="width:100%;margin-bottom:14px;" placeholder="مثال: رحلة الغردقة 3 أيام 2 ليلة شاملة الإقامة والإفطار بـ 5000 جنيه للفرد" maxlength="2000"></textarea>

                <label for="aiDailyBudget">الميزانية اليومية المتوقعة (USD) - اختياري</label>
                <input type="number" id="aiDailyBudget" class="p-select" style="width:100%;margin-bottom:6px;" min="1" step="0.5">
                <div class="p-cell-muted ads-note" style="margin-bottom:16px;">سيب الحقل ده فاضي لو عايز الذكاء الاصطناعي يقترحلك رقم مناسب</div>

                <button type="button" class="p-btn primary btn-block" id="aiGenerateBtn" onclick="generateAiBrief()">توليد الحملة بالذكاء الاصطناعي ✨</button>
                <div class="p-cell-muted ads-note" style="text-align:center;margin-top:8px;">هيتم خصم سعر التوليد من رصيد محفظتك عند نجاح التوليد بس</div>
                <div id="aiWizardError" class="alert alert-danger" style="display:none;margin-top:12px;"></div>
            </div>

            <div id="aiWizardStep2" style="display:none;"></div>
        </div>
        <div class="p-modal-foot" id="aiWizardFoot" style="display:none;">
            <button type="button" class="p-btn outline" onclick="backToAiStep1()">‹ رجوع للتعديل</button>
            <button type="button" class="p-btn primary" id="aiConfirmCreateBtn" onclick="confirmCreateAiCampaign()">إنشاء الحملة ✅</button>
        </div>
    </div>
</div>
