<?php
/**
 * chat/conversation — صفحة محادثة واحدة داخل Unified Inbox.
 * المتغيرات: $conversationId, $currentUserId
 */
?>
{ICON_SPRITE}
<div id="loadingConv" class="ch-empty"><div class="ch-empty-icon">{IC_CLOCK}</div><div class="ch-empty-title">جاري تحميل المحادثة...</div></div>
<div id="convNotFound" class="ch-empty" style="display:none;"><div class="ch-empty-icon">{IC_ALERT}</div><div class="ch-empty-title">المحادثة غير موجودة</div><div class="ch-empty-sub">المحادثة غير موجودة أو مش تابعة للموقع الحالي.</div></div>

<div id="convBody" style="display:none;" data-user-id="<?= (int) $currentUserId ?>" data-conversation-id="<?= (int) $conversationId ?>">
    <div class="ch-card" id="convHeader" style="margin-bottom:14px;"></div>

    <div class="ch-split">
        <div class="ch-split-main">
            <div class="ch-card ch-thread" id="convThread" style="max-height:480px;overflow-y:auto;"></div>

            <div class="ch-card" style="margin-top:14px;">
                <div class="ch-card-head"><h3 class="ch-card-title">الرد</h3></div>
                <div class="ch-card-body ch-composer">
                    <div id="aiSuggestions" style="display:none;margin-bottom:10px;"></div>
                    <div class="form-group">
                        <textarea id="manualMessage" class="form-control" rows="3" placeholder="اكتب ردك هنا..."></textarea>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button class="p-btn primary" id="sendManualBtn" onclick="sendManual()">➤ إرسال</button>
                        <button class="p-btn outline" id="suggestBtn" onclick="loadSuggestions()">💡 اقتراح رد AI</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="ch-split-side">
            <div class="ch-card" id="leadPanel" style="margin-bottom:14px;"></div>
            <div class="ch-card">
                <div class="ch-card-head"><h3 class="ch-card-title">ملاحظات وصفقات</h3></div>
                <div class="ch-empty" style="padding:16px 0;">
                    <div class="ch-empty-icon">🧩</div>
                    ميزة الملاحظات والصفقات المرتبطة غير متاحة حاليًا في AI Chat Backend.
                </div>
            </div>
        </div>
    </div>
</div>
