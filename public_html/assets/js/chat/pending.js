(function () {
    const P = window.Panel;
    const I18N = window.I18N || {};

    function ic(name, cls) {
        return '<svg class="ic ' + (cls || '') + '" aria-hidden="true"><use href="#i-' + name + '"/></svg>';
    }
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;

    window.regenerateReply = async function (id) {
        const box = document.getElementById('reply-' + id);
        const btn = document.getElementById('regenBtn-' + id);
        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = '🤖 ' + I18N['chat.pending.generating'];

        const res = await fetchJSON('/api/chat/generate-reply', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message_id: id }),
        });

        btn.disabled = false;
        btn.textContent = original;

        if (res.success) {
            box.value = res.data.reply;
            toast(I18N['chat.pending.new_reply_generated'], 'success');
        } else {
            toast(res.error || I18N['chat.pending.generate_failed'], 'error');
        }
    };

    window.approveMsg = async function (id, action) {
        const editedReply = action === 'approve' ? document.getElementById('reply-' + id).value.trim() : '';
        if (action === 'approve' && !editedReply) {
            toast(I18N['chat.pending.write_or_generate_first'], 'error');
            return;
        }
        const res = await fetchJSON('/api/chat/approve', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message_id: id, action: action, edited_reply: editedReply }),
        });
        if (res.success) {
            if (action === 'approve' && res.data && res.data.sent === false) {
                toast(res.data.message || I18N['chat.pending.approved_send_failed'], 'error');
            } else {
                toast(res.data && res.data.message ? res.data.message : (action === 'approve' ? I18N['chat.pending.approved_sent'] : I18N['chat.pending.rejected']), 'success');
            }
            load();
        }
        else { toast(res.error || I18N['chat.pending.action_failed'], 'error'); }
    };

    async function load() {
        const res = await fetchJSON('/api/chat/pending');
        const container = document.getElementById('pendingList');
        if (res.success && Array.isArray(res.data.pending) && res.data.pending.length) {
            container.innerHTML = res.data.pending.map(m => `
                <div class="p-card" style="margin-bottom:14px;">
                    <div class="p-card-head">
                        <h3>${esc(m.customer_name || m.customer_phone || I18N['chat.pending.customer'])} <span class="pill">${esc(m.platform || '-')}</span></h3>
                        <span class="p-cell-muted">${formatDate(m.created_at)}</span>
                    </div>
                    <div class="p-kv"><span class="k">${I18N['chat.pending.customer_message']}</span></div>
                    <p style="background:var(--panel-bg,#f7f8fa);padding:12px 14px;border-radius:8px;margin:6px 0 14px;">${esc(m.message_text || '-')}</p>
                    <label class="form-label">${I18N['chat.pending.suggested_reply']}</label>
                    <textarea id="reply-${m.id}" class="form-control" style="min-height:90px;margin-bottom:10px;">${esc(m.ai_reply_generated || '')}</textarea>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button class="p-btn success xs" onclick="approveMsg(${m.id}, 'approve')">✔ ${I18N['chat.pending.approve_send']}</button>
                        <button class="p-btn outline xs" id="regenBtn-${m.id}" onclick="regenerateReply(${m.id})">🔄 ${I18N['chat.pending.generate_new']}</button>
                        <button class="p-btn danger xs" onclick="approveMsg(${m.id}, 'reject')">✖ ${I18N['chat.pending.reject']}</button>
                    </div>
                </div>`).join('');
        } else {
            container.innerHTML = '<div class="p-empty"><div class="p-empty-icon">' + ic('check') + '</div>' + I18N['chat.pending.none'] + '</div>';
        }
    }
    load();
})();
