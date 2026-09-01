<?php
/**
 * chat/learning — حلقة التعلّم: فجوات المعرفة (Knowledge Gaps).
 */
?>
{ICON_SPRITE}
<div class="ch-toolbar">
    <a href="/chat" class="p-btn outline xs">{IC_INBOX}صندوق الوارد</a>
    <span class="ch-toolbar-spacer"></span>
    <button class="p-btn outline xs" onclick="lnScan()">{IC_REFRESH}إعادة مسح الفجوات</button>
    <select id="lnSince" class="p-select" onchange="load()">
        <option value="7">آخر 7 أيام</option>
        <option value="30" selected>آخر 30 يوم</option>
        <option value="90">آخر 90 يوم</option>
    </select>
</div>
<div id="lnNoWebsite" class="ch-card" style="display:none;">
    <div class="ch-empty"><div class="ch-empty-icon">{IC_GLOBE}</div><div class="ch-empty-sub">اختر موقعًا من القائمة أعلى الصفحة أولًا.</div></div>
</div>
<div id="lnBody" style="display:none;">
    <div class="ch-stats">
        <div class="ch-stat teal">
            <div class="ch-stat-icon">{IC_SPARKLES}</div>
            <div class="ch-stat-value" id="lnResRate">-</div>
            <div class="ch-stat-label">معدّل حلّ الذكاء الاصطناعي</div>
        </div>
        <div class="ch-stat gold">
            <div class="ch-stat-icon">{IC_TARGET}</div>
            <div class="ch-stat-value" id="lnGapCount">-</div>
            <div class="ch-stat-label">فجوات معرفة غير معالجة</div>
        </div>
    </div>

    <div class="ch-card" style="margin-bottom:14px;">
        <div class="ch-card-head"><h3 class="ch-card-title">ℹ️ كيف تعمل حلقة التعلّم؟</h3></div>
        <div class="ch-card-body">
            <p class="p-cell-muted" style="font-size:13px;line-height:1.8;">
                عندما يحوّل الذكاء الاصطناعي محادثة لموظف لأن السؤال خارج قاعدة المعرفة أو الثقة منخفضة،
                تُسجَّل السؤال تلقائيًا كـ"فجوة معرفة". أضف إجابة الفجوة لقاعدة المعرفة ليردّ عليها الـAI
                في المرة القادمة — هكذا يتحسّن النظام تدريجيًا (Flywheel).
            </p>
        </div>
    </div>

    <div class="ch-card no-pad">
        <div class="ch-card-head"><h3 class="ch-card-title">🧠 فجوات المعرفة</h3></div>
        <div class="p-table-scroll"><table class="p-table" id="lnTable">
            <thead><tr>
                <th>السؤال</th><th>اللغة</th><th>سبب التحويل</th><th>عدد المرات</th><th>الحالة</th><th>آخر ظهور</th><th></th>
            </tr></thead>
            <tbody><tr class="p-loading-row"><td colspan="7">جاري التحميل...</td></tr></tbody>
        </table></div>
    </div>
</div>
