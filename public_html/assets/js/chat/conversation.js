(function () {
    const P = window.Panel;

    function ic(name, cls) {
        return '<svg class="ic ' + (cls || '') + '" aria-hidden="true"><use href="#i-' + name + '"/></svg>';
    }
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    const conversationId = parseInt(document.getElementById('convBody').dataset.conversationId, 10) || 0;
    const currentUserId = parseInt(document.getElementById('convBody').dataset.userId, 10) || 0;
    let websiteId = null;
    let currentConversation = null;

    const STATUS_OPTIONS = [
        ['open', 'مفتوحة'], ['pending', 'قيد الانتظار'], ['resolved', 'تم الحل'], ['closed', 'مغلقة'],
    ];
    const PRIORITY_OPTIONS = [
        ['low', 'منخفضة'], ['normal', 'عادية'], ['high', 'عالية'], ['urgent', 'عاجلة'],
    ];
    const STANDARD_TAGS = ['HOT_LEAD', 'NEW_INQUIRY', 'PRICE_REQUEST', 'COMPLAINT', 'FOLLOW_UP', 'BOOKING_INTENT', 'VIP', 'HUMAN_REQUIRED'];
    const CHANNEL_LABEL = {
        whatsapp: '📱 واتساب', website_chat: '🌐 شات الموقع', webchat: '🌐 شات الموقع',
        messenger: '📘 Messenger', instagram: '📷 Instagram', email: '✉️ إيميل',
    };

    if (!conversationId) {
        document.getElementById('loadingConv').style.display = 'none';
        document.getElementById('convNotFound').style.display = 'block';
        return;
    }

    window.toggleHandoff = async function () {
        const isAi = currentConversation.ai_status === 'ai';
        const url = '/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId + (isAi ? '/handoff' : '/resume-ai');
        const res = await fetchJSON(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: isAi ? JSON.stringify({ reason: 'manual_takeover' }) : null,
        });
        if (res.success) { toast(isAi ? 'تم تحويل المحادثة لك' : 'تم استرجاع الرد الآلي', 'success'); load(); }
        else { toast(res.error || 'فشلت العملية', 'error'); }
    };

    window.assignToggle = async function () {
        const isMine = currentConversation.assigned_agent_id == currentUserId;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ assigned_agent_id: isMine ? null : currentUserId }),
        });
        if (res.success) { toast(isMine ? 'تم إلغاء التعيين' : 'تم تعيين المحادثة لك', 'success'); load(); }
        else { toast(res.error || 'فشلت العملية', 'error'); }
    };

    window.updateField = async function (field, value) {
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ [field]: value }),
        });
        if (res.success) { toast('تم التحديث', 'success'); load(); }
        else { toast(res.error || 'فشل التحديث', 'error'); }
    };

    window.toggleTag = async function (tag) {
        const tags = currentConversation.tags || [];
        const has = tags.includes(tag);
        const newTags = has ? tags.filter(t => t !== tag) : tags.concat([tag]);
        await updateField('tags', newTags);
    };

    window.sendManual = async function () {
        const message = document.getElementById('manualMessage').value.trim();
        if (!message) { toast('اكتب رسالة أولاً', 'error'); return; }
        const btn = document.getElementById('sendManualBtn');
        btn.disabled = true;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId + '/reply', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: message }),
        });
        btn.disabled = false;
        if (res.success) {
            toast(res.data && res.data.sent === false ? 'اتحفظت الرسالة لكن فشل الإرسال الفعلي للعميل' : 'تم الإرسال', res.data && res.data.sent === false ? 'error' : 'success');
            document.getElementById('manualMessage').value = '';
            load();
        } else {
            toast(res.error || 'فشل الإرسال', 'error');
        }
    };

    window.loadSuggestions = async function () {
        const box = document.getElementById('aiSuggestions');
        const btn = document.getElementById('suggestBtn');
        btn.disabled = true;
        box.style.display = 'block';
        box.innerHTML = '<div class="p-cell-muted">🤖 جاري توليد اقتراحات...</div>';

        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId + '/reply-suggestions');
        btn.disabled = false;

        if (!res.success || !res.data || !Array.isArray(res.data.suggestions) || !res.data.suggestions.length) {
            box.innerHTML = '<div class="p-cell-muted">⚠️ ' + esc((res.data && res.data.error) || res.error || 'لا توجد اقتراحات متاحة الآن') + '</div>';
            return;
        }

        box.innerHTML = res.data.suggestions.map((s, i) => `
            <div class="p-card" style="padding:10px;margin-bottom:6px;cursor:pointer;" onclick="document.getElementById('manualMessage').value = this.dataset.text;">
                <span class="pill blue">اقتراح ${i + 1}</span>
                <span data-text="${esc(s).replace(/"/g, '&quot;')}" style="display:block;margin-top:6px;">${esc(s)}</span>
            </div>`).join('');
    };

    function renderHeader(c) {
        const customer = c.customer_name || c.customer_phone || c.customer_email || 'عميل غير معروف';
        const isAi = c.ai_status === 'ai';
        const isMine = c.assigned_agent_id == currentUserId;

        const tagsHtml = STANDARD_TAGS.map(t => {
            const active = (c.tags || []).includes(t);
            return `<span class="pill ${active ? 'blue' : 'gray'}" style="cursor:pointer;" onclick="toggleTag('${t}')">${active ? '✓ ' : ''}${t}</span>`;
        }).join(' ');

        const statusSelect = '<select class="p-select" onchange="updateField(\'status\', this.value)">' +
            STATUS_OPTIONS.map(([v, l]) => `<option value="${v}" ${c.status === v ? 'selected' : ''}>${l}</option>`).join('') + '</select>';
        const prioritySelect = '<select class="p-select" onchange="updateField(\'priority\', this.value)">' +
            PRIORITY_OPTIONS.map(([v, l]) => `<option value="${v}" ${c.priority === v ? 'selected' : ''}>${l}</option>`).join('') + '</select>';

        document.getElementById('convHeader').innerHTML = `
            <div class="p-card-head">
                <h3>${esc(customer)} ${CHANNEL_LABEL[c.channel] || esc(c.channel || '')}</h3>
                <span class="p-cell-muted">${esc(c.customer_phone || c.customer_email || '')}</span>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">
                ${isAi ? '<span class="pill green">🤖 يرد الآن: الذكاء الاصطناعي</span>' : '<span class="pill">👤 يرد الآن: موظف</span>'}
                <button class="p-btn ${isAi ? 'outline' : 'primary'} xs" onclick="toggleHandoff()">${isAi ? '⇄ تحويل لموظف' : '⇄ استرجاع الرد الآلي'}</button>
                <button class="p-btn outline xs" onclick="assignToggle()">${isMine ? '✖ إلغاء التعيين مني' : '👤 تعيين لي'}</button>
                ${statusSelect}
                ${prioritySelect}
                ${c.ai_confidence_score !== null && c.ai_confidence_score !== undefined ? '<span class="pill">ثقة AI: ' + Math.round(c.ai_confidence_score * 100) + '%</span>' : ''}
            </div>
            <div style="margin-bottom:8px;">${tagsHtml}</div>
            ${c.ai_summary ? '<div class="p-card" style="background:var(--panel-bg,#f7f8fa);padding:10px 14px;"><strong>ملخص AI:</strong> ' + esc(c.ai_summary) + '</div>' : ''}
        `;
    }

    function renderThread(messages) {
        const thread = document.getElementById('convThread');
        if (!messages.length) {
            thread.innerHTML = '<div class="p-empty"><div class="p-empty-icon">💬</div>لا توجد رسائل في هذه المحادثة بعد</div>';
            return;
        }
        thread.innerHTML = messages.map(m => {
            const mine = m.message_direction === 'outgoing';
            return `
                <div style="max-width:70%;margin:${mine ? '8px 0 8px auto' : '8px auto 8px 0'};padding:10px 14px;border-radius:12px;background:${mine ? 'var(--panel-accent)' : 'var(--panel-card-bg,#f1f2f4)'};color:${mine ? '#fff' : 'inherit'};">
                    <div>${esc(m.message_text || m.ai_reply_generated || '')}</div>
                    <div style="font-size:11px;opacity:.7;margin-top:4px;">${formatDate(m.sent_at || m.created_at)}</div>
                </div>`;
        }).join('');
        thread.scrollTop = thread.scrollHeight;
    }

    function renderLeadPanel(leads) {
        const panel = document.getElementById('leadPanel');
        const lead = (leads && leads.length) ? leads[0] : null;
        if (!lead) {
            panel.innerHTML = '<div class="p-card-head"><h3>معلومات Lead</h3></div><div class="p-empty" style="padding:16px 0;"><div class="p-empty-icon">📋</div>لا يوجد Lead مرتبط بهذه المحادثة بعد.</div>';
            return;
        }
        panel.innerHTML = `
            <div class="p-card-head"><h3>معلومات Lead</h3></div>
            <div class="p-kv"><span class="k">الدرجة</span><span class="v">${lead.lead_score ?? '-'} / 100</span></div>
            <div class="p-kv"><span class="k">نية الشراء</span><span class="v">${lead.intent_score ?? '-'} / 100</span></div>
            <div class="p-kv"><span class="k">الوجهة</span><span class="v">${esc(lead.destination || '-')}</span></div>
            <div class="p-kv"><span class="k">الاهتمام</span><span class="v">${esc(lead.interest || '-')}</span></div>
            <div class="p-kv"><span class="k">الحالة</span><span class="v">${esc(lead.status || '-')}</span></div>
            ${lead.next_recommended_action ? '<div style="margin-top:10px;padding:10px;background:var(--panel-bg,#f7f8fa);border-radius:8px;"><strong>الخطوة التالية المقترحة:</strong><br>' + esc(lead.next_recommended_action) + '</div>' : ''}
            <a href="/chat/leads" class="p-btn outline xs" style="margin-top:10px;display:inline-block;">عرض كل الـLeads</a>
        `;
    }

    async function load() {
        websiteId = P.getCurrentWebsiteId();
        if (!websiteId) {
            document.getElementById('loadingConv').style.display = 'none';
            document.getElementById('convNotFound').style.display = 'block';
            document.getElementById('convNotFound').innerHTML = '<div class="p-empty-icon">🌐</div>اختر موقعًا من القائمة أعلى الصفحة أولًا.';
            return;
        }

        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId);
        if (!res.success || !res.data || !res.data.conversation) {
            document.getElementById('loadingConv').style.display = 'none';
            document.getElementById('convNotFound').style.display = 'block';
            return;
        }

        currentConversation = res.data.conversation;
        document.getElementById('loadingConv').style.display = 'none';
        document.getElementById('convNotFound').style.display = 'none';
        document.getElementById('convBody').style.display = 'block';

        renderHeader(currentConversation);
        renderThread(res.data.messages || []);

        fetchJSON('/api/ai-chat/websites/' + websiteId + '/leads?conversation_id=' + conversationId).then(leadRes => {
            renderLeadPanel(leadRes.success ? leadRes.data.leads : []);
        });
    }

    load();
    setInterval(load, 20000);
})();
