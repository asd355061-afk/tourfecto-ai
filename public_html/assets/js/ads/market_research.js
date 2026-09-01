(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;
    const colors = { high: 'green', medium: 'yellow', low: 'gray' };

    function renderCountries(countries, disclaimer) {
        return countries.map(c => `
            <div style="padding:8px 0;border-bottom:1px solid var(--border-color, #eee);">
                <span class="pill ${colors[c.opportunity] || ''}">${esc(c.opportunity)}</span> <b>${esc(c.country)}</b>
                <div class="p-cell-muted" style="font-size:12px;">${esc(c.reasoning)}</div>
            </div>
        `).join('') + `<div class="p-cell-muted" style="font-size:11px;margin-top:6px;">${esc(disclaimer)}</div>`;
    }

    window.runMarketResearch = async function () {
        const box = document.getElementById('marketResearchResults');
        box.innerHTML = 'جارِ التحليل...';
        const goalDescription = document.getElementById('mrGoalDesc').value.trim();
        if (!goalDescription) { box.innerHTML = '<span style="color:#b91c1c;">اكتب وصف العرض الأول</span>'; return; }

        const res = await fetchJSON('/api/ads/market-research', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ goal_description: goalDescription }),
        });
        if (!res.success) { box.innerHTML = `<span style="color:#b91c1c;">${esc(res.error || 'تعذر التحليل')}</span>`; return; }
        box.innerHTML = renderCountries(res.data.countries, res.data.disclaimer);
        loadHistory();
    };

    async function loadHistory() {
        const box = document.getElementById('mrHistoryBox');
        const res = await fetchJSON('/api/ads/market-research/history');
        if (!res.success || !res.data.length) { box.innerHTML = '<div class="p-cell-muted">لا يوجد تحليلات سابقة بعد</div>'; return; }
        box.innerHTML = res.data.map(h => `
            <div style="padding:10px 0;border-bottom:1px solid var(--border-color, #eee);">
                <div class="p-cell-muted" style="font-size:11px;">${esc(h.created_at)}</div>
                <div style="margin:4px 0;">${esc(h.goal_description)}</div>
                ${h.result_json && h.result_json.countries ? renderCountries(h.result_json.countries, h.result_json.disclaimer) : ''}
            </div>`).join('');
    }

    loadHistory();
})();
