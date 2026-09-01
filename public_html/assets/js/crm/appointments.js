(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    let currentPage = 1;
    let totalPages = 1;

    window.addAppt = async function () {
        const title = document.getElementById('aTitle').value.trim();
        const starts = document.getElementById('aStarts').value;
        if (!title || !starts) { toast(I18N['crm.appointments.required'], 'error'); return; }
        const res = await fetchJSON('/api/crm/appointments', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title, starts_at: starts.replace('T', ' '), purpose: document.getElementById('aPurpose').value.trim() }),
        });
        document.getElementById('newApptModal').classList.remove('open');
        if (res.success) { toast(I18N['common.added'], 'success'); load(); }
        else toast(res.error || I18N['crm.leads.add_failed'], 'error');
    };

    window.applyApptFilters = function () { currentPage = 1; load(); };
    window.clearApptFilters = function () {
        document.getElementById('apptSearch').value = '';
        document.getElementById('apptFilterStatus').value = '';
        currentPage = 1;
        load();
    };
    window.changeApptsPage = function (delta) {
        const next = currentPage + delta;
        if (next < 1 || next > totalPages) return;
        currentPage = next;
        load();
    };

    async function load() {
        const params = new URLSearchParams({ page: currentPage, per_page: 25 });
        const search = document.getElementById('apptSearch').value.trim();
        const status = document.getElementById('apptFilterStatus').value;
        if (search) params.set('search', search);
        if (status) params.set('status', status);

        const res = await fetchJSON('/api/crm/appointments/search?' + params.toString());
        const tbody = document.querySelector('#apptsTable tbody');
        const list = res.success ? res.data.items : [];
        tbody.innerHTML = list.length ? list.map(a => `
            <tr><td>${esc(a.title)}</td><td class="p-cell-muted">${formatDate(a.starts_at)}</td><td><span class="p-badge">${esc(a.status)}</span></td></tr>
        `).join('') : `<tr><td colspan="3" class="p-cell-muted text-center">${I18N['crm.appointments.none_yet']}</td></tr>`;

        if (res.success) {
            totalPages = res.data.total_pages || 1;
            document.getElementById('apptsPaginationInfo').textContent =
                I18N['crm.pagination.page_of'].replace('{page}', res.data.page).replace('{total}', totalPages) + ' · ' + res.data.total;
            document.getElementById('apptsPrevBtn').disabled = res.data.page <= 1;
            document.getElementById('apptsNextBtn').disabled = res.data.page >= totalPages;
        }
    }
    load();
})();