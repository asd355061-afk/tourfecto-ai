(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;
    let selectedCompetitorId = null;

    async function loadCompetitors() {
        const box = document.getElementById('competitorSelectorBox');
        const res = await fetchJSON('/api/ads/competitors');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر تحميل قائمة المنافسين</div>'; return; }
        if (!res.data.length) {
            box.innerHTML = '<div class="p-cell-muted">لا يوجد منافسون مسجّلون بعد - أضف منافس أولًا من صفحة "مراقبة المنافسين".</div>';
            return;
        }

        box.innerHTML = `
            <select id="competitorSelect" class="p-select" style="width:100%;margin-bottom:8px;">
                ${res.data.map(c => `<option value="${c.id}">${esc(c.competitor_name || c.competitor_domain)}</option>`).join('')}
            </select>
            <textarea id="offerDesc" class="p-select" style="width:100%;min-height:60px;margin-bottom:8px;" placeholder="وصف مختصر لعرضك الإعلاني الحالي"></textarea>
            <button class="p-btn primary xs" onclick="analyzeCompetitor()">تحليل</button>
        `;
    }

    window.analyzeCompetitor = async function () {
        const competitorId = document.getElementById('competitorSelect').value;
        const offerDescription = document.getElementById('offerDesc').value.trim();
        if (!offerDescription) { P.toast('اكتب وصف العرض الأول', 'error'); return; }

        const card = document.getElementById('competitorInsightsCard');
        const box = document.getElementById('competitorInsightsBox');
        card.style.display = 'block';
        box.innerHTML = '<div class="p-loading-row">جارِ التحليل...</div>';

        const res = await fetchJSON('/api/ads/competitors/' + competitorId + '/analyze', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ offer_description: offerDescription }),
        });
        if (!res.success) { box.innerHTML = `<span style="color:#b91c1c;">${esc(res.error || 'تعذر التحليل')}</span>`; return; }

        renderInsights(res.data.recommendations, res.data.disclaimer);
    };

    function renderInsights(recs, disclaimer) {
        const box = document.getElementById('competitorInsightsBox');
        if (!recs || !recs.length) { box.innerHTML = '<div class="p-cell-muted">لا توجد توصيات</div>'; return; }
        box.innerHTML = recs.map(r => `
            <div style="padding:8px 0;border-bottom:1px solid var(--border-color, #eee);">
                <span class="pill ${r.priority === 'high' ? 'red' : (r.priority === 'medium' ? 'yellow' : 'gray')}">${esc(r.priority)}</span>
                ${esc(r.text)}
            </div>`).join('') + (disclaimer ? `<div class="p-cell-muted" style="font-size:11px;margin-top:8px;">${esc(disclaimer)}</div>` : '');
    }

    loadCompetitors();
})();
