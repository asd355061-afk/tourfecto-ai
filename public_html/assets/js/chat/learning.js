(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;

    const STATUS_PILL = {
        new: '<span class="pill red">جديدة</span>',
        acknowledged: '<span class="pill">تمت الملاحظة</span>',
        added_to_kb: '<span class="pill green">أُضيفت للمعرفة</span>',
        dismissed: '<span class="pill gray">متجاهلة</span>',
    };
    const REASON_LABEL = {
        outside_knowledge_base: 'خارج قاعدة المعرفة',
        low_ai_confidence: 'ثقة AI منخفضة',
        ai_requested_handoff: 'طلب الـAI التحويل',
        manual_takeover: 'تدخل يدوي',
        customer_escalated: 'طلب العميل',
    };

    function websiteId() { return P.getCurrentWebsiteId(); }

    function ensureWebsite() {
        const id = websiteId();
        document.getElementById('lnNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('lnBody').style.display = id ? 'block' : 'none';
        return id;
    }

    window.lnScan = async function () {
        const id = ensureWebsite();
        if (!id) return;
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/learning/gaps/scan', { method: 'POST' });
        if (res.success) {
            toast('تم المسح: ' + (res.data && res.data.new_gaps_recorded != null ? res.data.new_gaps_recorded + ' فجوة جديدة' : 'بدون فجوات جديدة'), 'success');
            load();
        } else {
            toast(res.error || 'فشل المسح', 'error');
        }
    };

    window.lnSetStatus = async function (gapId, status) {
        const id = websiteId();
        if (!id) return;
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/learning/gaps/' + gapId + '/status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: status }),
        });
        if (res.success) { toast('تم تحديث حالة الفجوة', 'success'); load(); }
        else { toast(res.error || 'فشل التحديث', 'error'); }
    };

    window.lnAddToKb = async function (gapId, question) {
        const id = websiteId();
        if (!id) return;
        const content = prompt('اكتب إجابة الفجوة لتُضاف لقاعدة المعرفة:', '');
        if (content === null) return;
        if (!content.trim()) { toast('الإجابة مطلوبة', 'error'); return; }

        const addRes = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                section: 'faq',
                title: question,
                content: content.trim(),
                language: 'en',
                priority: 1,
            }),
        });
        if (!addRes.success) { toast(addRes.error || 'فشلت الإضافة', 'error'); return; }

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/learning/gaps/' + gapId + '/status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: 'added_to_kb' }),
        });
        if (res.success) { toast('تمت إضافة الإجابة لقاعدة المعرفة وإغلاق الفجوة', 'success'); load(); }
        else { toast('أُضيفت للمعرفة لكن فشل تحديث الحالة', 'error'); load(); }
    };

    async function load() {
        const id = ensureWebsite();
        if (!id) return;
        const days = document.getElementById('lnSince').value;
        const since = new Date(Date.now() - days * 86400000).toISOString().slice(0, 10);

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/learning/gaps?since=' + since);
        if (!res.success) {
            document.querySelector('#lnTable tbody').innerHTML = '<tr><td colspan="7" class="p-cell-muted text-center">⚠️ ' + esc(res.error || 'تعذر التحميل') + '</td></tr>';
            return;
        }

        const gaps = (res.data && Array.isArray(res.data.knowledge_gaps)) ? res.data.knowledge_gaps : [];
        const summary = (res.data && res.data.summary) || {};

        document.getElementById('lnResRate').textContent = (summary.ai_resolution_rate_percent != null ? summary.ai_resolution_rate_percent : '-') + '%';
        const unresolved = gaps.filter(g => g.status === 'new' || g.status === 'acknowledged').length;
        document.getElementById('lnGapCount').textContent = unresolved || 0;

        const tbody = document.querySelector('#lnTable tbody');
        if (!gaps.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="p-cell-muted text-center">لا توجد فجوات معرفة في هذه الفترة — الـAI يرد على كل الأسئلة 🎉</td></tr>';
            return;
        }

        tbody.innerHTML = gaps.map(g => {
            const actions = [];
            if (g.status === 'new' || g.status === 'acknowledged') {
                actions.push('<button class="p-btn outline xs" onclick="lnSetStatus(' + g.id + ', \'acknowledged\')">👁 ملاحظة</button>');
                actions.push('<button class="p-btn primary xs" onclick="lnAddToKb(' + g.id + ', \'' + esc(String(g.question || '')).replace(/'/g, "\\'") + '\')">📚 أضِف للمعرفة</button>');
                actions.push('<button class="p-btn outline xs" onclick="lnSetStatus(' + g.id + ', \'dismissed\')">✖ تجاهل</button>');
            } else {
                actions.push('<button class="p-btn outline xs" onclick="lnSetStatus(' + g.id + ', \'new\')">↺ إعادة فتح</button>');
            }
            return `<tr>
                <td><div style="max-width:340px;">${esc(g.question || g.normalized_question || '-')}</div></td>
                <td><span class="pill gray">${esc(g.language === 'ar' ? 'عربي' : (g.language || '-'))}</span></td>
                <td class="p-cell-muted">${esc(REASON_LABEL[g.handoff_reason] || g.handoff_reason || '-')}</td>
                <td><strong>×${g.occurrence_count || 1}</strong></td>
                <td>${STATUS_PILL[g.status] || esc(g.status || '-')}</td>
                <td class="p-cell-muted">${P.formatDate(g.last_seen_at)}</td>
                <td style="white-space:nowrap;">${actions.join(' ')}</td>
            </tr>`;
        }).join('');
    }

    window.addEventListener('tourfecto:website-changed', load);
    load();
})();
