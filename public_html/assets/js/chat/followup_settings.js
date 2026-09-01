(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    let steps = [];

    function websiteId() { return P.getCurrentWebsiteId(); }

    function ensureWebsite() {
        const id = websiteId();
        document.getElementById('fuNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('fuBody').style.display = id ? 'block' : 'none';
        return id;
    }

    function renderSteps() {
        const container = document.getElementById('fuSteps');
        if (!steps.length) {
            container.innerHTML = '<div class="p-cell-muted">مفيش خطوات لسه - أضف خطوة عشان المتابعة التلقائية تشتغل.</div>';
            return;
        }
        container.innerHTML = steps.map((s, i) => `
            <div class="p-card" style="background:var(--panel-bg,#f7f8fa);padding:12px;margin-bottom:10px;">
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <strong>الخطوة ${i + 1}</strong>
                    <label class="form-label" style="margin:0;">بعد</label>
                    <input type="number" class="form-control" style="max-width:90px;" value="${s.after_hours}" onchange="fuUpdateStep(${i}, 'after_hours', this.value)">
                    <span class="p-cell-muted">ساعة من آخر رسالة للعميل</span>
                    <div style="flex:1;"></div>
                    <button class="p-btn danger xs" onclick="fuRemoveStep(${i})">حذف</button>
                </div>
                <div class="form-group" style="margin-top:8px;margin-bottom:0;">
                    <label class="form-label">نص الرسالة (استخدم {name} لاسم العميل)</label>
                    <textarea class="form-control" rows="2" onchange="fuUpdateStep(${i}, 'template', this.value)">${esc(s.template || '')}</textarea>
                </div>
            </div>`).join('');
    }

    window.fuAddStep = function () {
        steps.push({ after_hours: 24, template: 'مرحبًا {name}، مجرد تذكير - هل ما زلت مهتمًا؟' });
        renderSteps();
    };
    window.fuRemoveStep = function (i) { steps.splice(i, 1); renderSteps(); };
    window.fuUpdateStep = function (i, field, value) {
        steps[i][field] = field === 'after_hours' ? parseFloat(value) : value;
    };

    window.fuSave = async function () {
        const id = ensureWebsite();
        if (!id) return;
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/followup-settings', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                is_enabled: document.getElementById('fuEnabled').checked,
                max_followups: parseInt(document.getElementById('fuMax').value, 10) || 3,
                steps: steps,
            }),
        });
        if (res.success) toast('تم حفظ الإعدادات', 'success');
        else toast(res.error || 'فشل الحفظ', 'error');
    };

    async function load() {
        const id = ensureWebsite();
        if (!id) return;
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/followup-settings');
        if (!res.success) { toast(res.error || 'تعذر التحميل', 'error'); return; }
        const s = res.data.settings || {};
        document.getElementById('fuEnabled').checked = !!s.is_enabled;
        document.getElementById('fuMax').value = s.max_followups || 3;
        steps = Array.isArray(s.steps) ? s.steps : [];
        renderSteps();
    }

    window.addEventListener('tourfecto:website-changed', load);
    load();
})();
