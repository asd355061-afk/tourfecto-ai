(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON;

    async function load() {
        const res = await fetchJSON('/api/crm/overview');
        if (!res.success) return;

        document.getElementById('crmStats').innerHTML = `
            <div class="p-card stat-tile"><div class="stat-icon blue">🌐</div><div class="stat-info"><div class="stat-value">${esc(res.data.total_websites)}</div><div class="stat-label">${I18N['crm.stat.websites']}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon green">⭐</div><div class="stat-info"><div class="stat-value">${esc(res.data.total_reviews)}</div><div class="stat-label">${I18N['crm.stat.total_reviews']}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon orange">📈</div><div class="stat-info"><div class="stat-value">${esc(res.data.avg_rating || '-')}</div><div class="stat-label">${I18N['crm.stat.avg_rating']}</div></div></div>
        `;

        const tbody = document.querySelector('#crmTable tbody');
        if (res.data.websites && res.data.websites.length) {
            tbody.innerHTML = res.data.websites.map(w => `
                <tr><td>${esc(w.brand_name || w.domain)}</td><td>${esc(w.review_count || 0)}</td><td>${w.avg_rating ? esc(w.avg_rating) + ' ⭐' : '-'}</td><td class="p-cell-muted">${esc(w.created_at || '-')}</td></tr>
            `).join('');
        } else {
            tbody.innerHTML = `<tr><td colspan="4" class="p-cell-muted text-center">${I18N['crm.no_data']}</td></tr>`;
        }
    }
    load();
})();