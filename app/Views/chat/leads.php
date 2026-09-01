<?php
/**
 * chat/leads — جدول الـ Leads المرتبطة بالمحادثات.
 */
?>
{ICON_SPRITE}
<div class="ch-toolbar">
    <a href="/chat" class="p-btn outline xs">{IC_INBOX}صندوق الوارد</a>
    <span class="ch-toolbar-spacer"></span>
    <select id="ldStatus" class="p-select" onchange="load()">
        <option value="">كل الحالات</option>
        <option value="new">جديد</option>
        <option value="contacted">تم التواصل</option>
        <option value="qualified">مؤهّل</option>
        <option value="proposal_sent">تم إرسال عرض سعر</option>
        <option value="won">تم الفوز به</option>
        <option value="lost">فاقد</option>
    </select>
</div>
<div id="ldNoWebsite" class="ch-card" style="display:none;">
    <div class="ch-empty"><div class="ch-empty-icon">{IC_GLOBE}</div><div class="ch-empty-sub">اختر موقعًا من القائمة أعلى الصفحة أولًا.</div></div>
</div>
<div class="ch-card no-pad" id="ldTableWrap">
    <div class="p-table-scroll"><table class="p-table" id="leadsTable">
        <thead><tr>
            <th>العميل</th><th>القناة</th><th>الاهتمام</th><th>الوجهة</th>
            <th>Lead Score</th><th>النية</th><th>الحالة</th><th>آخر تفاعل</th><th></th>
        </tr></thead>
        <tbody><tr class="p-loading-row"><td colspan="9">جاري التحميل...</td></tr></tbody>
    </table></div>
</div>
