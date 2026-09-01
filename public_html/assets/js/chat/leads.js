(function () {
    const P = window.Panel;

    function ic(name, cls) {
        return '<svg class="ic ' + (cls || '') + '" aria-hidden="true"><use href="#i-' + name + '"/></svg>';
    }
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;

    const STATUS_OPTIONS = [
        ['new', 'جديد'], ['contacted', 'تم التواصل'], ['qualified', 'مؤهّل'],
        ['proposal_sent', 'تم إرسال عرض سعر'], ['won', 'تم الفوز به'], ['lost', 'فاقد'],
    ];

    function websiteId() { return P.getCurrentWebsiteId(); }

    function ensureWebsite() {
        const id = websiteId();
        document.getElementById('ldNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('ldTableWrap').style.display = id ? 'block' : 'none';
        return id;
    }

    window.ldUpdateStatus = async function (leadId, status) {
        const id = websiteId();
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/leads/' + leadId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: status }),
        });
        if (res.success) toast('تم تحديث الحالة', 'success');
        else { toast(res.error || 'فشل التحديث', 'error'); load(); }
    };

    window.load = async function () {
        const id = ensureWebsite();
        if (!id) return;

        const status = document.getElementById('ldStatus').value;
        const qs = status ? ('?status=' + encodeURIComponent(status)) : '';

        const tbody = document.querySelector('#leadsTable tbody');
        tbody.innerHTML = '<tr class="p-loading-row"><td colspan="9">جاري التحميل...</td></tr>';

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/leads' + qs);
        if (!res.success) {
            tbody.innerHTML = '<tr><td colspan="9" class="p-cell-muted text-center">⚠️ ' + esc(res.error || 'تعذر التحميل') + '</td></tr>';
            return;
        }

        const leads = res.data.leads || [];
        if (!leads.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="p-cell-muted text-center">لا توجد Leads بعد</td></tr>';
            return;
        }

        tbody.innerHTML = leads.map(l => {
            const statusSelect = '<select class="p-select" style="min-width:130px;" onchange="ldUpdateStatus(' + l.id + ', this.value)">' +
                STATUS_OPTIONS.map(([v, label]) => `<option value="${v}" ${l.status === v ? 'selected' : ''}>${label}</option>`).join('') + '</select>';
            return `
                <tr>
                    <td>${esc(l.name || l.phone || 'غير معروف')}</td>
                    <td>${esc(l.channel || '-')}</td>
                    <td>${esc(l.interest || '-')}</td>
                    <td>${esc(l.destination || '-')}</td>
                    <td><span class="pill ${l.lead_score >= 70 ? 'red' : l.lead_score >= 40 ? 'blue' : 'gray'}">${l.lead_score ?? '-'}</span></td>
                    <td>${l.intent_score ?? '-'}</td>
                    <td>${statusSelect}</td>
                    <td class="p-cell-muted">${P.timeAgo(l.last_interaction_at)}</td>
                    <td><a href="/chat/conversation/${l.conversation_id}" class="p-btn outline xs">فتح المحادثة</a></td>
                </tr>`;
        }).join('');
    };

    window.addEventListener('tourfecto:website-changed', load);
    load();
})();
