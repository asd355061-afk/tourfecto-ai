(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;
    const CAMPAIGN_ID = document.getElementById('campaignDetailsConfig').dataset.campaignId;
    let trendChart = null;

    async function loadOverview() {
        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID);
        const box = document.getElementById('campaignOverviewCard');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر تحميل بيانات الحملة</div>'; return; }
        const c = res.data.campaign;
        const statusPill = c.status === 'active' ? 'green' : (c.status === 'paused' ? 'gray' : 'yellow');
        box.innerHTML = `
            <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;align-items:center;">
                <div>
                    <h2 style="margin:0;">${esc(c.name || '')}</h2>
                    <div class="p-cell-muted">${esc(c.objective || '')} · <span class="pill ${statusPill}">${esc(c.status)}</span></div>
                </div>
                <div style="display:flex;gap:8px;">
                    ${c.status === 'active' ? `<button class="p-btn outline xs" onclick="toggleCampaignStatus('paused')">⏸ إيقاف</button>` : ''}
                    ${c.status === 'paused' ? `<button class="p-btn outline xs" onclick="toggleCampaignStatus('active')">▶ استئناف</button>` : ''}
                    <button class="p-btn danger xs" onclick="deleteCampaign()">🗑 حذف الحملة</button>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-top:16px;">
                <div><div class="p-cell-muted" style="font-size:11px;">الميزانية اليومية</div><div><b>${esc(c.daily_budget ?? '-')}</b></div></div>
                <div><div class="p-cell-muted" style="font-size:11px;">الإنفاق الكلي</div><div><b>${esc(c.spend ?? 0)}</b></div></div>
                <div><div class="p-cell-muted" style="font-size:11px;">النقرات</div><div><b>${esc(c.clicks ?? 0)}</b></div></div>
                <div><div class="p-cell-muted" style="font-size:11px;">الظهور</div><div><b>${esc(c.impressions ?? 0)}</b></div></div>
            </div>
        `;

        renderAudience(res.data.audience);
        if (c.landing_page_url) document.getElementById('lpUrl').value = c.landing_page_url;
        if (c.landing_page_last_analysis) renderLandingPageResult(c.landing_page_last_analysis);
        document.getElementById('utmDest').value = c.landing_page_url || '';
    }

    window.toggleCampaignStatus = async function (newStatus) {
        const actionLabel = newStatus === 'paused' ? 'إيقاف' : 'استئناف';
        if (!confirm('متأكد من ' + actionLabel + ' هذه الحملة على المنصة الإعلانية الحقيقية؟')) return;

        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID + '/status', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status: newStatus }),
        });
        if (res.success) {
            P.toast('تم ' + actionLabel + ' الحملة بنجاح', 'success');
            loadOverview();
            loadLog();
        } else {
            P.toast(res.error || 'تعذّر تنفيذ الإجراء', 'error');
        }
    };

    window.deleteCampaign = async function () {
        if (!confirm('متأكد من حذف هذه الحملة؟\n\nملحوظة: Meta/Google Ads مفيهمش حذف نهائي حقيقي - الحملة هتتوقف على المنصة (لو كانت شغّالة) وتتخفي من قوائم Tourfecto، لكن كل بيانات الأداء التاريخية هتفضل محفوظة.')) return;

        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID, { method: 'DELETE' });
        if (res.success) {
            P.toast('تم حذف الحملة', 'success');
            setTimeout(() => { window.location.href = '/ads'; }, 800);
        } else {
            P.toast(res.error || 'تعذّر الحذف', 'error');
        }
    };

    function renderAudience(a) {
        const box = document.getElementById('campaignAudienceBox');
        if (!a) { box.innerHTML = '<div class="p-cell-muted">لا يوجد جمهور محدد لهذه الحملة</div>'; return; }
        box.innerHTML = `
            <div><b>الفئة العمرية:</b> ${esc(a.age_min || '-')} - ${esc(a.age_max || '-')}</div>
            <div><b>الجنس:</b> ${esc(a.genders || 'الكل')}</div>
            <div><b>الدول:</b> ${(a.locations || []).map(l => `<span class="pill xs">${esc(l)}</span>`).join(' ') || '-'}</div>
            <div style="margin-top:6px;"><b>الاهتمامات:</b> ${(a.interests || []).map(i => `<span class="pill xs">${esc(i)}</span>`).join(' ') || '-'}</div>
        `;
    }

    function renderLandingPageResult(d) {
        const box = document.getElementById('lpResults');
        if (d.fetch_error) { box.innerHTML = `<span style="color:#b91c1c;">${esc(d.fetch_error)}</span>`; return; }
        box.innerHTML = `
            <div><b>Relevance:</b> ${esc(d.relevance || '-')}</div>
            <div><b>CTA:</b> ${esc(d.cta || '-')}</div>
            <div style="margin-top:6px;"><b>التوصيات:</b><ul>${(d.recommendations || []).map(r => `<li>${esc(r)}</li>`).join('')}</ul></div>
        `;
    }

    window.analyzeCampaignLandingPage = async function () {
        const box = document.getElementById('lpResults');
        box.innerHTML = 'جارِ التحليل...';
        const url = document.getElementById('lpUrl').value.trim();
        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID + '/landing-page/analyze', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ url }),
        });
        if (!res.success) { box.innerHTML = `<span style="color:#b91c1c;">${esc(res.error || 'تعذر التحليل')}</span>`; return; }
        renderLandingPageResult(res.data);
    };

    async function loadAdGroups() {
        const box = document.getElementById('adGroupsBox');
        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID + '/ad-groups');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر تحميل المجموعات الإعلانية</div>'; return; }
        if (!res.data.ad_groups.length) { box.innerHTML = '<div class="p-cell-muted">لا توجد مجموعات إعلانية بعد - أضف واحدة لتنظيم كلماتك/إعلاناتك</div>'; return; }

        box.innerHTML = res.data.ad_groups.map(g => `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border-color, #eee);">
                <div>
                    <b>${esc(g.name)}</b> <span class="pill xs ${g.status === 'active' ? 'green' : 'gray'}">${esc(g.status)}</span>
                    <div class="p-cell-muted" style="font-size:11px;">🔑 ${g.keywords_count} كلمة مفتاحية · ✍️ ${g.ads_count} إعلان</div>
                </div>
                <div style="display:flex;gap:6px;">
                    <button class="p-btn outline xs" onclick="toggleAdGroupStatus(${g.id}, '${g.status === 'active' ? 'paused' : 'active'}')">${g.status === 'active' ? '⏸ إيقاف' : '▶ استئناف'}</button>
                    <button class="p-btn danger xs" onclick="deleteAdGroup(${g.id})">🗑</button>
                </div>
            </div>`).join('') + `<div class="p-cell-muted" style="font-size:11px;margin-top:8px;">${esc(res.data.performance_note)}</div>`;
    }

    window.createAdGroup = async function () {
        const input = document.getElementById('newAdGroupName');
        const name = input.value.trim();
        if (!name) { P.toast('اكتب اسم المجموعة الأول', 'error'); return; }

        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID + '/ad-groups', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name }),
        });
        if (res.success) { input.value = ''; P.toast('تم إنشاء المجموعة الإعلانية', 'success'); loadAdGroups(); }
        else P.toast(res.error || 'تعذر الإنشاء', 'error');
    };

    window.toggleAdGroupStatus = async function (id, newStatus) {
        const res = await fetchJSON('/api/ads/ad-groups/' + id + '/status', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status: newStatus }),
        });
        if (res.success) loadAdGroups(); else P.toast(res.error || 'تعذر التحديث', 'error');
    };

    window.deleteAdGroup = async function (id) {
        if (!confirm('متأكد من حذف المجموعة دي؟ (الكلمات/الإعلانات المرتبطة هتفضل موجودة بس تنفصل عن المجموعة)')) return;
        const res = await fetchJSON('/api/ads/ad-groups/' + id, { method: 'DELETE' });
        if (res.success) { P.toast('تم الحذف', 'success'); loadAdGroups(); } else P.toast(res.error || 'تعذر الحذف', 'error');
    };

    async function loadCopies() {
        const box = document.getElementById('campaignCopiesBox');
        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID + '/copies');
        if (!res.success || !res.data.copies || !res.data.copies.length) { box.innerHTML = '<div class="p-cell-muted">لا توجد إعلانات مُولَّدة لهذه الحملة بعد</div>'; return; }
        box.innerHTML = res.data.copies.map(c => `
            <div style="padding:10px 0;border-bottom:1px solid var(--border-color, #eee);">
                <div><b>${esc(c.headline || '')}</b> <span class="pill xs">${esc(c.variant_label || '')}</span> <span class="pill xs">${esc(c.status || '')}</span></div>
                <div class="p-cell-muted" style="font-size:13px;">${esc(c.description || '')}</div>
                ${c.performance_score !== null && c.performance_score !== undefined ? `<div class="p-cell-muted" style="font-size:11px;">نقاط الأداء: ${esc(c.performance_score)}</div>` : ''}
            </div>`).join('');
    }

    window.generateCampaignKeywords = async function () {
        const box = document.getElementById('kwResults');
        box.innerHTML = 'جارِ التوليد...';
        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID + '/keywords/generate', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({}),
        });
        if (!res.success) { box.innerHTML = `<span style="color:#b91c1c;">${esc(res.error || 'تعذر التوليد')}</span>`; return; }
        renderKeywords(res.data);
    };

    function renderKeywords(data) {
        const box = document.getElementById('kwResults');
        const groups = ['high_intent', 'commercial', 'long_tail', 'local', 'negative'];
        const labels = { high_intent: 'نية شراء عالية', commercial: 'تجارية عامة', long_tail: 'عبارات طويلة', local: 'محلية', negative: 'سلبية (استبعاد)' };
        const any = groups.some(g => data[g] && data[g].length);
        if (!any) { box.innerHTML = '<div class="p-cell-muted">لا توجد كلمات مفتاحية مُولَّدة بعد - اضغط "توليد بالذكاء الاصطناعي"</div>'; return; }
        box.innerHTML = groups.map(g => (data[g] && data[g].length) ? `
            <div style="margin-bottom:8px;"><b>${labels[g]}:</b> ${data[g].map(k => `<span class="pill xs" style="margin:2px;">${esc(k.keyword)}</span>`).join('')}</div>
        ` : '').join('') + (data.disclaimer ? `<div class="p-cell-muted" style="font-size:11px;">${esc(data.disclaimer)}</div>` : '');
    }

    async function loadKeywords() {
        const box = document.getElementById('kwResults');
        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID + '/keywords');
        if (!res.success || !res.data.length) { box.innerHTML = '<div class="p-cell-muted">لا توجد كلمات مفتاحية مُولَّدة بعد - اضغط "توليد بالذكاء الاصطناعي"</div>'; return; }
        // ملحوظة: match_type (exact/phrase/broad/negative) هو اللي بيتخزّن في
        // ad_keywords - تصنيف الـintent (high_intent/commercial/...) بيتولّد
        // لحظيًا وقت التوليد بس ومش عمود مُخزَّن، فعرض الكلمات المحفوظة
        // بيبقى List بسيط بدل تجميع Intent وهمي.
        box.innerHTML = '<table class="p-table"><thead><tr><th>الكلمة</th><th>النوع</th><th>الملاءمة</th><th>حجم بحث تقديري</th><th>CPC تقديري</th></tr></thead><tbody>' +
            res.data.map(k => `<tr><td>${esc(k.keyword)}</td><td>${esc(k.match_type)}</td><td>${k.ai_relevance_score ?? '-'}</td><td>${k.estimated_search_volume ?? '-'}</td><td>${k.estimated_cpc ?? '-'}</td></tr>`).join('') +
            '</tbody></table><div class="p-cell-muted" style="font-size:11px;margin-top:6px;">الأرقام تقديرات ذكاء اصطناعي، مش بيانات بحث حقيقية مقاسة.</div>';
    }

    window.createCampaignUtmLink = async function () {
        const box = document.getElementById('utmResults');
        box.innerHTML = 'جارِ الإنشاء...';
        const payload = {
            destination_url: document.getElementById('utmDest').value.trim(),
            utm_source: document.getElementById('utmSource').value.trim() || 'google',
            utm_medium: document.getElementById('utmMedium').value.trim() || 'cpc',
        };
        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID + '/utm-links', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        });
        if (!res.success) { box.innerHTML = `<span style="color:#b91c1c;">${esc(res.error || 'تعذر الإنشاء')}</span>`; return; }
        box.innerHTML = `<div>رابط التتبع: <a href="${esc(res.data.short_redirect_url)}" target="_blank">${esc(res.data.short_redirect_url)}</a></div>`;
        loadUtmLinks();
    };

    async function loadUtmLinks() {
        const box = document.getElementById('utmListBox');
        const res = await fetchJSON('/api/ads/campaigns/' + CAMPAIGN_ID + '/utm-links');
        if (!res.success || !res.data.length) { box.innerHTML = ''; return; }
        box.innerHTML = '<table class="p-table"><thead><tr><th>المصدر</th><th>الوسيط</th><th>نقرات</th></tr></thead><tbody>' +
            res.data.map(l => `<tr><td>${esc(l.utm_source)}</td><td>${esc(l.utm_medium)}</td><td>${esc(l.clicks)}</td></tr>`).join('') + '</tbody></table>';
    }

    async function loadTrend() {
        const res = await fetchJSON('/api/ads/reports/trend?days=30&campaign_id=' + CAMPAIGN_ID);
        const emptyBox = document.getElementById('campaignTrendEmpty');
        const canvas = document.getElementById('campaignTrendChart');
        if (!res.success || !res.data.length) { emptyBox.style.display = 'block'; canvas.style.display = 'none'; return; }
        emptyBox.style.display = 'none'; canvas.style.display = 'block';
        if (trendChart) trendChart.destroy();
        trendChart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: { labels: res.data.map(r => r.date), datasets: [
                { label: 'الإنفاق', data: res.data.map(r => r.spend), borderColor: '#0077be', tension: 0.3 },
                { label: 'التحويلات', data: res.data.map(r => r.conversions), borderColor: '#22c55e', tension: 0.3 },
            ] },
            options: { responsive: true },
        });
    }

    async function loadLog() {
        const box = document.getElementById('campaignLogBox');
        const res = await fetchJSON('/api/ads/autopilot/logs?campaign_id=' + CAMPAIGN_ID);
        if (!res.success || !res.data.length) { box.innerHTML = '<div class="p-cell-muted">لا يوجد سجل نشاط لهذه الحملة بعد</div>'; return; }
        box.innerHTML = res.data.map(l => `
            <div style="padding:8px 0;border-bottom:1px solid var(--border-color, #eee);">
                <b>${esc(l.action_type)}</b> <span class="p-cell-muted" style="font-size:11px;">(${esc(l.mode)})</span>
                <div class="p-cell-muted" style="font-size:12px;">${esc(l.description)}</div>
            </div>`).join('');
    }

    loadOverview();
    loadAdGroups();
    loadCopies();
    loadKeywords();
    loadUtmLinks();
    loadTrend();
    loadLog();
})();
