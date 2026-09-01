(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    let stages = [];
    let dragDealId = null;
    let currentView = 'kanban';
    let listPage = 1;
    let listTotalPages = 1;

    async function loadStages() {
        const res = await fetchJSON('/api/crm/pipeline-stages');
        stages = res.success ? res.data.stages : [];
        document.getElementById('dealStage').innerHTML = stages.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
    }

    window.addDeal = async function () {
        const title = document.getElementById('dealTitle').value.trim();
        if (!title) { toast(I18N['crm.deals.title_required'], 'error'); return; }
        const res = await fetchJSON('/api/crm/deals', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                title,
                value: document.getElementById('dealValue').value || 0,
                currency: document.getElementById('dealCurrency').value,
                stage_id: document.getElementById('dealStage').value,
            }),
        });
        document.getElementById('newDealModal').classList.remove('open');
        if (res.success) { toast(I18N['crm.deals.created'], 'success'); loadCurrentView(); }
        else toast(res.error || I18N['crm.deals.create_failed'], 'error');
    };

    window.onDealDragStart = function (id) { dragDealId = id; };
    window.onColumnDrop = async function (stageId) {
        if (!dragDealId) return;
        const res = await fetchJSON('/api/crm/deals/' + dragDealId + '/stage', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ stage_id: stageId }),
        });
        dragDealId = null;
        if (res.success) load();
        else toast(res.error || I18N['crm.deals.move_failed'], 'error');
    };

    // ================= Kanban (الأصلية - لم تتغيّر) =================

    async function load() {
        const res = await fetchJSON('/api/crm/deals');
        const deals = res.success ? res.data.deals : [];
        const board = document.getElementById('dealsBoard');

        board.innerHTML = stages.map(stage => {
            const stageDeals = deals.filter(d => String(d.stage_id) === String(stage.id));
            const total = stageDeals.reduce((sum, d) => sum + parseFloat(d.value || 0), 0);
            return `
            <div style="min-width:250px;flex-shrink:0;" ondragover="event.preventDefault()" ondrop="onColumnDrop(${stage.id})">
                <div class="p-card" style="border-top:3px solid ${esc(stage.color || '#6366f1')};padding:12px;margin-bottom:10px;">
                    <strong>${esc(stage.name)}</strong>
                    <div class="p-cell-muted" style="font-size:12px;">${stageDeals.length} ${I18N['crm.deals.deal_word']} · ${total.toLocaleString()}</div>
                </div>
                ${stageDeals.map(d => `
                    <div class="p-card" draggable="true" ondragstart="onDealDragStart(${d.id})" style="padding:12px;margin-bottom:8px;cursor:grab;">
                        <div style="font-weight:700;font-size:13.5px;margin-bottom:4px;">${esc(d.title)}</div>
                        <div class="p-cell-muted" style="font-size:12.5px;">${esc(d.value || 0)} ${esc(d.currency || '')}</div>
                    </div>`).join('') || `<p class="p-cell-muted" style="font-size:12.5px;">${I18N['crm.deals.empty_column']}</p>`}
            </div>`;
        }).join('');
    }

    // ================= List View جديدة (بند 29، 37) - بديل اختياري للـKanban =================

    window.switchView = function (view) {
        currentView = view;
        document.getElementById('dealsBoard').style.display = view === 'kanban' ? 'flex' : 'none';
        document.getElementById('dealsListView').style.display = view === 'list' ? 'block' : 'none';
        document.getElementById('viewToggleKanban').classList.toggle('primary', view === 'kanban');
        document.getElementById('viewToggleList').classList.toggle('primary', view === 'list');
        loadCurrentView();
    };

    window.applyDealFilters = function () { listPage = 1; loadListView(); };
    window.clearDealFilters = function () {
        document.getElementById('dealSearch').value = '';
        document.getElementById('dealFilterStatus').value = '';
        document.getElementById('dealMinValue').value = '';
        document.getElementById('dealMaxValue').value = '';
        listPage = 1;
        loadListView();
    };
    window.changeDealsPage = function (delta) {
        const next = listPage + delta;
        if (next < 1 || next > listTotalPages) return;
        listPage = next;
        loadListView();
    };

    async function loadListView() {
        const params = new URLSearchParams({ page: listPage, per_page: 25 });
        const search = document.getElementById('dealSearch').value.trim();
        const status = document.getElementById('dealFilterStatus').value;
        const minValue = document.getElementById('dealMinValue').value;
        const maxValue = document.getElementById('dealMaxValue').value;
        if (search) params.set('search', search);
        if (status) params.set('status', status);
        if (minValue) params.set('min_value', minValue);
        if (maxValue) params.set('max_value', maxValue);

        const res = await fetchJSON('/api/crm/deals/search?' + params.toString());
        const tbody = document.querySelector('#dealsTable tbody');
        const list = res.success ? res.data.items : [];
        tbody.innerHTML = list.length ? list.map(d => `
            <tr>
                <td>${esc(d.title)}</td>
                <td><span class="p-badge" style="background:${esc(d.stage_color || '#6366f1')};color:#fff;">${esc(d.stage_name)}</span></td>
                <td>${esc(d.value)} ${esc(d.currency)}</td>
                <td><span class="p-badge">${esc(d.status)}</span></td>
            </tr>`).join('') : `<tr><td colspan="4" class="p-cell-muted text-center">${I18N['crm.deals.empty_column']}</td></tr>`;

        if (res.success) {
            listTotalPages = res.data.total_pages || 1;
            document.getElementById('dealsPaginationInfo').textContent =
                I18N['crm.pagination.page_of'].replace('{page}', res.data.page).replace('{total}', listTotalPages) + ' · ' + res.data.total;
            document.getElementById('dealsPrevBtn').disabled = res.data.page <= 1;
            document.getElementById('dealsNextBtn').disabled = res.data.page >= listTotalPages;
        }
    }

    function loadCurrentView() {
        if (currentView === 'kanban') load(); else loadListView();
    }

    loadStages().then(load);
})();