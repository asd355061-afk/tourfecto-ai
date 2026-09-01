(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    let currentPage = 1;
    let totalPages = 1;

    window.addCompany = async function () {
        const name = document.getElementById('coName').value.trim();
        if (!name) { toast(I18N['crm.companies.name_required'], 'error'); return; }
        const res = await fetchJSON('/api/crm/companies', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name,
                industry: document.getElementById('coIndustry').value.trim(),
                website: document.getElementById('coWebsite').value.trim(),
                phone: document.getElementById('coPhone').value.trim(),
            }),
        });
        document.getElementById('newCompanyModal').classList.remove('open');
        if (res.success) { toast(I18N['common.added'], 'success'); load(); }
        else toast(res.error || I18N['crm.leads.add_failed'], 'error');
    };

    window.applyCompanyFilters = function () { currentPage = 1; load(); };
    window.clearCompanyFilters = function () { document.getElementById('companySearch').value = ''; currentPage = 1; load(); };
    window.changeCompaniesPage = function (delta) {
        const next = currentPage + delta;
        if (next < 1 || next > totalPages) return;
        currentPage = next;
        load();
    };

    async function load() {
        const params = new URLSearchParams({ page: currentPage, per_page: 25 });
        const search = document.getElementById('companySearch').value.trim();
        if (search) params.set('search', search);

        const res = await fetchJSON('/api/crm/companies/search?' + params.toString());
        const tbody = document.querySelector('#companiesTable tbody');
        const list = res.success ? res.data.items : [];
        tbody.innerHTML = list.length ? list.map(c => `
            <tr><td>${esc(c.name)}</td><td>${esc(c.industry || '-')}</td><td style="direction:ltr;text-align:left;">${esc(c.website || '-')}</td><td style="direction:ltr;text-align:left;">${esc(c.phone || '-')}</td></tr>
        `).join('') : `<tr><td colspan="4" class="p-cell-muted text-center">${I18N['crm.companies.none_yet']}</td></tr>`;

        if (res.success) {
            totalPages = res.data.total_pages || 1;
            document.getElementById('companiesPaginationInfo').textContent =
                I18N['crm.pagination.page_of'].replace('{page}', res.data.page).replace('{total}', totalPages) + ' · ' + res.data.total;
            document.getElementById('companiesPrevBtn').disabled = res.data.page <= 1;
            document.getElementById('companiesNextBtn').disabled = res.data.page >= totalPages;
        }
    }
    load();
})();