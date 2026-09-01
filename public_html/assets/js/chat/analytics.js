(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON;
    let convChart = null;

    function ic(name, cls) {
        return '<svg class="ic ' + (cls || '') + '" aria-hidden="true"><use href="#i-' + name + '"/></svg>';
    }

    function websiteId() { return P.getCurrentWebsiteId(); }

    function ensureWebsite() {
        const id = websiteId();
        document.getElementById('anNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('anBody').style.display = id ? 'block' : 'none';
        return id;
    }

    function statTile(label, value) {
        return `<div class="p-card" style="text-align:center;padding:16px;">
            <div style="font-size:22px;font-weight:800;">${value}</div>
            <div class="p-cell-muted" style="font-size:11.5px;">${label}</div>
        </div>`;
    }

    function healthPill(provider, configured, status24h) {
        if (!configured) return '<div class="p-kv"><span class="k">' + esc(provider) + '</span><span class="v"><span class="pill gray">غير مهيّأ</span></span></div>';
        let pill;
        if (status24h === 'healthy') pill = '<span class="pill green">' + ic('check','ic-sm') + ' سليم</span>';
        else if (status24h === 'degraded') pill = '<span class="pill red">' + ic('alert','ic-sm') + ' متدهور</span>';
        else pill = '<span class="pill">لا بيانات بعد</span>';
        return '<div class="p-kv"><span class="k">' + esc(provider) + '</span><span class="v">' + pill + '</span></div>';
    }

    function renderHealth(health) {
        const box = document.getElementById('anHealth');
        if (!health || !Array.isArray(health.providers)) {
            box.innerHTML = '<div class="p-cell-muted">لا توجد بيانات صحة متاحة</div>';
            return;
        }
        const per = {};
        (health.summary_last_24h && health.summary_last_24h.per_provider || []).forEach(p => { per[p.provider] = p; });

        let html = '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">';
        if (health.status === 'healthy') html += '<span class="pill green">✓ الحالة العامة: سليمة</span>';
        else if (health.status === 'degraded') html += '<span class="pill red">⚠ الحالة العامة: متدهورة (فشل في بعض الطلبات)</span>';
        else html += '<span class="pill">الحالة العامة: لا استخدام مسجّل بعد</span>';
        html += '</div>';

        health.providers.forEach(p => {
            const s = per[p.provider];
            const status24h = s ? (s.failed_requests > 0 ? 'degraded' : 'healthy') : 'no_data';
            html += healthPill(p.provider, !!p.configured, status24h);
            if (p.configured) {
                html += '<div class="p-kv"><span class="k">النموذج</span><span class="v">' + esc(p.model || '-') + '</span></div>';
                html += '<div class="p-kv"><span class="k">ترتيب الأفضلية</span><span class="v">#' + (p.priority_position || '-') + '</span></div>';
                if (s) {
                    html += '<div class="p-kv"><span class="k">طلبات (24h)</span><span class="v">' + s.total_requests + ' · ' + s.failed_requests + ' فاشلة</span></div>';
                }
            }
        });

        const s = health.summary_last_24h;
        if (s && s.total_requests > 0) {
            html += '<div style="margin-top:10px;padding:10px;background:var(--panel-sidebar-bg-hover);border-radius:8px;">'
                + '<strong>إجمالي آخر 24 ساعة:</strong> ' + s.total_requests + ' طلب · '
                + s.failed_requests + ' فاشل · ' + (s.fallback_used_count || 0) + ' Fallback · '
                + (s.total_tokens || 0).toLocaleString() + ' token · $' + parseFloat(s.total_cost_usd || 0).toFixed(4)
                + '</div>';
        }
        box.innerHTML = html;
    }

    function renderLearning(learning) {
        const box = document.getElementById('anLearning');
        if (!learning) { box.innerHTML = '<div class="p-cell-muted">لا توجد بيانات حلقة تعلّم بعد</div>'; return; }

        const breakdown = learning.resolution_events || {};
        const total = Object.values(breakdown).reduce((a, b) => a + b, 0);
        let html = '<div class="p-kv"><span class="k">معدّل حلّ الذكاء الاصطناعي</span><span class="v"><strong>' + (learning.ai_resolution_rate_percent || 0) + '%</strong></span></div>';
        html += '<div class="p-kv"><span class="k">أحداث مسجّلة</span><span class="v">' + total + '</span></div>';
        if (total > 0) {
            const labels = {
                ai_resolved: 'حلّها الذكاء الاصطناعي',
                human_resolved: 'حلّها موظف',
                abandoned: 'ترَك العميل',
                reopened: 'أُعيد فتحها',
            };
            const keys = Object.keys(labels);
            html += '<div style="display:flex;flex-direction:column;gap:6px;margin-top:8px;">';
            keys.forEach(k => {
                const n = breakdown[k] || 0;
                if (n <= 0) return;
                const pct = Math.round((n / total) * 100);
                html += '<div style="display:flex;align-items:center;gap:8px;">'
                    + '<span style="width:130px;font-size:12px;">' + labels[k] + '</span>'
                    + '<div style="flex:1;background:var(--panel-sidebar-bg-hover);border-radius:6px;height:8px;overflow:hidden;">'
                    + '<div style="width:' + pct + '%;height:100%;background:var(--panel-teal);border-radius:6px;"></div></div>'
                    + '<span style="width:44px;text-align:left;font-size:12px;">' + n + '</span></div>';
            });
            html += '</div>';
        }
        const gaps = learning.knowledge_gaps || [];
        if (gaps.length) {
            html += '<div style="margin-top:10px;"><strong>أبرز فجوات المعرفة:</strong></div>';
            html += gaps.slice(0, 3).map(g =>
                '<div class="p-kv"><span class="k">' + esc(g.question || g.normalized_question || '-') + '</span><span class="v">×' + (g.occurrence_count || 1) + '</span></div>'
            ).join('');
            html += '<a href="/chat/learning" class="p-btn outline xs" style="margin-top:8px;">مراجعة الفجوات كلها</a>';
        }
        box.innerHTML = html;
    }

    function renderEscalations(escalations) {
        const box = document.getElementById('anEscalations');
        const reasons = escalations || {};
        const keys = Object.keys(reasons);
        box.innerHTML = keys.length
            ? keys.map(r => {
                let label = r;
                const map = {
                    outside_knowledge_base: 'خارج قاعدة المعرفة',
                    low_ai_confidence: 'ثقة AI منخفضة',
                    ai_requested_handoff: 'طلب الـAI التحويل',
                    manual_takeover: 'تدخل يدوي',
                    customer_escalated: 'طلب العميل',
                    'no_provider_configured': 'لا يوجد مزود مهيّأ',
                };
                label = map[r] || r;
                return '<div class="p-kv"><span class="k">' + esc(label) + '</span><span class="v">' + reasons[r] + '</span></div>';
            }).join('')
            : '<div class="p-cell-muted">لا توجد أسباب تحويل مسجّلة</div>';
    }

    window.load = async function () {
        const id = ensureWebsite();
        if (!id) return;
        const days = document.getElementById('anSince').value;
        const since = new Date(Date.now() - days * 86400000).toISOString().slice(0, 10);

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/analytics?since=' + since);
        if (!res.success) {
            document.getElementById('anStats').innerHTML = '<div class="p-cell-muted">⚠️ ' + esc(res.error || 'تعذر التحميل') + '</div>';
            return;
        }

        const d = res.data.dashboard;
        document.getElementById('anStats').innerHTML = [
            statTile('إجمالي المحادثات', d.total_conversations),
            statTile('ردّ الذكاء الاصطناعي', d.ai_conversations),
            statTile('تحويل لموظف', d.human_conversations),
            statTile('Leads جديدة', d.leads_generated),
            statTile('Leads ساخنة 🔥', d.hot_leads),
            statTile('نسبة التحويل', d.conversion_rate_percent + '%'),
            statTile('معدّل حلّ الذكاء الاصطناعي', d.ai_resolution_rate_percent + '%'),
            statTile('نسبة التحويل لموظف', d.human_handoff_rate_percent + '%'),
            statTile('نجاح المتابعات', d.followup_success_rate_percent + '%'),
        ].join('');

        const tags = d.top_tags || {};
        const tagKeys = Object.keys(tags);
        document.getElementById('anTags').innerHTML = tagKeys.length
            ? tagKeys.map(t => `<div class="p-kv"><span class="k">${esc(t)}</span><span class="v">${tags[t]}</span></div>`).join('')
            : '<div class="p-cell-muted">لا توجد بيانات كافية بعد</div>';

        const services = d.most_popular_services || {};
        const serviceKeys = Object.keys(services);
        document.getElementById('anServices').innerHTML = serviceKeys.length
            ? serviceKeys.map(s => `<div class="p-kv"><span class="k">${esc(s)}</span><span class="v">${services[s]}</span></div>`).join('')
            : '<div class="p-cell-muted">لا توجد بيانات كافية بعد</div>';

        if (typeof Chart !== 'undefined') {
            const conv = { ai: d.ai_conversations || 0, human: d.human_conversations || 0 };
            if (convChart) convChart.destroy();
            convChart = new Chart(document.getElementById('anConvChart'), {
                type: 'doughnut',
                data: {
                    labels: ['ردّ الذكاء الاصطناعي', 'تحويل لموظف'],
                    datasets: [{
                        data: [conv.ai, conv.human],
                        backgroundColor: ['#4ECDC4', '#EFB05E'],
                        borderColor: '#0F1A2C',
                        borderWidth: 2,
                    }],
                },
                options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { color: '#8996AC' } } } },
            });
        }

        renderHealth(res.data.provider_health);
        renderLearning(res.data.learning_loop);
        renderEscalations(res.data.learning_loop ? res.data.learning_loop.escalation_reasons : {});

        const providers = d.ai_usage_by_provider || [];
        const tbody = document.querySelector('#anProviders tbody');
        tbody.innerHTML = providers.length
            ? providers.map(p => `<tr>
                <td>${esc(p.provider)}</td>
                <td class="p-cell-muted">${esc(p.model || '-')}</td>
                <td>${p.total_requests}</td>
                <td>${p.total_requests - (p.failed_requests || 0)}</td>
                <td>${p.failed_requests || 0}</td>
                <td>${p.fallback_used_count || 0}</td>
                <td>${(p.total_tokens || 0).toLocaleString()}</td>
                <td>$${parseFloat(p.total_cost_usd || 0).toFixed(4)}</td>
            </tr>`).join('')
            : '<tr><td colspan="8" class="p-cell-muted text-center">لا يوجد استخدام مسجَّل بعد</td></tr>';
    };

    window.addEventListener('tourfecto:website-changed', load);
    load();
})();
