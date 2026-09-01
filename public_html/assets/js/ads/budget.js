(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;
    let budgetTrendChart = null, comparisonChart = null;

    async function loadBudgetKpis() {
        const box = document.getElementById('budgetKpis');
        const res = await fetchJSON('/api/ads/dashboard/summary?period=monthly');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحميل</div>'; return; }
        const d = res.data;
        const kpi = (label, value) => `
            <div class="p-card" style="padding:14px;">
                <div class="p-cell-muted" style="font-size:11.5px;">${label}</div>
                <div style="font-size:20px;font-weight:800;margin-top:4px;">${value === null || value === undefined ? '<span class="p-cell-muted" style="font-size:13px;">لا توجد بيانات كافية</span>' : esc(String(value))}</div>
            </div>`;
        box.innerHTML = kpi('الإنفاق (آخر 30 يوم)', d.spend) + kpi('استخدام الميزانية', d.budget_utilization_pct !== null ? d.budget_utilization_pct + '%' : null) + kpi('حملات نشطة', d.active_campaigns) + kpi('حملات متوقفة', d.paused_campaigns);
    }

    window.loadComparisonChart = async function () {
        const period = document.getElementById('cmpPeriod').value;
        const res = await fetchJSON('/api/ads/reports/comparison?period=' + period);
        const emptyBox = document.getElementById('comparisonEmpty');
        const canvas = document.getElementById('comparisonChart');

        if (!res.success || !res.data.length) { emptyBox.style.display = 'block'; canvas.style.display = 'none'; return; }
        emptyBox.style.display = 'none'; canvas.style.display = 'block';

        if (comparisonChart) comparisonChart.destroy();
        comparisonChart = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: res.data.map(c => c.name),
                datasets: [{ label: 'الإنفاق', data: res.data.map(c => c.spend), backgroundColor: '#0077be' }],
            },
            options: { responsive: true },
        });
    };

    async function loadBudgetTrend() {
        const res = await fetchJSON('/api/ads/reports/trend?days=30');
        const emptyBox = document.getElementById('budgetTrendEmpty');
        const canvas = document.getElementById('budgetTrendChart');
        if (!res.success || !res.data.length) { emptyBox.style.display = 'block'; canvas.style.display = 'none'; return; }
        emptyBox.style.display = 'none'; canvas.style.display = 'block';

        if (budgetTrendChart) budgetTrendChart.destroy();
        budgetTrendChart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: res.data.map(r => r.date),
                datasets: [
                    { label: 'الإنفاق', data: res.data.map(r => r.spend), borderColor: '#0077be', tension: 0.3 },
                    { label: 'التحويلات', data: res.data.map(r => r.conversions), borderColor: '#22c55e', tension: 0.3 },
                ],
            },
            options: { responsive: true },
        });
    }

    loadBudgetKpis();
    loadBudgetTrend();
    loadComparisonChart();
})();
