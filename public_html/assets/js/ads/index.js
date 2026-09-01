(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;
    const ALLOWED_CTAS = JSON.parse(document.getElementById('adsWizardConfig').dataset.ctas || '[]');
    const LIMITS = {
        headline: { recommended: 27, max: 40 },
        description: { recommended: 27, max: 30 },
        primary_text: { recommended: 125, max: 220 },
    };
    let currentBrief = null;

    async function loadMetaStatus() {
        const res = await fetchJSON('/api/ads/meta/status');
        const box = document.getElementById('metaConnectionStatus');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحقق من حالة الربط</div>'; return; }

        if (!res.data.configured) {
            box.innerHTML = '<div class="p-cell-muted">ربط Meta Ads لسه مش مفعّل من إدارة النظام (بيانات App ID/Secret ناقصة في إعدادات السيرفر).</div>';
            return;
        }

        if (res.data.connected) {
            box.innerHTML = `
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span><span class="pill green">✔ مربوط</span> ${esc(res.data.account_name || res.data.external_account_id || '')}</span>
                    <div style="display:flex;gap:8px;">
                        <button class="p-btn outline xs" onclick="syncMetaCampaigns()">🔄 مزامنة الحملات الآن</button>
                        <button class="p-btn danger xs" onclick="disconnectMeta()">فصل الربط</button>
                    </div>
                </div>`;
        } else {
            box.innerHTML = `<a href="/ads/connect/meta" class="p-btn primary xs">🔗 ربط حساب Meta Ads</a>`;
        }
    }

    window.syncMetaCampaigns = async function () {
        P.toast('جارِ سحب الحملات من Meta...', 'success');
        const res = await fetchJSON('/api/ads/meta/sync', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('تمت المزامنة: ' + res.data.synced + ' حملة', 'success'); load(); }
        else P.toast(res.error || 'تعذرت المزامنة', 'error');
    };

    window.disconnectMeta = async function () {
        if (!confirm('متأكد من فصل ربط Meta Ads؟')) return;
        const res = await fetchJSON('/api/ads/meta/disconnect', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('تم فصل الربط', 'success'); loadMetaStatus(); }
        else P.toast(res.error || 'تعذر الفصل', 'error');
    };

    async function loadGoogleAdsStatus() {
        const res = await fetchJSON('/api/ads/google/status');
        const box = document.getElementById('googleAdsConnectionStatus');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحقق من حالة الربط</div>'; return; }

        if (!res.data.configured) {
            box.innerHTML = '<div class="p-cell-muted">ربط Google Ads لسه مش مفعّل من إدارة النظام (GOOGLE_ADS_DEVELOPER_TOKEN ناقص في إعدادات السيرفر).</div>';
            return;
        }

        if (res.data.connected) {
            box.innerHTML = `
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span><span class="pill green">✔ مربوط</span> ${esc(res.data.external_account_id || '')}</span>
                    <div style="display:flex;gap:8px;">
                        <button class="p-btn outline xs" onclick="syncGoogleAdsCampaigns()">🔄 مزامنة الحملات الآن</button>
                        <button class="p-btn danger xs" onclick="disconnectGoogleAds()">فصل الربط</button>
                    </div>
                </div>`;
        } else {
            box.innerHTML = `<a href="/ads/connect/google" class="p-btn primary xs">🔗 ربط حساب Google Ads</a>`;
        }
    }

    window.syncGoogleAdsCampaigns = async function () {
        P.toast('جارِ سحب الحملات من Google Ads...', 'success');
        const res = await fetchJSON('/api/ads/google/sync', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('تمت المزامنة: ' + res.data.synced + ' حملة', 'success'); load(); }
        else P.toast(res.error || 'تعذرت المزامنة', 'error');
    };

    window.disconnectGoogleAds = async function () {
        if (!confirm('متأكد من فصل ربط Google Ads؟')) return;
        const res = await fetchJSON('/api/ads/google/disconnect', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('تم فصل الربط', 'success'); loadGoogleAdsStatus(); }
        else P.toast(res.error || 'تعذر الفصل', 'error');
    };

    let currentPage = 1;
    let searchDebounceTimer = null;

    async function load() {
        const tbody = document.querySelector('#campaignsTable tbody');
        tbody.innerHTML = '<tr class="p-loading-row"><td colspan="6">جارِ التحميل...</td></tr>';
        document.getElementById('selectAllCampaigns').checked = false;
        updateBulkBar();

        const qs = new URLSearchParams({
            q: document.getElementById('campaignSearch').value.trim(),
            status: document.getElementById('campaignStatusFilter').value,
            sort: document.getElementById('campaignSort').value,
            dir: 'desc',
            page: currentPage,
            per_page: 20,
        });

        const res = await fetchJSON('/api/ads/campaigns/search?' + qs.toString());
        if (res.success && res.data.campaigns && res.data.campaigns.length) {
            tbody.innerHTML = res.data.campaigns.map(c => `
                <tr>
                    <td><input type="checkbox" class="campaign-select" value="${c.id}" onchange="updateBulkBar()"></td>
                    <td>
                        ${esc(c.name)}
                        ${c.ai_generated ? '<span class="pill blue xs" style="margin-inline-start:6px;">✨ ذكاء اصطناعي</span>' : ''}
                        ${c.target_audience_brief ? '<div class="p-cell-muted" style="font-size:11px;margin-top:3px;">🎯 ' + esc(c.target_audience_brief) + '</div>' : ''}
                    </td>
                    <td>${esc(c.daily_budget || '-')} ${esc(c.currency)}</td>
                    <td>${esc(c.status)}</td>
                    <td>${esc(c.spend)} ${esc(c.currency)}</td>
                    <td>
                        <a href="/ads/campaigns/${c.id}" class="p-btn outline xs" style="text-decoration:none;">📋 التفاصيل</a>
                        <button class="p-btn outline xs" onclick="generateCopies(${c.id})">توليد ✨</button>
                        <button class="p-btn outline xs" data-campaign-id="${c.id}" data-campaign-name="${esc(c.name)}" onclick="openCampaignTools(this)">🛠 أدوات</button>
                        <div id="copies-${c.id}" style="margin-top:6px;"></div>
                    </td>
                </tr>
            `).join('');
            res.data.campaigns.forEach(c => { if (c.id) loadCopiesInline(c.id); });
            renderPagination(res.data.total, res.data.page, res.data.per_page);
        } else {
            tbody.innerHTML = '<tr><td colspan="6" class="p-empty">لا يوجد حملات مطابقة</td></tr>';
            document.getElementById('campaignsPagination').innerHTML = '';
        }
    }

    function renderPagination(total, page, perPage) {
        const box = document.getElementById('campaignsPagination');
        const totalPages = Math.max(1, Math.ceil(total / perPage));
        if (total === 0) { box.innerHTML = ''; return; }
        box.innerHTML = `
            <span class="p-cell-muted">${total} حملة - صفحة ${page} من ${totalPages}</span>
            <div style="display:flex;gap:6px;">
                <button class="p-btn outline xs" ${page <= 1 ? 'disabled' : ''} onclick="goToPage(${page - 1})">السابق</button>
                <button class="p-btn outline xs" ${page >= totalPages ? 'disabled' : ''} onclick="goToPage(${page + 1})">التالي</button>
            </div>`;
    }

    window.goToPage = function (page) { currentPage = page; load(); };

    window.toggleSelectAll = function () {
        const checked = document.getElementById('selectAllCampaigns').checked;
        document.querySelectorAll('.campaign-select').forEach(cb => { cb.checked = checked; });
        updateBulkBar();
    };

    window.updateBulkBar = function () {
        const selected = document.querySelectorAll('.campaign-select:checked');
        const bar = document.getElementById('bulkActionBar');
        if (selected.length > 0) {
            bar.style.display = 'flex';
            document.getElementById('bulkSelectedCount').textContent = selected.length + ' حملة محدّدة';
        } else {
            bar.style.display = 'none';
        }
    };

    window.bulkUpdateStatus = async function (newStatus) {
        const ids = Array.from(document.querySelectorAll('.campaign-select:checked')).map(cb => parseInt(cb.value, 10));
        if (!ids.length) return;
        const actionLabel = newStatus === 'paused' ? 'إيقاف' : 'استئناف';
        if (!confirm('متأكد من ' + actionLabel + ' ' + ids.length + ' حملة؟')) return;

        const res = await fetchJSON('/api/ads/campaigns/bulk-status', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ campaign_ids: ids, status: newStatus }),
        });
        if (res.success) {
            const failed = res.data.results.filter(r => !r.success);
            if (failed.length) P.toast(failed.length + ' حملة فشلت (راجع التفاصيل)', 'error');
            else P.toast('تم ' + actionLabel + ' الحملات المحدّدة', 'success');
            load();
        } else {
            P.toast(res.error || 'تعذّر تنفيذ الإجراء الجماعي', 'error');
        }
    };

    document.getElementById('campaignSearch').addEventListener('input', () => {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => { currentPage = 1; load(); }, 400);
    });
    document.getElementById('campaignStatusFilter').addEventListener('change', () => { currentPage = 1; load(); });
    document.getElementById('campaignSort').addEventListener('change', () => { currentPage = 1; load(); });

    async function loadCopiesInline(campaignId) {
        const box = document.getElementById('copies-' + campaignId);
        if (!box) return;
        const res = await fetchJSON('/api/ads/campaigns/' + campaignId + '/copies');
        if (res.success && res.data.copies && res.data.copies.length) {
            renderCopiesList(box, res.data.copies);
        }
    }

    function renderCopiesList(box, copies) {
        box.innerHTML = copies.map(c => `
            <div class="ads-copy-mini ${c.status === 'approved' ? 'approved' : ''} ${c.status === 'rejected' ? 'rejected' : ''}">
                <div><strong>[${esc(c.variant_label)}]</strong> ${esc(c.headline)}</div>
                <div class="p-cell-muted" style="font-size:11px;">${esc(c.description || '')}</div>
                <div class="ads-copy-mini-actions">
                    ${c.status === 'approved'
                        ? '<span class="pill green xs">✔ معتمدة</span>'
                        : `<button class="p-btn outline xs" onclick="approveCopy(${c.id})">اعتماد</button>
                           <button class="p-btn ghost xs" onclick="rejectCopy(${c.id})">استبعاد</button>`}
                </div>
            </div>
        `).join('');
    }

    window.generateCopies = async function (id) {
        const box = document.getElementById('copies-' + id);
        box.innerHTML = '<div class="p-cell-muted">جارِ التوليد...</div>';
        const res = await fetchJSON('/api/ads/campaigns/' + id + '/generate-copies', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success && res.data.copies) {
            renderCopiesList(box, res.data.copies);
        } else {
            box.innerHTML = '<span class="p-cell-muted">' + esc(res.error || 'فشل التوليد') + '</span>';
        }
    };

    window.approveCopy = async function (id) {
        const res = await fetchJSON('/api/ads/copies/' + id + '/approve', { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('تم اعتماد النسخة', 'success'); load(); }
        else P.toast(res.error || 'تعذر الاعتماد', 'error');
    };

    window.rejectCopy = async function (id) {
        const res = await fetchJSON('/api/ads/copies/' + id + '/reject', { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('تم استبعاد النسخة', 'success'); load(); }
        else P.toast(res.error || 'تعذر الاستبعاد', 'error');
    };

    window.createCampaign = async function () {
        const name = document.getElementById('campaignName').value.trim();
        const daily_budget = document.getElementById('campaignBudget').value;
        if (!name) return;
        const res = await fetchJSON('/api/ads/campaigns', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name, daily_budget }) });
        document.getElementById('newCampaignModal').classList.remove('open');
        document.getElementById('campaignName').value = '';
        document.getElementById('campaignBudget').value = '';
        if (res.success) { P.toast('تم إنشاء الحملة (مسودة)', 'success'); load(); }
        else P.toast(res.error || 'فشل الإنشاء', 'error');
    };

    // ============ ويزارد الحملة بالذكاء الاصطناعي ============
    window.openAiWizard = function () {
        currentBrief = null;
        document.getElementById('aiWizardError').style.display = 'none';
        document.getElementById('aiWizardStep1').style.display = 'block';
        document.getElementById('aiWizardStep2').style.display = 'none';
        document.getElementById('aiWizardStep2').innerHTML = '';
        document.getElementById('aiWizardFoot').style.display = 'none';
        document.getElementById('aiWizardModal').classList.add('open');
    };

    window.closeAiWizard = function () {
        document.getElementById('aiWizardModal').classList.remove('open');
    };

    window.backToAiStep1 = function () {
        document.getElementById('aiWizardStep1').style.display = 'block';
        document.getElementById('aiWizardStep2').style.display = 'none';
        document.getElementById('aiWizardFoot').style.display = 'none';
    };

    window.generateAiBrief = async function () {
        const objective = document.getElementById('aiObjective').value;
        const goalDescription = document.getElementById('aiGoalDescription').value.trim();
        const dailyBudget = document.getElementById('aiDailyBudget').value;
        const errBox = document.getElementById('aiWizardError');
        errBox.style.display = 'none';

        if (!goalDescription) {
            errBox.textContent = 'اكتب وصف مختصر لعرضك الأول';
            errBox.style.display = 'block';
            return;
        }

        const btn = document.getElementById('aiGenerateBtn');
        const originalLabel = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'جارِ التوليد بالذكاء الاصطناعي...';

        const payload = { objective: objective, goal_description: goalDescription };
        if (dailyBudget) payload.daily_budget = dailyBudget;

        let res;
        try {
            res = await fetchJSON('/api/ads/campaigns/ai-generate', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
            });
        } catch (e) {
            res = { success: false, error: 'تعذر الاتصال بالسيرفر' };
        }

        btn.disabled = false;
        btn.textContent = originalLabel;

        if (res.success) {
            currentBrief = res.data.brief;
            renderAiReview(currentBrief);
            document.getElementById('aiWizardStep1').style.display = 'none';
            document.getElementById('aiWizardStep2').style.display = 'block';
            document.getElementById('aiWizardFoot').style.display = 'flex';
        } else {
            if (res.data && res.data.shortfall) {
                errBox.textContent = 'رصيدك في المحفظة مش كافي - محتاج تودّع $' + res.data.shortfall + ' إضافية';
            } else {
                errBox.textContent = res.error || 'تعذر توليد الحملة، جرّب تاني';
            }
            errBox.style.display = 'block';
        }
    };

    function ctaOptionsHtml(selected) {
        return ALLOWED_CTAS.map(function (c) {
            return '<option value="' + esc(c) + '"' + (c === selected ? ' selected' : '') + '>' + esc(c) + '</option>';
        }).join('');
    }

    function badgeClass(len, limitObj) {
        return len <= limitObj.recommended ? 'ok' : 'warn';
    }

    function renderCopyCard(c, i) {
        const headline = c.headline || '';
        const description = c.description || '';
        const primaryText = c.primary_text || '';
        const hLen = headline.length, dLen = description.length, pLen = primaryText.length;

        let html = '<div class="ads-copy-card">';
        html += '<div class="ads-copy-card-head">نسخة ' + esc(c.variant_label || String.fromCharCode(65 + i)) + '</div>';

        html += '<label>العنوان (Headline)</label>';
        html += '<div class="ads-field-row"><input type="text" class="p-select ads-cc-headline" data-idx="' + i + '" style="width:100%;" maxlength="' + LIMITS.headline.max + '" value="' + esc(headline) + '">';
        html += '<span class="ads-char-badge ' + badgeClass(hLen, LIMITS.headline) + '" id="badge-headline-' + i + '">' + hLen + '/' + LIMITS.headline.max + '</span></div>';

        html += '<label>الوصف (Description)</label>';
        html += '<div class="ads-field-row"><input type="text" class="p-select ads-cc-description" data-idx="' + i + '" style="width:100%;" maxlength="' + LIMITS.description.max + '" value="' + esc(description) + '">';
        html += '<span class="ads-char-badge ' + badgeClass(dLen, LIMITS.description) + '" id="badge-description-' + i + '">' + dLen + '/' + LIMITS.description.max + '</span></div>';

        html += '<label>النص الأساسي (Primary Text)</label>';
        html += '<div class="ads-field-row"><textarea class="p-select ads-cc-primary_text" data-idx="' + i + '" style="width:100%;" rows="2" maxlength="' + LIMITS.primary_text.max + '">' + esc(primaryText) + '</textarea>';
        html += '<span class="ads-char-badge ' + badgeClass(pLen, LIMITS.primary_text) + '" id="badge-primary_text-' + i + '">' + pLen + '/' + LIMITS.primary_text.max + '</span></div>';

        html += '<label>دعوة لاتخاذ إجراء (CTA)</label>';
        html += '<select class="p-select ads-cc-cta" data-idx="' + i + '" style="width:100%;">' + ctaOptionsHtml(c.call_to_action) + '</select>';

        const warnings = c.policy_warnings || [];
        if (warnings.length) {
            html += '<div class="ads-policy-warnings">' + warnings.map(function (w) {
                return '<div class="ads-policy-warning">⚠️ ' + esc(w) + '</div>';
            }).join('') + '</div>';
        }

        html += '</div>';
        return html;
    }

    function bindCopyCardEvents(count) {
        for (let i = 0; i < count; i++) {
            bindField('headline', i, LIMITS.headline);
            bindField('description', i, LIMITS.description);
            bindField('primary_text', i, LIMITS.primary_text);
        }
    }

    function bindField(field, i, limitObj) {
        const el = document.querySelector('.ads-cc-' + field + '[data-idx="' + i + '"]');
        const badge = document.getElementById('badge-' + field + '-' + i);
        if (!el || !badge) return;
        el.addEventListener('input', function () {
            const len = el.value.length;
            badge.textContent = len + '/' + limitObj.max;
            badge.classList.remove('ok', 'warn');
            badge.classList.add(badgeClass(len, limitObj));
        });
    }

    function renderAiReview(brief) {
        const step2 = document.getElementById('aiWizardStep2');
        const a = brief.audience || {};
        const b = brief.budget_recommendation || {};
        let html = '';

        html += '<label>اسم الحملة</label>';
        html += '<input type="text" id="reviewCampaignName" class="p-select" style="width:100%;margin-bottom:16px;" maxlength="255" value="' + esc(brief.campaign_name || '') + '">';

        html += '<div class="p-card" style="margin-bottom:14px;padding:14px;">';
        html += '<div style="font-weight:800;font-size:13.5px;margin-bottom:10px;">🎯 الجمهور المستهدف</div>';
        html += '<div class="ads-grid-2">';
        html += '<div><label>أقل عمر</label><input type="number" id="reviewAgeMin" class="p-select" style="width:100%;" value="' + (a.age_min != null ? a.age_min : 18) + '" min="13" max="65"></div>';
        html += '<div><label>أكبر عمر</label><input type="number" id="reviewAgeMax" class="p-select" style="width:100%;" value="' + (a.age_max != null ? a.age_max : 65) + '" min="13" max="65"></div>';
        html += '</div>';
        html += '<label style="margin-top:10px;display:block;">الجنس</label>';
        html += '<select id="reviewGenders" class="p-select" style="width:100%;">';
        html += '<option value="all"' + (a.genders === 'all' ? ' selected' : '') + '>الكل</option>';
        html += '<option value="male"' + (a.genders === 'male' ? ' selected' : '') + '>ذكور</option>';
        html += '<option value="female"' + (a.genders === 'female' ? ' selected' : '') + '>إناث</option>';
        html += '</select>';
        html += '<label style="margin-top:10px;display:block;">المواقع الجغرافية (افصل بفاصلة)</label>';
        html += '<input type="text" id="reviewLocations" class="p-select" style="width:100%;" value="' + esc((a.locations || []).join('، ')) + '">';
        html += '<label style="margin-top:10px;display:block;">الاهتمامات (افصل بفاصلة)</label>';
        html += '<input type="text" id="reviewInterests" class="p-select" style="width:100%;" value="' + esc((a.interests || []).join('، ')) + '">';
        if (brief.target_audience_brief) {
            html += '<div class="p-cell-muted" style="margin-top:10px;font-size:12px;">💡 ' + esc(brief.target_audience_brief) + '</div>';
        }
        html += '</div>';

        html += '<div class="p-card" style="margin-bottom:14px;padding:14px;">';
        html += '<div style="font-weight:800;font-size:13.5px;margin-bottom:10px;">💰 توصية الميزانية</div>';
        html += '<label>الميزانية اليومية المقترحة (USD)</label>';
        html += '<input type="number" id="reviewBudget" class="p-select" style="width:100%;margin-bottom:8px;" min="1" step="0.5" value="' + (b.recommended_daily_budget != null ? b.recommended_daily_budget : 10) + '">';
        if (b.bid_strategy) html += '<div class="p-cell-muted" style="font-size:12px;"><strong>استراتيجية المزايدة:</strong> ' + esc(b.bid_strategy) + '</div>';
        if (b.reasoning) html += '<div class="p-cell-muted" style="font-size:12px;margin-top:4px;">💡 ' + esc(b.reasoning) + '</div>';
        html += '</div>';

        html += '<div style="font-size:13px;font-weight:800;margin:14px 0 8px;">✍️ النصوص الإعلانية (اتفحص العدّادات وعدّل اللي يعجبك)</div>';
        const copies = brief.copies || [];
        copies.forEach(function (c, i) { html += renderCopyCard(c, i); });

        step2.innerHTML = html;
        bindCopyCardEvents(copies.length);
    }

    function collectReviewData() {
        const locations = document.getElementById('reviewLocations').value.split(/[,،]/).map(s => s.trim()).filter(Boolean);
        const interests = document.getElementById('reviewInterests').value.split(/[,،]/).map(s => s.trim()).filter(Boolean);
        const copyCount = (currentBrief && currentBrief.copies ? currentBrief.copies.length : 0);
        const copies = [];
        for (let i = 0; i < copyCount; i++) {
            const headlineEl = document.querySelector('.ads-cc-headline[data-idx="' + i + '"]');
            if (!headlineEl) continue;
            copies.push({
                headline: headlineEl.value.trim(),
                description: document.querySelector('.ads-cc-description[data-idx="' + i + '"]').value.trim(),
                primary_text: document.querySelector('.ads-cc-primary_text[data-idx="' + i + '"]').value.trim(),
                call_to_action: document.querySelector('.ads-cc-cta[data-idx="' + i + '"]').value,
                variant_label: (currentBrief.copies[i] && currentBrief.copies[i].variant_label) || String.fromCharCode(65 + i),
            });
        }

        return {
            name: document.getElementById('reviewCampaignName').value.trim(),
            objective: currentBrief.objective,
            product_or_service: currentBrief.product_or_service,
            target_audience_brief: currentBrief.target_audience_brief,
            daily_budget: document.getElementById('reviewBudget').value,
            ai_generated: true,
            audience: {
                age_min: document.getElementById('reviewAgeMin').value,
                age_max: document.getElementById('reviewAgeMax').value,
                genders: document.getElementById('reviewGenders').value,
                locations: locations,
                interests: interests,
            },
            budget_recommendation: {
                recommended_daily_budget: document.getElementById('reviewBudget').value,
                bid_strategy: (currentBrief.budget_recommendation || {}).bid_strategy || '',
                reasoning: (currentBrief.budget_recommendation || {}).reasoning || '',
            },
            copies: copies,
        };
    }

    window.confirmCreateAiCampaign = async function () {
        if (!currentBrief) return;
        const payload = collectReviewData();
        if (!payload.name) {
            payload.name = 'حملة بالذكاء الاصطناعي';
        }

        const btn = document.getElementById('aiConfirmCreateBtn');
        btn.disabled = true;
        btn.textContent = 'جارِ الإنشاء...';

        const res = await fetchJSON('/api/ads/campaigns', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        });

        btn.disabled = false;
        btn.textContent = 'إنشاء الحملة ✅';

        if (res.success) {
            P.toast('تم إنشاء الحملة بنجاح', 'success');
            closeAiWizard();
            load();
        } else {
            P.toast(res.error || 'تعذر إنشاء الحملة', 'error');
        }
    };

    let currentToolsCampaignId = null;

    window.openCampaignTools = function (btn) {
        currentToolsCampaignId = btn.getAttribute('data-campaign-id') || currentToolsCampaignId;
        document.getElementById('toolsCampaignName').textContent = btn.getAttribute('data-campaign-name') || '';
        document.getElementById('kwResults').innerHTML = '';
        document.getElementById('lpResults').innerHTML = '';
        document.getElementById('utmResults').innerHTML = '';
        document.getElementById('campaignToolsModal').classList.add('open');
    };

    window.generateCampaignKeywords = async function () {
        const box = document.getElementById('kwResults');
        box.innerHTML = 'جارِ التحليل...';
        const goalDescription = document.getElementById('kwGoalDesc').value.trim();
        const res = await fetchJSON(`/api/ads/campaigns/${currentToolsCampaignId}/keywords/generate`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ goal_description: goalDescription }),
        });
        if (!res.success) { box.innerHTML = `<span style="color:#b91c1c;">${esc(res.error || 'تعذر التوليد')}</span>`; return; }

        const groups = ['high_intent', 'commercial', 'long_tail', 'local', 'negative'];
        const labels = { high_intent: 'نية شراء عالية', commercial: 'تجارية عامة', long_tail: 'عبارات طويلة', local: 'محلية', negative: 'سلبية (استبعاد)' };
        box.innerHTML = groups.map(g => (res.data[g] && res.data[g].length) ? `
            <div style="margin-bottom:8px;"><b>${labels[g]}:</b> ${res.data[g].map(k => `<span class="pill xs" style="margin:2px;">${esc(k.keyword)}</span>`).join('')}</div>
        ` : '').join('') + `<div class="p-cell-muted" style="font-size:11px;">${esc(res.data.disclaimer || '')}</div>`;
    };

    window.analyzeCampaignLandingPage = async function () {
        const box = document.getElementById('lpResults');
        box.innerHTML = 'جارِ التحليل...';
        const url = document.getElementById('lpUrl').value.trim();
        const res = await fetchJSON(`/api/ads/campaigns/${currentToolsCampaignId}/landing-page/analyze`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url }),
        });
        if (!res.success) { box.innerHTML = `<span style="color:#b91c1c;">${esc(res.error || 'تعذر التحليل')}</span>`; return; }
        if (res.data.fetch_error) { box.innerHTML = `<span style="color:#b91c1c;">${esc(res.data.fetch_error)}</span>`; return; }

        box.innerHTML = `
            <div><b>Relevance:</b> ${esc(res.data.relevance || '-')}</div>
            <div><b>CTA:</b> ${esc(res.data.cta || '-')}</div>
            <div><b>Message Match:</b> ${esc(res.data.message_match || '-')}</div>
            <div style="margin-top:6px;"><b>التوصيات:</b><ul>${(res.data.recommendations || []).map(r => `<li>${esc(r)}</li>`).join('')}</ul></div>
        `;
    };

    window.createCampaignUtmLink = async function () {
        const box = document.getElementById('utmResults');
        box.innerHTML = 'جارِ الإنشاء...';
        const payload = {
            destination_url: document.getElementById('utmDest').value.trim(),
            utm_source: document.getElementById('utmSource').value.trim() || 'google',
            utm_medium: document.getElementById('utmMedium').value.trim() || 'cpc',
        };
        const res = await fetchJSON(`/api/ads/campaigns/${currentToolsCampaignId}/utm-links`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        });
        if (!res.success) { box.innerHTML = `<span style="color:#b91c1c;">${esc(res.error || 'تعذر الإنشاء')}</span>`; return; }
        box.innerHTML = `<div>رابط التتبع القصير: <a href="${esc(res.data.short_redirect_url)}" target="_blank">${esc(res.data.short_redirect_url)}</a></div>`;
    };

    async function loadDashboardSummary() {
        const box = document.getElementById('dashboardKpis');
        const period = document.getElementById('dashPeriod').value;
        const platform = document.getElementById('dashPlatform').value;
        const status = document.getElementById('dashStatus').value;

        const qs = new URLSearchParams({ period });
        if (platform) qs.set('platform', platform);
        if (status) qs.set('status', status);

        const res = await fetchJSON('/api/ads/dashboard/summary?' + qs.toString());
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر تحميل الملخص</div>'; return; }
        const d = res.data;

        const kpi = (label, value) => `
            <div class="p-card" style="padding:14px;">
                <div class="p-cell-muted" style="font-size:11.5px;">${label}</div>
                <div style="font-size:20px;font-weight:800;margin-top:4px;">${value === null || value === undefined ? '<span class="p-cell-muted" style="font-size:13px;">لا توجد بيانات كافية</span>' : esc(String(value))}</div>
            </div>`;

        box.innerHTML =
            kpi('إجمالي الإنفاق', d.spend) +
            kpi('التحويلات', d.conversions) +
            kpi('CTR', d.ctr !== null ? d.ctr + '%' : null) +
            kpi('CPC', d.cpc) +
            kpi('CPM', d.cpm) +
            kpi('ROAS', d.roas !== null ? d.roas + 'x' : null) +
            kpi('حملات نشطة', d.active_campaigns) +
            kpi('حملات متوقفة', d.paused_campaigns) +
            kpi('استخدام الميزانية', d.budget_utilization_pct !== null ? d.budget_utilization_pct + '%' : null);
    }

    async function loadDashboardRecommendations() {
        const box = document.getElementById('dashboardRecommendations');
        const res = await fetchJSON('/api/ads/autopilot/pending');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحميل</div>'; return; }
        if (!res.data.length) { box.innerHTML = '<div class="p-cell-muted">مفيش توصيات جديدة حاليًا - كل حملاتك ضمن النطاق الطبيعي، أو الوضع الحالي "يدوي" وبيسجّل توصيات في سجل Autopilot بدل طابور الموافقة.</div>'; return; }
        box.innerHTML = res.data.slice(0, 3).map(a => `
            <div style="padding:8px 0;border-bottom:1px solid var(--border-color, #eee);">
                <b>${esc(a.action_type)}</b> - حملة #${a.campaign_id}
                <div class="p-cell-muted" style="font-size:12px;">${esc(a.reasoning)}</div>
            </div>`).join('') + `<a href="/ads/autopilot" class="p-btn outline xs" style="margin-top:8px;">مراجعة كل التوصيات</a>`;
    }

    loadDashboardSummary();
    loadDashboardRecommendations();
    loadMetaStatus();
    loadGoogleAdsStatus();
    load();
})();
