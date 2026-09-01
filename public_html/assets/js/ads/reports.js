(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;
    let trendChartInstance = null;

    window.loadTrendChart = async function () {
        const days = document.getElementById('trendDays').value;
        const res = await fetchJSON('/api/ads/reports/trend?days=' + days);
        const emptyBox = document.getElementById('trendChartEmpty');
        const canvas = document.getElementById('trendChart');

        if (!res.success || !res.data.length) {
            emptyBox.style.display = 'block';
            canvas.style.display = 'none';
            return;
        }
        emptyBox.style.display = 'none';
        canvas.style.display = 'block';

        const labels = res.data.map(r => r.date);
        const spend = res.data.map(r => r.spend);
        const conversions = res.data.map(r => r.conversions);

        if (trendChartInstance) trendChartInstance.destroy();
        trendChartInstance = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels,
                datasets: [
                    { label: 'الإنفاق', data: spend, borderColor: '#0077be', tension: 0.3, yAxisID: 'y' },
                    { label: 'التحويلات', data: conversions, borderColor: '#22c55e', tension: 0.3, yAxisID: 'y1' },
                ],
            },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: { type: 'linear', position: 'left', title: { display: true, text: 'الإنفاق' } },
                    y1: { type: 'linear', position: 'right', title: { display: true, text: 'التحويلات' }, grid: { drawOnChartArea: false } },
                },
            },
        });
    };

    window.loadAdsReport = async function () {
        const box = document.getElementById('reportBox');
        box.innerHTML = '<div class="p-loading-row">جارِ التحميل...</div>';
        const period = document.getElementById('reportPeriod').value;
        const res = await fetchJSON('/api/ads/reports?period=' + period);
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر تحميل التقرير</div>'; return; }
        const d = res.data;
        if (!d.has_data) { box.innerHTML = `<div class="p-cell-muted">${esc(d.note || 'مفيش بيانات كافية للفترة دي بعد')}</div>`; return; }

        box.innerHTML = `
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px;">
                <div><div class="p-cell-muted" style="font-size:11px;">الإنفاق</div><div><b>${esc(d.totals.spend)}</b></div></div>
                <div><div class="p-cell-muted" style="font-size:11px;">النقرات</div><div><b>${esc(d.totals.clicks)}</b></div></div>
                <div><div class="p-cell-muted" style="font-size:11px;">CPA</div><div><b>${d.totals.cpa ?? '-'}</b></div></div>
                <div><div class="p-cell-muted" style="font-size:11px;">ROAS</div><div><b>${d.totals.roas ?? '-'}</b></div></div>
            </div>
            ${d.best_campaign ? `<div>🏆 أفضل حملة: <b>${esc(d.best_campaign.name)}</b> (ROAS: ${d.best_campaign.roas ?? '-'})</div>` : ''}
            ${d.worst_campaign ? `<div>⚠️ أضعف حملة: <b>${esc(d.worst_campaign.name)}</b> (ROAS: ${d.worst_campaign.roas ?? '-'})</div>` : ''}
            ${d.best_creative ? `<div>✨ أفضل إعلان: <b>${esc(d.best_creative.headline)}</b></div>` : ''}
            ${d.actions_taken.length ? `<div style="margin-top:8px;"><b>إجراءات اتخذها Autopilot:</b><ul>${d.actions_taken.slice(0, 5).map(a => `<li>${esc(a.action_type)} - ${esc(a.description)}</li>`).join('')}</ul></div>` : ''}
        `;
    };

    loadTrendChart();
    loadAdsReport();
})();
