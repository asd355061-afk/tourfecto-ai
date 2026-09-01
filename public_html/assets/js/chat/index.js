(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, timeAgo = P.timeAgo;
    const currentUserId = parseInt(document.getElementById('ucBody').dataset.userId, 10) || 0;
    let websiteId = null;
    let currentConversation = null;
    let activeConvId = null;

    const CHANNEL_LABEL = {
        whatsapp: 'واتساب', website_chat: 'شات الموقع', webchat: 'شات الموقع',
        messenger: 'Messenger', instagram: 'Instagram', email: 'إيميل',
    };
    const STATUS_OPTIONS = [
        ['open', 'مفتوحة'], ['pending', 'قيد الانتظار'], ['resolved', 'تم الحل'], ['closed', 'مغلقة'],
    ];
    const PRIORITY_OPTIONS = [
        ['low', 'منخفضة'], ['normal', 'عادية'], ['high', 'عالية'], ['urgent', 'عاجلة'],
    ];
    const STANDARD_TAGS = ['HOT_LEAD', 'NEW_INQUIRY', 'PRICE_REQUEST', 'COMPLAINT', 'FOLLOW_UP', 'BOOKING_INTENT', 'VIP', 'HUMAN_REQUIRED'];

    function ic(name, cls) {
        return '<svg class="ic ' + (cls || '') + '" aria-hidden="true"><use href="#i-' + name + '"/></svg>';
    }

    const QUOTE_STATUS_LABEL = {
        draft: 'مسودة', sent: 'مُرسل', accepted: 'مقبول', declined: 'مرفوض', expired: 'منتهي', cancelled: 'ملغي',
    };
    const QUOTE_STATUS_CLASS = {
        draft: 'gray', sent: 'blue', accepted: 'green', declined: 'red', expired: 'gray', cancelled: 'red',
    };
    let quoteItems = [];

    function ensureWebsiteSelected() {
        const id = P.getCurrentWebsiteId();
        document.getElementById('ucNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('ucBody').style.display = id ? 'grid' : 'none';
        return id;
    }

    function customerLabel(c) {
        return c.customer_name || c.customer_phone || c.customer_email || 'عميل غير معروف';
    }

    function avatarClass(ch) {
        return (ch === 'whatsapp' || ch === 'email') ? ' ' + ch : '';
    }

    function statusLine(c) {
        const parts = [];
        if (c.lead_status === 'hot_lead') parts.push('<span class="pill red" style="font-size:10px;">' + ic('fire', 'ic-sm') + '</span>');
        if (c.priority === 'urgent' || c.priority === 'high') parts.push('<span class="pill red" style="font-size:10px;">' + ic('flag', 'ic-sm') + '</span>');
        if (c.ai_status === 'ai') parts.push('<span class="pill green" style="font-size:10px;">' + ic('sparkles', 'ic-sm') + ' AI</span>');
        else if (c.ai_status === 'paused') parts.push('<span class="pill red" style="font-size:10px;">' + ic('pause', 'ic-sm') + '</span>');
        return parts.join(' ');
    }

    window.ucApplyFilters = function () { loadList(); };

    async function loadList() {
        const id = ensureWebsiteSelected();
        if (!id) return;

        const qs = new URLSearchParams();
        const search = document.getElementById('ucSearch').value.trim();
        const status = document.getElementById('ucStatus').value;
        const aiStatus = document.getElementById('ucAiStatus').value;
        const leadStatus = document.getElementById('ucLeadStatus').value;
        const channel = document.getElementById('ucChannel').value;
        const tag = document.getElementById('ucTag').value;
        if (search) qs.set('search', search);
        if (status) qs.set('status', status);
        if (aiStatus) qs.set('ai_status', aiStatus);
        if (leadStatus) qs.set('lead_status', leadStatus);
        if (channel) qs.set('channel', channel);
        if (tag) qs.set('tag', tag);

        const listEl = document.getElementById('ucList');
        listEl.innerHTML = '<div class="p-empty" style="padding:26px 0;"><div class="p-empty-icon">' + ic('clock') + '</div>جاري التحميل...</div>';

        const res = await fetchJSON('/api/ai-chat/websites/' + encodeURIComponent(id) + '/conversations?' + qs.toString());
        if (!res.success) {
            listEl.innerHTML = '<div class="p-empty"><div class="p-empty-icon">' + ic('alert') + '</div>' + esc(res.error || 'تعذر تحميل المحادثات') + '</div>';
            return;
        }

        const list = (res.data && Array.isArray(res.data.conversations)) ? res.data.conversations : [];
        document.getElementById('ucCount').textContent = list.length + ' محادثة';
        if (!list.length) {
            listEl.innerHTML = '<div class="p-empty" style="padding:26px 0;"><div class="p-empty-icon">' + ic('inbox') + '</div>لا توجد محادثات تطابق الفلاتر</div>';
            return;
        }

        listEl.innerHTML = list.map(c => {
            const customer = customerLabel(c);
            const initial = (customer || '?').trim().charAt(0).toUpperCase();
            const active = c.id === activeConvId ? ' active' : '';
            return `
                <div class="ai-chat-item${active}" data-id="${c.id}" onclick="window.selectConversation(${c.id})">
                    <div class="r1">
                        <div class="ai-chat-avatar${avatarClass(c.channel)}">${esc(initial)}</div>
                        <div class="nm">
                            <b>${esc(customer)} ${c.unread_count > 0 ? '<span class="ub">' + c.unread_count + '</span>' : ''}</b>
                            <small>${CHANNEL_LABEL[c.channel] || esc(c.channel || '-')} · ${esc(c.customer_phone || c.customer_email || '-')}</small>
                        </div>
                        <div class="ch">${statusLine(c)}</div>
                    </div>
                    <div class="r1" style="margin-top:6px;">
                        <small class="tm" style="flex:1;">${timeAgo(c.last_message_at)}</small>
                    </div>
                </div>`;
        }).join('');
    }

    window.selectConversation = async function (id) {
        if (!websiteId) websiteId = P.getCurrentWebsiteId();
        if (!websiteId) return;
        activeConvId = id;
        document.querySelectorAll('.ai-chat-item').forEach(el => {
            el.classList.toggle('active', el.dataset.id === String(id));
        });

        document.getElementById('ucEmptyState').style.display = 'none';
        document.getElementById('ucThreadPanel').style.display = 'block';
        document.getElementById('convHeader').innerHTML = '<div class="p-empty" style="padding:20px 0;"><div class="p-empty-icon">' + ic('clock') + '</div>جاري تحميل المحادثة...</div>';
        document.getElementById('convThread').innerHTML = '';

        const res = await fetchJSON('/api/ai-chat/websites/' + encodeURIComponent(websiteId) + '/conversations/' + id);
        if (!res.success || !res.data || !res.data.conversation) {
            toast(res.error || 'تعذر تحميل المحادثة', 'error');
            return;
        }

        currentConversation = res.data.conversation;
        renderHeader(currentConversation);
        renderThread(res.data.messages || []);

        const leadRes = await fetchJSON('/api/ai-chat/websites/' + encodeURIComponent(websiteId) + '/leads?conversation_id=' + id);
        renderLeadPanel(leadRes.success ? leadRes.data.leads : []);
        quoteLoad();
    };

    window.toggleHandoff = async function () {
        const isAi = currentConversation.ai_status === 'ai';
        const url = '/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id + (isAi ? '/handoff' : '/resume-ai');
        const res = await fetchJSON(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: isAi ? JSON.stringify({ reason: 'manual_takeover' }) : null,
        });
        if (res.success) { toast(isAi ? 'تم تحويل المحادثة لك' : 'تم استرجاع الرد الآلي', 'success'); loadList(); selectConversation(currentConversation.id); }
        else { toast(res.error || 'فشلت العملية', 'error'); }
    };

    window.assignToggle = async function () {
        const isMine = currentConversation.assigned_agent_id == currentUserId;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ assigned_agent_id: isMine ? null : currentUserId }),
        });
        if (res.success) { toast(isMine ? 'تم إلغاء التعيين' : 'تم تعيين المحادثة لك', 'success'); selectConversation(currentConversation.id); }
        else { toast(res.error || 'فشلت العملية', 'error'); }
    };

    window.updateField = async function (field, value) {
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ [field]: value }),
        });
        if (res.success) { toast('تم التحديث', 'success'); loadList(); }
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
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id + '/reply', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: message }),
        });
        btn.disabled = false;
        if (res.success) {
            toast(res.data && res.data.sent === false ? 'اتحفظت الرسالة لكن فشل الإرسال الفعلي للعميل' : 'تم الإرسال', res.data && res.data.sent === false ? 'error' : 'success');
            document.getElementById('manualMessage').value = '';
            selectConversation(currentConversation.id);
        } else {
            toast(res.error || 'فشل الإرسال', 'error');
        }
    };

    window.loadSuggestions = async function () {
        const box = document.getElementById('aiSuggestions');
        const btn = document.getElementById('suggestBtn');
        btn.disabled = true;
        box.style.display = 'block';
        box.innerHTML = '<div class="p-cell-muted">' + ic('sparkles', 'ic-sm') + ' جاري توليد اقتراحات...</div>';

        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id + '/reply-suggestions');
        btn.disabled = false;

        if (!res.success || !res.data || !Array.isArray(res.data.suggestions) || !res.data.suggestions.length) {
            box.innerHTML = '<div class="p-cell-muted">' + ic('alert', 'ic-sm') + ' ' + esc((res.data && res.data.error) || res.error || 'لا توجد اقتراحات متاحة الآن') + '</div>';
            return;
        }

        box.innerHTML = res.data.suggestions.map((s, i) => `
            <div class="p-card ai-sugg" style="padding:10px 12px;margin-bottom:6px;cursor:pointer;" onclick="document.getElementById('manualMessage').value = this.dataset.text;">
                <span class="pill blue">اقتراح ${i + 1}</span>
                <span data-text="${esc(s).replace(/"/g, '&quot;')}" style="display:block;margin-top:6px;color:var(--panel-text);">${esc(s)}</span>
            </div>`).join('');
    };

    function renderHeader(c) {
        const customer = customerLabel(c);
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

        const badges = [];
        if (c.language) badges.push('<span class="pill gray">' + ic('globe', 'ic-sm') + ' ' + esc(c.language === 'ar' ? 'عربي' : 'English') + '</span>');
        if (c.ai_confidence_score !== null && c.ai_confidence_score !== undefined) {
            badges.push('<span class="pill ' + (c.ai_confidence_score >= 0.7 ? 'green' : (c.ai_confidence_score >= 0.4 ? '' : 'red')) + '">' + ic('sparkles', 'ic-sm') + ' ثقة AI: ' + Math.round(c.ai_confidence_score * 100) + '%</span>');
        }

        document.getElementById('convHeader').innerHTML = `
            <div class="p-card-head">
                <h3>${esc(customer)} <span class="pill gray">${CHANNEL_LABEL[c.channel] || esc(c.channel || '')}</span></h3>
                <span class="p-card-sub">${esc(c.customer_phone || c.customer_email || '')}</span>
            </div>
            <div class="ai-conv-head">
                ${isAi ? '<span class="pill green">' + ic('sparkles', 'ic-sm') + ' يرد الآن: الذكاء الاصطناعي</span>' : '<span class="pill">' + ic('user', 'ic-sm') + ' يرد الآن: موظف</span>'}
                <button class="p-btn ${isAi ? 'outline' : 'primary'} xs" onclick="toggleHandoff()">${ic('handoff')}${isAi ? 'تحويل لموظف' : 'استرجاع الرد الآلي'}</button>
                <button class="p-btn outline xs" onclick="assignToggle()">${isMine ? ic('x') + 'إلغاء التعيين مني' : ic('user-plus') + 'تعيين لي'}</button>
                ${statusSelect}
                ${prioritySelect}
                ${badges.join('')}
            </div>
            <div style="margin:10px 0 4px;display:flex;flex-wrap:wrap;gap:4px;">${tagsHtml}</div>
            ${c.ai_summary ? '<div class="p-card" style="background:var(--panel-sidebar-bg-hover);padding:10px 14px;margin-top:8px;"><strong>' + ic('sparkles', 'ic-sm') + ' ملخص AI:</strong> ' + esc(c.ai_summary) + '</div>' : ''}
        `;
    }

    function renderThread(messages) {
        const thread = document.getElementById('convThread');
        if (!messages.length) {
            thread.innerHTML = '<div class="p-empty"><div class="p-empty-icon">' + ic('chat') + '</div>لا توجد رسائل في هذه المحادثة بعد</div>';
            return;
        }
        thread.innerHTML = '<div class="ai-chat-bubbles">' + messages.map(m => {
            const mine = m.message_direction === 'outgoing';
            const text = m.message_text || m.ai_reply_generated || '';
            const tag = (m.ai_reply_generated && !mine) ? '<span class="ai-tag">' + ic('sparkles', 'ic-sm') + ' رد تلقائي' + (m.ai_confidence_score != null ? ' · ' + Math.round(m.ai_confidence_score * 100) + '%' : '') + '</span>' : '';
            return `
                <div class="ai-bubble ${mine ? 'out' : 'in'}">
                    ${tag}
                    <span>${esc(text)}</span>
                    <div class="bt">${P.formatDate(m.sent_at || m.created_at)}</div>
                </div>`;
        }).join('') + '</div>';
        thread.scrollTop = thread.scrollHeight;
    }

    function renderLeadPanel(leads) {
        const panel = document.getElementById('leadPanel');
        if (!panel) return;
        const lead = (leads && leads.length) ? leads[0] : null;
        if (!lead) {
            panel.style.display = 'none';
            return;
        }
        panel.style.display = 'block';
        panel.innerHTML = `
            <div class="p-card-head"><h3>${ic('target')} معلومات Lead</h3></div>
            <div class="p-kv"><span class="k">الدرجة</span><span class="v">${lead.lead_score ?? '-'} / 100</span></div>
            <div class="p-kv"><span class="k">نية الشراء</span><span class="v">${lead.intent_score ?? '-'} / 100</span></div>
            <div class="p-kv"><span class="k">الوجهة</span><span class="v">${esc(lead.destination || '-')}</span></div>
            <div class="p-kv"><span class="k">الاهتمام</span><span class="v">${esc(lead.interest || '-')}</span></div>
            <div class="p-kv"><span class="k">الحالة</span><span class="v">${esc(lead.status || '-')}</span></div>
            ${lead.next_recommended_action ? '<div style="margin-top:10px;padding:10px;background:var(--panel-sidebar-bg-hover);border-radius:8px;"><strong>الخطوة التالية المقترحة:</strong><br>' + esc(lead.next_recommended_action) + '</div>' : ''}
        `;
    }

    async function refreshActive() {
        if (activeConvId) selectConversation(activeConvId);
    }

    // ===== In-Chat Quotes (بيع داخل الشات) =====
    function quoteBase() {
        return '/api/ai-chat/websites/' + websiteId + '/quotes';
    }

    window.quoteToggleComposer = function () {
        const el = document.getElementById('quoteComposer');
        el.style.display = el.style.display === 'none' ? 'block' : 'none';
        if (el.style.display === 'block') {
            quoteItems = [];
            quoteRenderComposer();
        }
    };

    window.quoteAddItem = function () {
        quoteItems.push({ name: '', qty: 1, unit_price: 0 });
        quoteRenderComposer();
    };

    window.quoteRemoveItem = function (i) {
        quoteItems.splice(i, 1);
        quoteRenderComposer();
    };

    window.quoteField = function (i, field, value) {
        quoteItems[i][field] = field === 'qty' ? Math.max(1, parseInt(value || '1', 10) || 1) : (field === 'unit_price' ? (parseFloat(value) || 0) : value);
        quoteRenderTotals();
    };

    function quoteSubtotal() {
        return quoteItems.reduce((sum, it) => sum + ((it.unit_price || 0) * (it.qty || 1)), 0);
    }

    function quoteRenderTotals() {
        const sub = quoteSubtotal();
        const disc = parseFloat(document.getElementById('qDiscount')?.value || '0') || 0;
        const el = document.getElementById('qTotals');
        if (el) el.textContent = (sub - Math.max(0, disc)).toFixed(2) + ' ' + (document.getElementById('qCurrency')?.value || 'USD');
    }

    function quoteRenderComposer() {
        const el = document.getElementById('quoteComposer');
        if (quoteItems.length === 0) quoteItems.push({ name: '', qty: 1, unit_price: 0 });
        el.innerHTML = `
            <div class="p-card" style="padding:12px;border:1px solid var(--panel-accent);">
                <div class="p-card-head"><h3>${ic('wallet')} عرض سعر جديد</h3></div>
                ${quoteItems.map((it, i) => `
                    <div class="q-row">
                        <input class="form-control" placeholder="اسم الخدمة/البند" value="${esc(it.name)}" oninput="quoteField(${i},'name',this.value)">
                        <input class="form-control" type="number" min="1" value="${it.qty}" oninput="quoteField(${i},'qty',this.value)" title="الكمية">
                        <input class="form-control" type="number" min="0" step="0.01" value="${it.unit_price}" oninput="quoteField(${i},'unit_price',this.value)" title="سعر الوحدة">
                        <button class="p-btn outline xs q-del" onclick="quoteRemoveItem(${i})">${ic('trash')}</button>
                    </div>`).join('')}
                <button class="p-btn outline xs" onclick="quoteAddItem()">${ic('plus')} إضافة بند</button>
                <div class="form-group" style="margin-top:10px;">
                    <label>الخصم</label>
                    <input class="form-control" id="qDiscount" type="number" min="0" step="0.01" value="0" oninput="quoteRenderTotals()">
                </div>
                <div class="form-group">
                    <label>العملة</label>
                    <select class="p-select" id="qCurrency" onchange="quoteRenderTotals()">
                        <option value="USD">USD</option><option value="EGP">EGP</option><option value="EUR">EUR</option><option value="SAR">SAR</option><option value="AED">AED</option><option value="GBP">GBP</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>ملاحظات داخلية (اختياري)</label>
                    <textarea class="form-control" id="qNotes" rows="2" placeholder="أي ملاحظات للموظف..."></textarea>
                </div>
                <div style="display:flex;align-items:center;gap:10px;margin-top:10px;">
                    <strong>الإجمالي:</strong> <span id="qTotals">0.00 USD</span>
                    <div style="flex:1;"></div>
                    <button class="p-btn primary" onclick="quoteCreate()">${ic('check')} إنشاء العرض</button>
                </div>
            </div>`;
        quoteRenderTotals();
    }

    window.quoteCreate = async function () {
        const items = quoteItems
            .filter(it => (it.name || '').trim() !== '')
            .map(it => ({ name: it.name.trim(), qty: it.qty || 1, unit_price: it.unit_price || 0 }));
        if (!items.length) { toast('أضِف بندًا واحدًا على الأقل', 'error'); return; }
        const btn = event.target;
        btn.disabled = true;
        const res = await fetchJSON(quoteBase(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                conversation_id: currentConversation.id,
                items: items,
                discount: parseFloat(document.getElementById('qDiscount').value) || 0,
                currency: document.getElementById('qCurrency').value,
                notes: document.getElementById('qNotes').value.trim() || null,
            }),
        });
        btn.disabled = false;
        if (res.success) {
            toast('تم إنشاء عرض السعر', 'success');
            document.getElementById('quoteComposer').style.display = 'none';
            quoteItems = [];
            quoteLoad();
        } else {
            toast(res.error || 'فشل إنشاء عرض السعر', 'error');
        }
    };

    window.quoteLoad = async function () {
        if (!activeConvId) return;
        const res = await fetchJSON(quoteBase() + '?conversation_id=' + activeConvId);
        const wrap = document.getElementById('quoteList');
        if (!res.success || !res.data || !res.data.quotes.length) {
            wrap.style.display = 'none';
            return;
        }
        wrap.style.display = 'block';
        wrap.innerHTML = res.data.quotes.map(q => quoteCardHtml(q)).join('');
    };

    function quoteCardHtml(q) {
        const items = (q.items || []).map(it => `
            <div class="ai-quote-row"><span>${esc(it.name)} × ${it.qty}</span><span>${Number(it.line_total).toFixed(2)} ${esc(q.currency)}</span></div>`).join('');
        const actions = [];
        if (q.status === 'draft') {
            actions.push(`<button class="p-btn primary xs" onclick="quoteSend(${q.id})">${ic('send')} إرسال للعميل</button>`);
            actions.push(`<button class="p-btn outline xs" onclick="quoteSetStatus(${q.id},'cancelled')">${ic('x')} إلغاء</button>`);
        } else if (q.status === 'sent') {
            actions.push(`<button class="p-btn outline xs" onclick="quoteSetStatus(${q.id},'accepted')">${ic('check')} قبول</button>`);
            actions.push(`<button class="p-btn outline xs" onclick="quoteSetStatus(${q.id},'declined')">${ic('x')} رفض</button>`);
        }
        return `
            <div class="ai-quote-card">
                <div class="q-head">
                    <span><strong>${ic('wallet')} ${esc(q.quote_number || 'عرض سعر')}</strong></span>
                    <span class="pill ${QUOTE_STATUS_CLASS[q.status] || 'gray'}">${esc(QUOTE_STATUS_LABEL[q.status] || q.status)}</span>
                </div>
                <div style="padding:8px 0;">${items}</div>
                <div class="ai-quote-row total"><span>الإجمالي</span><span>${Number(q.total).toFixed(2)} ${esc(q.currency)}</span></div>
                ${actions.length ? '<div class="ai-quote-actions">' + actions.join('') + '</div>' : ''}
            </div>`;
    }

    window.quoteSend = async function (quoteId) {
        const res = await fetchJSON(quoteBase() + '/' + quoteId + '/send', { method: 'POST' });
        if (res.success) { toast('تم إرسال عرض السعر للعميل', 'success'); quoteLoad(); selectConversation(currentConversation.id); }
        else { toast(res.error || 'فشل الإرسال', 'error'); }
    };

    window.quoteSetStatus = async function (quoteId, status) {
        const res = await fetchJSON(quoteBase() + '/' + quoteId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: status }),
        });
        if (res.success) {
            toast('تم تحديث حالة العرض', 'success');
            quoteLoad();
            if (status === 'accepted') {
                updateField('lead_status', 'converted');
                updateField('status', 'resolved');
            }
        }
        else { toast(res.error || 'فشل التحديث', 'error'); }
    };

    document.getElementById('ucSearch').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') loadList();
    });
    window.addEventListener('tourfecto:website-changed', function () {
        activeConvId = null;
        currentConversation = null;
        document.getElementById('ucEmptyState').style.display = 'block';
        document.getElementById('ucThreadPanel').style.display = 'none';
        loadList();
    });

    loadList();
    setInterval(loadList, 20000);
    setInterval(refreshActive, 30000);
})();
