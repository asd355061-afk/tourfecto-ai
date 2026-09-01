<?php
/**
 * chat/index — Unified AI Chat Inbox (بند 1، 15، 16 من AI Chat Backend).
 * المتغيرات: $currentUserId
 */
?>
{ICON_SPRITE}
<div class="ch-toolbar">
    <div class="ch-search">
        {IC_SEARCH}
        <input type="text" id="ucSearch" class="form-control" placeholder="ابحث بالاسم أو الهاتف أو الإيميل...">
    </div>
    <select id="ucStatus" class="p-select">
        <option value="">كل الحالات</option>
        <option value="open">مفتوحة</option>
        <option value="pending">قيد الانتظار</option>
        <option value="resolved">تم الحل</option>
        <option value="closed">مغلقة</option>
    </select>
    <select id="ucAiStatus" class="p-select">
        <option value="">AI أو موظف</option>
        <option value="ai">🤖 الذكاء الاصطناعي</option>
        <option value="human">👤 موظف</option>
        <option value="paused">⏸ متوقف</option>
    </select>
    <select id="ucLeadStatus" class="p-select">
        <option value="">كل حالات Lead</option>
        <option value="new_inquiry">استفسار جديد</option>
        <option value="qualifying">قيد التأهيل</option>
        <option value="qualified">مؤهّل</option>
        <option value="hot_lead">🔥 Lead ساخن</option>
        <option value="converted">تم التحويل</option>
        <option value="lost">فاقد</option>
    </select>
    <select id="ucChannel" class="p-select">
        <option value="">كل القنوات</option>
        <option value="whatsapp">واتساب</option>
        <option value="website_chat">شات الموقع</option>
        <option value="messenger">Messenger</option>
        <option value="instagram">Instagram</option>
        <option value="email">إيميل</option>
    </select>
    <select id="ucTag" class="p-select">
        <option value="">كل الوسوم</option>
        <option value="HOT_LEAD">HOT_LEAD</option>
        <option value="NEW_INQUIRY">NEW_INQUIRY</option>
        <option value="PRICE_REQUEST">PRICE_REQUEST</option>
        <option value="COMPLAINT">COMPLAINT</option>
        <option value="FOLLOW_UP">FOLLOW_UP</option>
        <option value="BOOKING_INTENT">BOOKING_INTENT</option>
        <option value="VIP">VIP</option>
        <option value="HUMAN_REQUIRED">HUMAN_REQUIRED</option>
    </select>
    <button class="p-btn outline xs" onclick="ucApplyFilters()">{IC_SEARCH}بحث</button>
    <span class="ch-toolbar-spacer"></span>
    <a href="/chat/pending" class="p-btn outline xs">{IC_CLOCK}المعلّقة</a>
    <a href="/chat/learning" class="p-btn outline xs">{IC_SPARKLES}الفجوات</a>
    <a href="/chat/knowledge-base" class="p-btn outline xs">{IC_BOOK}المعرفة</a>
    <a href="/chat/analytics" class="p-btn outline xs">{IC_CHART}التحليلات</a>
    <a href="/chat/settings" class="p-btn primary xs">{IC_GEAR}الإعدادات</a>
</div>

<div id="ucNoWebsite" class="ch-card" style="display:none;">
    <div class="ch-empty"><div class="ch-empty-icon">{IC_GLOBE}</div><div class="ch-empty-title">اختر موقعًا أولًا</div><div class="ch-empty-sub">اختر موقعًا من القائمة أعلى الصفحة أولًا لعرض محادثاته.</div></div>
</div>

<div class="ch-inbox-split" id="ucBody" style="display:none;" data-user-id="<?= (int) $currentUserId ?>">
    <div class="ch-card ch-inbox-card">
        <div class="ch-card-head">
            <h3 class="ch-card-title">{IC_INBOX} المحادثات</h3>
            <span class="ch-card-spacer"></span>
            <span class="p-card-sub" id="ucCount"></span>
        </div>
        <div class="ai-chat-list" id="ucList">
            <div class="ch-empty" style="padding:26px 0;"><div class="ch-empty-icon">{IC_CLOCK}</div>جاري التحميل...</div>
        </div>
    </div>

    <div class="ch-inbox-main">
        <div id="ucEmptyState" class="ch-card">
            <div class="ch-empty"><div class="ch-empty-icon">{IC_CHAT}</div><div class="ch-empty-title">اختر محادثة</div><div class="ch-empty-sub">اختر محادثة من القائمة لعرضها هنا</div></div>
        </div>

        <div id="ucThreadPanel" style="display:none;">
            <div class="ch-card" id="convHeader" style="margin-bottom:14px;"></div>
            <div class="ch-card" id="leadPanel" style="margin-bottom:14px;"></div>
            <div class="ch-card ch-thread" id="convThread" style="max-height:calc(100vh - 420px);min-height:260px;overflow-y:auto;"></div>
            <div class="ch-card" style="margin-top:14px;">
                <div class="ch-card-head">
                    <h3 class="ch-card-title">{IC_SEND} الرد</h3>
                    <span class="ch-card-spacer"></span>
                    <button class="p-btn outline xs" onclick="quoteToggleComposer()">{IC_WALLET}عرض سعر</button>
                </div>
                <div class="ch-card-body ch-composer">
                    <div id="quoteComposer" style="display:none;margin-bottom:12px;"></div>
                    <div id="quoteList" style="display:none;margin-bottom:12px;"></div>
                    <div id="aiSuggestions" style="display:none;margin-bottom:10px;"></div>
                    <div class="form-group">
                        <textarea id="manualMessage" class="form-control" rows="3" placeholder="اكتب ردك هنا..."></textarea>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button class="p-btn primary" id="sendManualBtn" onclick="sendManual()">{IC_SEND}إرسال</button>
                        <button class="p-btn outline" id="suggestBtn" onclick="loadSuggestions()">{IC_SPARKLES}اقتراح رد AI</button>
                        <div style="flex:1;"></div>
                        <a href="/chat/leads" class="p-btn outline xs" style="align-self:center;">{IC_TARGET}عرض الـLeads</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
