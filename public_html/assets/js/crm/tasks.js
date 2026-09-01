(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    let currentPage = 1;
    let totalPages = 1;

    window.addTask = async function () {
        const title = document.getElementById('tTitle').value.trim();
        if (!title) { toast(I18N['crm.tasks.title_required'], 'error'); return; }
        const res = await fetchJSON('/api/crm/tasks', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                title,
                due_date: document.getElementById('tDue').value || null,
                priority: document.getElementById('tPriority').value,
            }),
        });
        document.getElementById('newTaskModal').classList.remove('open');
        if (res.success) { toast(I18N['common.added'], 'success'); load(); }
        else toast(res.error || I18N['crm.leads.add_failed'], 'error');
    };

    window.toggleTaskDone = async function (id, done) {
        const res = await fetchJSON('/api/crm/tasks/' + id + '/status', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status: done ? 'done' : 'open' }),
        });
        if (res.success) load(); else toast(res.error, 'error');
    };

    window.applyTaskFilters = function () { currentPage = 1; load(); };
    window.clearTaskFilters = function () {
        document.getElementById('taskSearch').value = '';
        document.getElementById('taskFilterStatus').value = '';
        document.getElementById('taskFilterPriority').value = '';
        currentPage = 1;
        load();
    };
    window.changeTasksPage = function (delta) {
        const next = currentPage + delta;
        if (next < 1 || next > totalPages) return;
        currentPage = next;
        load();
    };

    async function load() {
        const params = new URLSearchParams({ page: currentPage, per_page: 25 });
        const search = document.getElementById('taskSearch').value.trim();
        const status = document.getElementById('taskFilterStatus').value;
        const priority = document.getElementById('taskFilterPriority').value;
        if (search) params.set('search', search);
        if (status) params.set('status', status);
        if (priority) params.set('priority', priority);

        const res = await fetchJSON('/api/crm/tasks/search?' + params.toString());
        const tbody = document.querySelector('#tasksTable tbody');
        const list = res.success ? res.data.items : [];
        tbody.innerHTML = list.length ? list.map(t => {
            const overdue = t.due_date && new Date(t.due_date) < new Date() && t.status !== 'done';
            return `<tr>
                <td><label><input type="checkbox" ${t.status === 'done' ? 'checked' : ''} onchange="toggleTaskDone(${t.id}, this.checked)"> ${esc(t.title)}</label></td>
                <td class="${overdue ? 'p-cell-danger' : 'p-cell-muted'}">${t.due_date ? formatDate(t.due_date) : '-'}</td>
                <td><span class="p-badge">${esc(t.priority)}</span></td>
                <td><span class="p-badge ${t.status === 'done' ? 'green' : ''}">${esc(t.status)}</span></td>
            </tr>`;
        }).join('') : `<tr><td colspan="4" class="p-cell-muted text-center">${I18N['crm.tasks.none_yet']}</td></tr>`;

        if (res.success) {
            totalPages = res.data.total_pages || 1;
            document.getElementById('tasksPaginationInfo').textContent =
                I18N['crm.pagination.page_of'].replace('{page}', res.data.page).replace('{total}', totalPages) + ' · ' + res.data.total;
            document.getElementById('tasksPrevBtn').disabled = res.data.page <= 1;
            document.getElementById('tasksNextBtn').disabled = res.data.page >= totalPages;
        }
    }
    load();
})();