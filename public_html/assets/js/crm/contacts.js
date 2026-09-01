(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    let currentPage = 1;
    let totalPages = 1;
    let activeSegmentId = null;

    window.addContact = async function () {
        const name = document.getElementById('cName').value.trim();
        if (!name) { toast(I18N['crm.leads.name_required'], 'error'); return; }
        const res = await fetchJSON('/api/crm/contacts', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name,
                email: document.getElementById('cEmail').value.trim(),
                phone: document.getElementById('cPhone').value.trim(),
                source: document.getElementById('cSource').value,
            }),
        });
        document.getElementById('newContactModal').classList.remove('open');
        if (res.success) { toast(I18N['common.added'], 'success'); load(); }
        else toast(res.error || I18N['crm.leads.add_failed'], 'error');
    };

    window.applyFilters = function () { activeSegmentId = null; currentPage = 1; load(); };
    window.clearFilters = function () {
        document.getElementById('contactSearch').value = '';
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterSource').value = '';
        activeSegmentId = null;
        currentPage = 1;
        load();
    };
    window.changePage = function (delta) {
        const next = currentPage + delta;
        if (next < 1 || next > totalPages) return;
        currentPage = next;
        load();
    };

    window.runSegment = function (id) {
        activeSegmentId = id;
        currentPage = 1;
        load();
    };

    window.saveSegment = async function () {
        const name = document.getElementById('segmentName').value.trim();
        if (!name) { toast(I18N['crm.segments.name_required'], 'error'); return; }
        const res = await fetchJSON('/api/crm/segments', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, filters: currentFilters() }),
        });
        document.getElementById('saveSegmentModal').classList.remove('open');
        if (res.success) { toast(I18N['common.added'], 'success'); loadSegments(); }
        else toast(res.error || I18N['crm.leads.add_failed'], 'error');
    };

    window.deleteSegment = async function (id, event) {
        event.stopPropagation();
        const res = await fetchJSON('/api/crm/segments/' + id, { method: 'DELETE' });
        if (res.success) { toast(I18N['common.updated'], 'success'); loadSegments(); if (activeSegmentId === id) window.clearFilters(); }
        else toast(res.error, 'error');
    };

    function currentFilters() {
        const f = {};
        const search = document.getElementById('contactSearch').value.trim();
        const status = document.getElementById('filterStatus').value;
        const source = document.getElementById('filterSource').value;
        if (search) f.search = search;
        if (status) f.status = status;
        if (source) f.source = source;
        return f;
    }

    function render(list) {
        const tbody = document.querySelector('#contactsTable tbody');
        if (list.length) {
            tbody.innerHTML = list.map(c => `
                <tr>
                    <td><a href="/crm/contacts/${c.id}">${esc(c.name)}</a></td>
                    <td style="direction:ltr;text-align:left;">${esc(c.email || '-')}</td>
                    <td style="direction:ltr;text-align:left;">${esc(c.phone || '-')}</td>
                    <td>${esc(c.source || '-')}</td>
                    <td><span class="p-badge ${c.status === 'active' ? 'green' : ''}">${esc(c.status || '-')}</span></td>
                    <td><a class="p-btn xs" href="/crm/contacts/${c.id}">${I18N['crm.contacts.view_360']}</a></td>
                </tr>`).join('');
        } else {
            tbody.innerHTML = `<tr><td colspan="6" class="p-cell-muted text-center">${I18N['crm.contacts.none_yet']}</td></tr>`;
        }
    }

    async function loadSources() {
        const res = await fetchJSON('/api/crm/lead-sources');
        const select = document.getElementById('filterSource');
        select.innerHTML = `<option value="">${I18N['crm.filters.source_any']}</option>` +
            (res.success ? res.data.sources.map(s => `<option value="${esc(s.source_key)}">${esc(s.name)}</option>`).join('') : '');
    }

    async function loadSegments() {
        const res = await fetchJSON('/api/crm/segments');
        const box = document.getElementById('segmentsList');
        if (!res.success || !res.data.segments.length) { box.textContent = I18N['crm.segments.none_yet']; return; }
        box.innerHTML = res.data.segments.map(s => `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;cursor:pointer;" onclick="runSegment(${s.id})">
                <span style="${activeSegmentId === s.id ? 'font-weight:700;' : ''}">${esc(s.name)}</span>
                ${s.is_system == 0 ? `<span onclick="deleteSegment(${s.id}, event)" style="cursor:pointer;color:#ef4444;">✕</span>` : ''}
            </div>`).join('');
    }

    async function load() {
        let url, res;
        if (activeSegmentId) {
            url = '/api/crm/segments/' + activeSegmentId + '/run?page=' + currentPage + '&per_page=25';
        } else {
            const qs = new URLSearchParams(Object.assign({ page: currentPage, per_page: 25 }, currentFilters())).toString();
            url = '/api/crm/contacts/search?' + qs;
        }
        res = await fetchJSON(url);
        if (!res.success) { render([]); return; }
        render(res.data.items);
        totalPages = res.data.total_pages || 1;
        document.getElementById('paginationInfo').textContent =
            I18N['crm.pagination.page_of'].replace('{page}', res.data.page).replace('{total}', totalPages) +
            ' · ' + res.data.total + ' ' + I18N['crm.contacts.title'];
        document.getElementById('prevPageBtn').disabled = res.data.page <= 1;
        document.getElementById('nextPageBtn').disabled = res.data.page >= totalPages;
    }

    loadSources();
    loadSegments();
    load();
})();