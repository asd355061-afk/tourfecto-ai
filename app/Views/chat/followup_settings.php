<?php
/**
 * chat/followup_settings — إعدادات المتابعة التلقائية.
 */
?>
{ICON_SPRITE}
<div class="ch-toolbar">
    <a href="/chat" class="p-btn outline xs">{IC_INBOX}صندوق الوارد</a>
</div>
<div id="fuNoWebsite" class="ch-card" style="display:none;">
    <div class="ch-empty"><div class="ch-empty-icon">{IC_GLOBE}</div><div class="ch-empty-sub">اختر موقعًا من القائمة أعلى الصفحة أولًا.</div></div>
</div>
<div id="fuBody" style="display:none;">
    <div class="ch-card" style="margin-bottom:14px;">
        <div class="ch-card-head"><h3 class="ch-card-title">⏰ المتابعة التلقائية</h3><span class="ch-card-sub">لو العميل سأل ثم اختفى، النظام يقدر يبعتله متابعة تلقائية حسب الخطوات تحت</span></div>
        <div class="ch-card-body ch-form">
            <div class="form-group">
                <label class="ch-toggle"><input type="checkbox" id="fuEnabled"><span class="ch-toggle-track"></span><span>تفعيل المتابعة التلقائية لهذا الموقع</span></label>
            </div>
            <div class="form-group">
                <label class="form-label">الحد الأقصى لعدد المتابعات لكل عميل</label>
                <input type="number" id="fuMax" class="form-control" min="1" max="10" style="max-width:120px;">
            </div>
        </div>
    </div>
    <div class="ch-card" style="margin-bottom:14px;">
        <div class="ch-card-head"><h3 class="ch-card-title">خطوات المتابعة</h3></div>
        <div class="ch-card-body">
            <div id="fuSteps" class="ch-steps"></div>
            <button class="p-btn outline xs" style="margin-top:10px;" onclick="fuAddStep()">➕ إضافة خطوة</button>
        </div>
    </div>
    <button class="p-btn primary" onclick="fuSave()">💾 حفظ الإعدادات</button>
</div>
