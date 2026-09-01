<?php
include __DIR__ . '/_tabs.php';
?>

<div class="p-card ads-card">
    <div class="p-card-head">
        <h3>👥 إدارة الفريق</h3>
        <span class="p-card-sub">أضف زملاء عندهم حساب Tourfecto بالفعل، وحدّد صلاحياتهم على موديول الإعلانات بتاعك</span>
    </div>
    <div class="ads-grid-2" style="margin-bottom:14px;gap:8px;">
        <input type="email" id="newMemberEmail" class="p-select" placeholder="إيميل العضو (لازم يكون مسجّل في Tourfecto)">
        <select id="newMemberRole" class="p-select">
            <option value="viewer">Viewer - عرض فقط</option>
            <option value="manager">Manager - إدارة الحملات</option>
            <option value="admin">Admin - كل الصلاحيات</option>
        </select>
    </div>
    <button type="button" class="p-btn primary" onclick="addTeamMember()">+ إضافة</button>
    <div id="teamMembersBox" style="margin-top:14px;"><div class="p-loading-row">جارِ التحميل...</div></div>
</div>

<div class="p-card" id="belongToCard" style="display:none;">
    <div class="p-card-head"><h3>🔗 حسابات أنا عضو فيها</h3></div>
    <div id="belongToBox"></div>
</div>
