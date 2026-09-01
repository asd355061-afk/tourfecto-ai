(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    let currentPage = 1;
    let totalPages = 1;

    const statusLabels = {
        new: I18N['crm.leads.status.new'], nurturing: I18N['crm.leads.status.nurturing'], qualified: I18N['crm.leads.status.qualified'],
        disqualified: I18N['crm.leads.status.disqualified'], converted: I18N['crm.leads.status.converted'],
    };
    const statusOptions = Object.entries(statusLabels).map(([v, l]) => `<option value="${v}">${l}</option>`).join('');

    window.changeLeadStatus = async function (id, status) {
        const res = await fetchJSON('/api/crm/leads/' + id + '/status', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status }),
        });
        if (res.success) toast(I18N['common.updated'], 'success');
        else toast(res.error || I18N['common.update_failed'], 'error');
        load();
    };

    window.addLead = async function () {
        const name = document.getElementById('leadName').value.trim();
        if (!name) { toast(I18N['crm.leads.name_required'], 'error'); return; }
        const res = await fetchJSON('/api/crm/leads', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name,
                email: document.getElementById('leadEmail').value.trim(),
                phone: document.getElementById('leadPhone').value.trim(),
                source: document.getElementById('leadSource').value,
            }),
        });
        document.getElementById('newLeadModal').classList.remove('open');
        if (res.success) { toast(I18N['common.added'], 'success'); load(); }
        else toast(res.error || I18N['crm.leads.add_failed'], 'error');
    };

    window.applyLeadFilters = function () { currentPage = 1; load(); };
    window.clearLeadFilters = function () {
        document.getElementById('leadSearch').value = '';
        document.getElementById('leadFilterStatus').value = '';
        currentPage = 1;
        load();
    };
    window.changeLeadsPage = function (delta) {
        const next = currentPage + delta;
        if (next < 1 || next > totalPages) return;
        currentPage = next;
        load();
    };

    async function load() {
        const params = new URLSearchParams({ page: currentPage, per_page: 25 });
        const search = document.getElementById('leadSearch').value.trim();
        const status = document.getElementById('leadFilterStatus').value;
        if (search) params.set('search', search);
        if (status) params.set('status', status);

        const res = await fetchJSON('/api/crm/leads/search?' + params.toString());
        const tbody = document.querySelector('#leadsTable tbody');
        if (res.success && res.data.items && res.data.items.length) {
            tbody.innerHTML = res.data.items.map(l => `
                <tr>
                    <td>${esc(l.contact_name || '-')}</td>
                    <td style="direction:ltr;text-align:left;">${esc(l.contact_email || '-')}</td>
                    <td style="direction:ltr;text-align:left;">${esc(l.contact_phone || '-')}</td>
                    <td><select class="p-select xs" onchange="changeLeadStatus(${l.id}, this.value)">${statusOptions.replace(`value="${l.status}"`, `value="${l.status}" selected`)}</select></td>
                    <td class="p-cell-muted">${l.last_engagement_at ? formatDate(l.last_engagement_at) : '-'}</td>
                </tr>`).join('');
            totalPages = res.data.total_pages || 1;
            document.getElementById('leadsPaginationInfo').textContent =
                I18N['crm.pagination.page_of'].replace('{page}', res.data.page).replace('{total}', totalPages) + ' · ' + res.data.total;
            document.getElementById('leadsPrevBtn').disabled = res.data.page <= 1;
            document.getElementById('leadsNextBtn').disabled = res.data.page >= totalPages;
        } else {
            tbody.innerHTML = `<tr><td colspan="5" class="p-cell-muted text-center">${I18N['crm.leads.none_yet']}</td></tr>`;
            document.getElementById('leadsPaginationInfo').textContent = '';
        }
    }
    load();
})();