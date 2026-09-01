(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;

    async function loadAutopilotSettings() {
        const res = await fetchJSON('/api/ads/autopilot/settings');
        const box = document.getElementById('autopilotSettingsBox');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر تحميل الإعدادات</div>'; return; }
        const s = res.data;
        const modeLabels = { manual: 'يدوي - توصيات فقط', approval: 'موافقة - تنفيذ بعد موافقتك', autopilot: 'تلقائي - تنفيذ داخل الحدود' };
        box.innerHTML = `
            <label>وضع التشغيل</label>
            <select id="apMode" class="p-select" style="width:100%;margin-bottom:12px;">
                ${Object.entries(modeLabels).map(([k, l]) => `<option value="${k}" ${s.optimization_mode === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}
            </select>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
                <div><label>أقصى ميزانية يومية إجمالية</label><input type="number" id="apMaxDaily" class="p-select" style="width:100%;" value="${s.max_daily_budget ?? ''}"></div>
                <div><label>أقصى تكلفة اكتساب (CPA) مقبولة</label><input type="number" id="apMaxCpa" class="p-select" style="width:100%;" value="${s.max_allowed_cpa ?? ''}"></div>
                <div><label>أقل ROAS مقبول</label><input type="number" id="apMinRoas" class="p-select" style="width:100%;" value="${s.min_required_roas ?? ''}"></div>
                <div><label>أقصى عدد تغييرات تلقائية/يوم</label><input type="number" id="apMaxChanges" class="p-select" style="width:100%;" value="${s.max_changes_per_day}"></div>
                <div><label>أقصى نسبة زيادة ميزانية لكل قرار %</label><input type="number" id="apIncPct" class="p-select" style="width:100%;" value="${s.max_budget_increase_pct}"></div>
                <div><label>أقصى نسبة تخفيض ميزانية لكل قرار %</label><input type="number" id="apDecPct" class="p-select" style="width:100%;" value="${s.max_budget_decrease_pct}"></div>
            </div>
            <div class="p-cell-muted" style="font-size:11.5px;margin-bottom:10px;">في وضع "تلقائي": أي قرار يتجاوز الحدود دي ما بيتنفذش تلقائيًا أبدًا - بيتحوّل لقسم "قرارات بانتظار الموافقة" فوق.</div>
            <button class="p-btn primary" onclick="saveAutopilotSettings()">حفظ الإعدادات</button>
        `;
    }

    window.saveAutopilotSettings = async function () {
        const payload = {
            optimization_mode: document.getElementById('apMode').value,
            max_daily_budget: document.getElementById('apMaxDaily').value || null,
            max_allowed_cpa: document.getElementById('apMaxCpa').value || null,
            min_required_roas: document.getElementById('apMinRoas').value || null,
            max_changes_per_day: document.getElementById('apMaxChanges').value,
            max_budget_increase_pct: document.getElementById('apIncPct').value,
            max_budget_decrease_pct: document.getElementById('apDecPct').value,
        };
        const res = await fetchJSON('/api/ads/autopilot/settings', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        if (res.success) P.toast('تم حفظ إعدادات Autopilot', 'success');
        else P.toast(res.error || 'تعذر الحفظ', 'error');
    };

    async function loadPendingActions() {
        const res = await fetchJSON('/api/ads/autopilot/pending');
        const box = document.getElementById('pendingActionsBox');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحميل</div>'; return; }
        if (!res.data.length) { box.innerHTML = '<div class="p-cell-muted">لا توجد قرارات معلّقة حاليًا</div>'; return; }
        box.innerHTML = res.data.map(a => `
            <div class="p-card" style="margin-bottom:8px;padding:10px;">
                <div><b>${esc(a.action_type)}</b> - <a href="/ads/campaigns/${a.campaign_id}">حملة #${a.campaign_id}</a> ${a.before_value ? `(${esc(a.before_value)} ← ${esc(a.after_value)})` : ''}</div>
                <div class="p-cell-muted" style="font-size:12px;margin:4px 0;">${esc(a.reasoning)}</div>
                ${a.blocked_reason ? `<div class="p-cell-muted" style="font-size:11px;color:#b45309;">⚠ ${esc(a.blocked_reason)}</div>` : ''}
                <div style="display:flex;gap:8px;margin-top:6px;">
                    <button class="p-btn primary xs" onclick="decidePendingAction(${a.id}, 'approve')">✅ موافقة وتنفيذ</button>
                    <button class="p-btn outline xs" onclick="decidePendingAction(${a.id}, 'reject')">❌ رفض</button>
                </div>
            </div>`).join('');
    }

    window.decidePendingAction = async function (id, decision) {
        const res = await fetchJSON(`/api/ads/autopilot/pending/${id}/${decision}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast(decision === 'approve' ? 'تم التنفيذ' : 'تم الرفض', 'success'); loadPendingActions(); loadOptimizationLog(); }
        else P.toast(res.error || 'تعذر تنفيذ القرار', 'error');
    };

    async function loadOptimizationLog() {
        const res = await fetchJSON('/api/ads/autopilot/logs');
        const box = document.getElementById('optimizationLogBox');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحميل</div>'; return; }
        if (!res.data.length) { box.innerHTML = '<div class="p-cell-muted">لا يوجد سجل بعد</div>'; return; }
        box.innerHTML = res.data.map(l => `
            <div style="padding:8px 0;border-bottom:1px solid var(--border-color, #eee);">
                <div><b>${esc(l.action_type)}</b> <span class="p-cell-muted" style="font-size:11px;">(${esc(l.mode)} - ${l.applied_automatically == 1 ? 'نُفّذ فعليًا' : 'توصية فقط'})</span></div>
                ${l.before_value ? `<div class="p-cell-muted" style="font-size:12px;">${esc(l.before_value)} ← ${esc(l.after_value || '')}</div>` : ''}
                <div class="p-cell-muted" style="font-size:12px;">${esc(l.description)}</div>
                ${(l.can_rollback == 1 && !l.rolled_back_at) ? `<button class="p-btn outline xs" style="margin-top:4px;" onclick="rollbackLog(${l.id})">↩ تراجع</button>` : ''}
                ${l.rolled_back_at ? `<span class="p-cell-muted" style="font-size:11px;">تم التراجع عنه</span>` : ''}
            </div>`).join('');
    }

    window.rollbackLog = async function (id) {
        if (!confirm('متأكد من التراجع عن التغيير ده؟')) return;
        const res = await fetchJSON(`/api/ads/autopilot/logs/${id}/rollback`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('تم التراجع', 'success'); loadOptimizationLog(); }
        else P.toast(res.error || 'تعذر التراجع', 'error');
    };

    loadAutopilotSettings();
    loadPendingActions();
    loadOptimizationLog();
})();
