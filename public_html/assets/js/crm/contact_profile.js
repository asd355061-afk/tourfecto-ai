(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    const contactId = parseInt(document.getElementById('c360Root').dataset.contactId, 10);

    function section(title, rows, extraHtml) {
        return `<div class="p-card" style="margin-bottom:14px;"><div class="p-card-head"><h3>${title}</h3></div><div style="padding:0 20px 16px;">${extraHtml || ''}${rows}</div></div>`;
    }

    window.loadAiSummary = async function () {
        const box = document.getElementById('aiSummaryBox');
        box.innerHTML = I18N['common.loading'];
        const res = await fetchJSON('/api/crm/contacts/' + contactId + '/ai-summary');
        if (res.success) {
            box.innerHTML = `<div style="white-space:pre-line;line-height:1.8;">${esc(res.data.summary)}</div>`;
        } else {
            box.innerHTML = `<p class="p-cell-muted">${esc(res.error || I18N['crm.ai.summary_failed'])}</p>`;
        }
    };

    window.loadLeadNba = async function (leadId, targetId) {
        const target = document.getElementById(targetId);
        target.textContent = I18N['common.loading'];
        const res = await fetchJSON('/api/crm/leads/' + leadId + '/next-best-action');
        target.innerHTML = res.success ? `<strong>${esc(res.data.action)}</strong> - ${esc(res.data.reason)}` : esc(res.error || '-');
    };

    window.scoreLead = async function (leadId, targetId) {
        const target = document.getElementById(targetId);
        target.textContent = I18N['common.loading'];
        const res = await fetchJSON('/api/crm/leads/' + leadId + '/score', { method: 'POST' });
        if (res.success) {
            const l = res.data.lead;
            target.innerHTML = `<span class="p-badge ${l.priority === 'high' ? 'green' : ''}">${esc(l.score)} - ${esc(l.priority || '-')}</span> <span class="p-cell-muted">${esc(l.score_reason || '')}</span>`;
        } else {
            target.textContent = res.error || '-';
        }
    };

    window.sendWa = async function () {
        const text = document.getElementById('waText').value.trim();
        if (!text) return;
        const result = document.getElementById('commResult');
        result.textContent = I18N['common.loading'];
        const res = await fetchJSON('/api/crm/contacts/' + contactId + '/send-whatsapp', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ text }),
        });
        if (res.success) {
            result.textContent = I18N['crm.comm.sent'];
            document.getElementById('waText').value = '';
            toast(I18N['crm.comm.sent'], 'success');
        } else {
            result.textContent = res.error || I18N['crm.comm.send_failed'];
            toast(res.error || I18N['crm.comm.send_failed'], 'error');
        }
    };

    window.sendEmailMsg = async function () {
        const subject = document.getElementById('emailSubject').value.trim();
        const body = document.getElementById('emailBody').value.trim();
        if (!subject || !body) { toast(I18N['crm.comm.email_required'], 'error'); return; }
        const result = document.getElementById('commResult');
        result.textContent = I18N['common.loading'];
        const res = await fetchJSON('/api/crm/contacts/' + contactId + '/send-email', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ subject, body }),
        });
        if (res.success) {
            result.textContent = I18N['crm.comm.sent'];
            document.getElementById('emailSubject').value = '';
            document.getElementById('emailBody').value = '';
            toast(I18N['crm.comm.sent'], 'success');
        } else {
            result.textContent = res.error || I18N['crm.comm.send_failed'];
            toast(res.error || I18N['crm.comm.send_failed'], 'error');
        }
    };

    window.sendSmsMsg = async function () {
        const text = document.getElementById('smsText').value.trim();
        if (!text) return;
        const result = document.getElementById('commResult');
        result.textContent = I18N['common.loading'];
        const res = await fetchJSON('/api/crm/contacts/' + contactId + '/send-sms', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ text }),
        });
        if (res.success) {
            result.textContent = I18N['crm.comm.sent'];
            document.getElementById('smsText').value = '';
            toast(I18N['crm.comm.sent'], 'success');
        } else {
            result.textContent = res.error || I18N['crm.comm.send_failed'];
            toast(res.error || I18N['crm.comm.send_failed'], 'error');
        }
    };

    async function loadCommStatus() {
        const res = await fetchJSON('/api/crm/communication/status');
        const note = document.getElementById('commStatusNote');
        if (!res.success) { note.textContent = ''; return; }
        const parts = [];
        parts.push(res.data.whatsapp_configured ? I18N['crm.comm.whatsapp_ready'] : I18N['crm.comm.whatsapp_not_configured']);
        parts.push(res.data.email_configured ? I18N['crm.comm.email_ready'] : I18N['crm.comm.email_not_configured']);
        parts.push(res.data.sms_configured ? I18N['crm.comm.sms_ready'] : I18N['crm.comm.sms_not_configured']);
        note.textContent = parts.join(' · ');
    }

    async function load() {
        const res = await fetchJSON('/api/crm/contacts/' + contactId + '/360');
        const root = document.getElementById('c360Root');
        if (!res.success) { root.innerHTML = `<div class="p-empty">${res.error || I18N['crm.contacts.load_failed']}</div>`; return; }
        const d = res.data;
        const c = d.contact;

        let html = `<div class="p-card" style="margin-bottom:14px;padding:20px;">
            <h2 style="margin:0 0 6px;">${esc(c.name)}</h2>
            <div class="p-cell-muted">${esc(c.email || '-')} · ${esc(c.phone || '-')} · ${esc(c.country || '')}</div>
            <div style="margin-top:8px;"><span class="p-badge">${esc(c.status)}</span> <span class="p-badge">${esc(c.source || '')}</span> ${d.company ? '<span class="p-badge gold">' + esc(d.company.name) + '</span>' : ''}</div>
        </div>`;

        html += `<div class="p-card" style="margin-bottom:14px;padding:20px;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;">${I18N['crm.ai.summary_title']}</h3>
                <button class="p-btn xs" onclick="loadAiSummary()">${I18N['crm.ai.generate']}</button>
            </div>
            <div id="aiSummaryBox" class="p-cell-muted" style="margin-top:10px;">${I18N['crm.ai.summary_hint']}</div>
        </div>`;

        html += `<div class="p-card" style="margin-bottom:14px;padding:20px;">
            <h3 style="margin:0 0 10px;">${I18N['crm.comm.title']}</h3>
            <div id="commStatusNote" class="p-cell-muted" style="margin-bottom:10px;font-size:12.5px;"></div>
            <div style="display:flex;gap:8px;margin-bottom:8px;">
                <input type="text" id="waText" class="form-control" placeholder="${I18N['crm.comm.whatsapp_placeholder']}">
                <button class="p-btn" onclick="sendWa()">${I18N['crm.comm.send_whatsapp']}</button>
            </div>
            <div style="display:flex;gap:8px;margin-bottom:8px;">
                <input type="text" id="smsText" class="form-control" placeholder="${I18N['crm.comm.sms_placeholder']}">
                <button class="p-btn" onclick="sendSmsMsg()">${I18N['crm.comm.send_sms']}</button>
            </div>
            <div style="display:flex;gap:8px;">
                <input type="text" id="emailSubject" class="form-control" style="max-width:220px;" placeholder="${I18N['crm.comm.subject_placeholder']}">
                <input type="text" id="emailBody" class="form-control" placeholder="${I18N['crm.comm.email_placeholder']}">
                <button class="p-btn" onclick="sendEmailMsg()">${I18N['crm.comm.send_email']}</button>
            </div>
            <div id="commResult" class="p-cell-muted" style="margin-top:8px;font-size:12.5px;"></div>
        </div>`;

        html += section(I18N['crm.leads.title'], d.leads.length ? d.leads.map(l => `
            <div style="padding:8px 0;border-bottom:1px solid var(--border,#eee);">
                <div>#${l.id} - ${esc(l.status)} ${l.value ? '· ' + esc(l.value) + ' ' + esc(l.currency||'') : ''}
                    <button class="p-btn xs" onclick="scoreLead(${l.id}, 'leadScore${l.id}')">${I18N['crm.ai.score_lead']}</button>
                    <button class="p-btn xs" onclick="loadLeadNba(${l.id}, 'leadNba${l.id}')">${I18N['crm.ai.next_best_action']}</button>
                </div>
                <div id="leadScore${l.id}" class="p-cell-muted" style="font-size:12.5px;margin-top:4px;">${l.score ? (esc(l.score) + ' - ' + esc(l.priority||'-') + (l.score_reason ? ' · ' + esc(l.score_reason) : '')) : ''}</div>
                <div id="leadNba${l.id}" class="p-cell-muted" style="font-size:12.5px;"></div>
            </div>`).join('') : `<p class="p-cell-muted">${I18N['crm.leads.none_yet']}</p>`);

        html += section(I18N['crm.deals.title'], d.deals.length ? d.deals.map(x => `<div class="p-cell-muted" style="padding:6px 0;border-bottom:1px solid var(--border,#eee);">${esc(x.title)} - <span class="p-badge" style="background:${esc(x.stage_color||'#6366f1')};color:#fff;">${esc(x.stage_name)}</span> · ${esc(x.value)} ${esc(x.currency)}</div>`).join('') : `<p class="p-cell-muted">${I18N['crm.deals.empty_column']}</p>`);

        html += section(I18N['crm.tasks.title'], d.tasks.length ? d.tasks.map(x => `<div class="p-cell-muted" style="padding:6px 0;border-bottom:1px solid var(--border,#eee);">${esc(x.title)} - ${esc(x.status)} ${x.due_date ? '· ' + formatDate(x.due_date) : ''}</div>`).join('') : `<p class="p-cell-muted">${I18N['crm.tasks.none_yet']}</p>`);

        html += section(I18N['crm.appointments.title'], d.appointments.length ? d.appointments.map(x => `<div class="p-cell-muted" style="padding:6px 0;border-bottom:1px solid var(--border,#eee);">${esc(x.title)} - ${formatDate(x.starts_at)} · ${esc(x.status)}</div>`).join('') : `<p class="p-cell-muted">${I18N['crm.appointments.none_yet']}</p>`);

        html += section(I18N['crm.notes.title'], d.notes.length ? d.notes.map(x => `<div class="p-cell-muted" style="padding:6px 0;border-bottom:1px solid var(--border,#eee);">${esc(x.body)}</div>`).join('') : `<p class="p-cell-muted">${I18N['crm.notes.none_yet']}</p>`);

        html += section(I18N['crm.timeline.title'], d.timeline.length ? d.timeline.map(x => `<div class="p-cell-muted" style="padding:6px 0;border-bottom:1px solid var(--border,#eee);">${esc(x.action)} · ${formatDate(x.created_at)}</div>`).join('') : `<p class="p-cell-muted">${I18N['crm.timeline.empty']}</p>`);

        root.innerHTML = html;
        loadCommStatus();
    }
    load();
})();