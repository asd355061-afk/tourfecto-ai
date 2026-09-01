(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    const dash = (val, suffix) => (val === null || val === undefined) ? I18N['crm.reports.not_enough_data'] : (esc(val) + (suffix || ''));

    window.askAssistant = async function (presetQuestion) {
        const input = document.getElementById('assistantInput');
        const question = presetQuestion || input.value.trim();
        if (!question) return;
        input.value = question;
        const box = document.getElementById('assistantAnswer');
        box.textContent = I18N['common.loading'];
        const res = await fetchJSON('/api/crm/assistant/ask', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ question }),
        });
        box.textContent = res.success ? res.data.answer : (res.error || I18N['crm.ai.assistant_failed']);
    };

    async function loadForecast() {
        const res = await fetchJSON('/api/crm/forecast');
        const box = document.getElementById('forecastBox');
        if (!res.success) { box.textContent = res.error || '-'; return; }
        const f = res.data;
        let html = `<div class="p-cell-muted" style="margin-bottom:8px;">${I18N['crm.forecast.disclaimer']}</div>`;
        html += `<div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:12px;">
            <div><strong>${esc(f.expected_pipeline.weighted_value)}</strong><div class="p-cell-muted" style="font-size:12px;">${I18N['crm.forecast.weighted_pipeline']}</div></div>
            <div><strong>${esc(f.potential_revenue.value)}</strong><div class="p-cell-muted" style="font-size:12px;">${I18N['crm.forecast.potential_revenue']}</div></div>
            <div><strong>${f.likely_wins.length}</strong><div class="p-cell-muted" style="font-size:12px;">${I18N['crm.forecast.likely_wins']}</div></div>
            <div><strong>${f.at_risk_deals.length}</strong><div class="p-cell-muted" style="font-size:12px;">${I18N['crm.forecast.at_risk']}</div></div>
        </div>`;
        html += `<div class="p-cell-muted" style="font-size:12.5px;">${esc(f.forecast_confidence_note)}</div>`;
        box.innerHTML = html;
    }

    async function load() {
        const res = await fetchJSON('/api/crm/dashboard/stats');
        if (!res.success) return;
        const s = res.data;

        document.getElementById('crmReportStats').innerHTML = `
            <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${esc(s.total_leads)}</div><div class="stat-label">${I18N['crm.reports.total_leads']}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${dash(s.conversion_rate, '%')}</div><div class="stat-label">${I18N['crm.reports.conversion_rate']}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${esc(s.pipeline_value)}</div><div class="stat-label">${I18N['crm.reports.pipeline_value']}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${esc(s.won_deals)}</div><div class="stat-label">${I18N['crm.reports.won_deals']}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${esc(s.overdue_follow_ups)}</div><div class="stat-label">${I18N['crm.reports.overdue_follow_ups']}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${esc(s.overdue_tasks)}</div><div class="stat-label">${I18N['crm.reports.overdue_tasks']}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${dash(s.average_deal_value)}</div><div class="stat-label">${I18N['crm.reports.avg_deal_value']}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${dash(s.average_sales_cycle_days, ' ' + I18N['crm.reports.days'])}</div><div class="stat-label">${I18N['crm.reports.sales_cycle']}</div></div></div>
        `;

        document.getElementById('reportBySource').innerHTML = s.lead_sources.length
            ? s.lead_sources.map(r => `<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border,#eee);"><span>${esc(r.source)}</span><strong>${esc(r.total)}</strong></div>`).join('')
            : I18N['crm.reports.not_enough_data'];

        document.getElementById('reportByRep').innerHTML = s.sales_rep_performance.length
            ? s.sales_rep_performance.map(r => `<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border,#eee);"><span>${esc((r.first_name||'') + ' ' + (r.last_name||''))}</span><strong>${esc(r.deals_count)} · ${esc(r.won_value)}</strong></div>`).join('')
            : I18N['crm.reports.not_enough_data'];
    }
    load();
    loadForecast();
})();