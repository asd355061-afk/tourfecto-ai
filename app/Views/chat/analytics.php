<?php
/**
 * chat/analytics — تحليلات الشات (توزيع المحادثات، صحة مزودي AI، الوسوم، الخدمات، التعلّم، الاستخدام).
 */
?>
{ICON_SPRITE}
<div class="ch-toolbar">
    <a href="/chat" class="p-btn outline xs">{IC_INBOX}صندوق الوارد</a>
    <span class="ch-toolbar-spacer"></span>
    <select id="anSince" class="p-select" onchange="load()">
        <option value="7">آخر 7 أيام</option>
        <option value="30" selected>آخر 30 يوم</option>
        <option value="90">آخر 90 يوم</option>
    </select>
</div>
<div id="anNoWebsite" class="ch-card" style="display:none;">
    <div class="ch-empty"><div class="ch-empty-icon">{IC_GLOBE}</div><div class="ch-empty-sub">اختر موقعًا من القائمة أعلى الصفحة أولًا.</div></div>
</div>
<div id="anBody" style="display:none;">

    <div id="anStats" class="ch-stats"></div>

    <div class="ch-split">
        <div class="ch-split-main">
            <div class="ch-card">
                <div class="ch-card-head"><h3 class="ch-card-title">{IC_CHART} توزيع المحادثات</h3></div>
                <div style="padding:6px 4px;"><canvas id="anConvChart" height="120"></canvas></div>
            </div>
        </div>
        <div class="ch-split-side">
            <div class="ch-card">
                <div class="ch-card-head"><h3 class="ch-card-title">{IC_SPARKLES} صحة مزودي الذكاء الاصطناعي</h3></div>
                <div id="anHealth" style="padding:4px 2px;"></div>
            </div>
        </div>
    </div>

    <div class="ch-split">
        <div class="ch-split-main">
            <div class="ch-card">
                <div class="ch-card-head"><h3 class="ch-card-title">{IC_TAG} أكثر الوسوم تكرارًا</h3></div>
                <div id="anTags"></div>
            </div>
        </div>
        <div class="ch-split-side">
            <div class="ch-card">
                <div class="ch-card-head"><h3 class="ch-card-title">{IC_TARGET} أكثر الخدمات طلبًا</h3></div>
                <div id="anServices"></div>
            </div>
        </div>
    </div>

    <div class="ch-split">
        <div class="ch-split-main">
            <div class="ch-card">
                <div class="ch-card-head"><h3 class="ch-card-title">{IC_SPARKLES} حلقة التعلّم: نتائج المحادثات</h3></div>
                <div id="anLearning"></div>
            </div>
        </div>
        <div class="ch-split-side">
            <div class="ch-card">
                <div class="ch-card-head"><h3 class="ch-card-title">{IC_HANDOFF} أسباب التحويل للموظف</h3></div>
                <div id="anEscalations"></div>
            </div>
        </div>
    </div>

    <div class="ch-card">
        <div class="ch-card-head"><h3 class="ch-card-title">{IC_CHART} استخدام مزودي الذكاء الاصطناعي (آخر 24 ساعة)</h3></div>
        <div class="p-table-scroll"><table class="p-table" id="anProviders">
            <thead><tr><th>المزود</th><th>النموذج</th><th>الطلبات</th><th>ناجحة</th><th>فاشلة</th><th>Fallback</th><th>Tokens</th><th>التكلفة التقديرية</th></tr></thead>
            <tbody></tbody>
        </table></div>
    </div>

</div>
