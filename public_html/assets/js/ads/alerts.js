(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;

    const RULE_LABELS = {
        budget_exhausted: 'نفاد الميزانية اليومية',
        cpc_spike: 'ارتفاع تكلفة النقرة',
        ctr_drop: 'انخفاض نسبة النقر',
        landing_page_down: 'صفحة هبوط معطّلة',
        budget_pacing: 'إنفاق أبطأ من المتوقع',
    };
    const RULE_HINTS = {
        budget_exhausted: 'أشعرني لما يصرف % من ميزانيته اليومية',
        cpc_spike: 'نسبة زيادة عن متوسط الأسبوع السابق',
        ctr_drop: 'نسبة انخفاض عن متوسط الأسبوع السابق',
        landing_page_down: 'الفحص بدون حد نسبة',
        budget_pacing: 'نسبة اليوم المنقضي',
    };

    async function loadAlerts() {
        const res = await fetchJSON('/api/ads/alerts');
        const box = document.getElementById('alertsList');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحميل</div>'; return; }

        const severityIcon = { info: 'ℹ️', warning: '⚠️', critical: '🚨' };
        const severityColor = { info: 'var(--info-color, #1890ff)', warning: 'var(--warning-color, #fa8c16)', critical: 'var(--danger-color, #f5222d)' };

        if (!res.data.alerts.length) {
            box.innerHTML = '<div class="p-cell-muted">مفيش تنبيهات حالياً - كل الحملات داخل الحدود الطبيعية 🎉</div>';
            return;
        }
        box.innerHTML = res.data.alerts.map(a => `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-color, #eee);">
                <div style="flex:1;">
                    <div style="color:${severityColor[a.severity] || '#666'};font-weight:bold;">${severityIcon[a.severity] || 'ℹ️'} ${esc(a.title)} <span class="p-cell-muted" style="font-weight:normal;font-size:11px;">${esc(RULE_LABELS[a.rule_type] || a.rule_type)}</span></div>
                    <div class="p-cell-muted" style="font-size:12px;margin-top:2px;">${esc(a.body || '')}</div>
                </div>
                <button class="p-btn xs" onclick="dismissAlert(${a.id})">تجاهل</button>
            </div>`).join('');
    }

    async function loadRules() {
        const res = await fetchJSON('/api/ads/alerts/rules');
        const box = document.getElementById('rulesBox');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحميل</div>'; return; }

        box.innerHTML = Object.entries(res.data.rules).map(([type, rule]) => `
            <div style="display:grid;grid-template-columns:auto 1fr auto;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-color, #eee);">
                <input type="checkbox" id="rule-${type}" ${rule.is_enabled ? 'checked' : ''} onchange="saveRules()" style="transform:scale(1.2);">
                <div>
                    <div><b>${esc(RULE_LABELS[type] || type)}</b></div>
                    <div class="p-cell-muted" style="font-size:11px;">${esc(RULE_HINTS[type] || '')}</div>
                </div>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="number" id="rule-val-${type}" class="p-select xs" style="width:90px;" value="${rule.threshold_value ?? ''}" min="1" max="999" step="1" placeholder="—" onchange="saveRules()">
                    <span class="p-cell-muted" style="font-size:11px;">%</span>
                </div>
            </div>`).join('');
        box.innerHTML += '<div style="margin-top:10px;"><button class="p-btn primary" onclick="saveRules()">حفظ القواعد</button></div>';
    }

    window.runAlertsNow = async function () {
        const res = await fetchJSON('/api/ads/alerts/run', { method: 'POST' });
        if (res.success) { P.toast('تم التقييم - ' + res.data.generated + ' تنبيه جديد', 'success'); loadAlerts(); }
        else P.toast(res.error || 'تعذر التقييم', 'error');
    };

    window.markAllRead = async function () {
        const res = await fetchJSON('/api/ads/alerts/read-all', { method: 'POST' });
        if (res.success) P.toast('تم تعليم الكل كمقروء', 'success');
    };

    window.dismissAlert = async function (id) {
        const res = await fetchJSON('/api/ads/alerts/' + id + '/dismiss', { method: 'POST' });
        if (res.success) { P.toast('تم تجاهل التنبيه', 'success'); loadAlerts(); }
        else P.toast(res.error || 'تعذر التجاهل', 'error');
    };

    window.saveRules = async function () {
        const types = Object.keys(RULE_LABELS);
        const rules = {};
        types.forEach(t => {
            rules[t] = {
                is_enabled: document.getElementById('rule-' + t).checked ? 1 : 0,
                threshold_value: document.getElementById('rule-val-' + t).value || null,
            };
        });
        const res = await fetchJSON('/api/ads/alerts/rules', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ rules }),
        });
        if (res.success) P.toast('تم حفظ القواعد', 'success');
        else P.toast(res.error || 'تعذر الحفظ', 'error');
    };

    loadAlerts();
    loadRules();
})();
