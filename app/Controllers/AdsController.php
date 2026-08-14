<?php
/**
 * Tourfecto - Ads Controller (إدارة الإعلانات)
 * @version 1.0.0
 */
class AdsController extends Controller {
    /** @var AdCampaignService */
    private $service;

    public function __construct() {
        parent::__construct();
        $this->service = new AdCampaignService();
    }

    /** GET /ads */
    public function index(array $params = []): array {
        $objectiveOptionsHtml = '';
        foreach (AdCopyGenerationService::OBJECTIVES as $key => $label) {
            $keyEsc = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
            $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $objectiveOptionsHtml .= "<option value=\"{$keyEsc}\">{$labelEsc}</option>";
        }

        $ctasJson = htmlspecialchars(
            json_encode(AdCopyGenerationService::allowedCtas(), JSON_UNESCAPED_UNICODE),
            ENT_QUOTES, 'UTF-8'
        );

        $body = <<<HTML
        <div class="p-grid cols-2" style="margin-bottom:16px;">
            <div class="p-card" id="metaConnectCard">
                <div class="p-card-head"><h3>📘 Meta Ads</h3><span class="p-card-sub">Facebook و Instagram</span></div>
                <div id="metaConnectionStatus"><div class="p-loading-row">جارِ التحميل...</div></div>
            </div>
            <div class="p-card" id="googleAdsConnectCard">
                <div class="p-card-head"><h3>🎯 Google Ads</h3><span class="p-card-sub">إعلانات البحث على Google</span></div>
                <div id="googleAdsConnectionStatus"><div class="p-loading-row">جارِ التحميل...</div></div>
            </div>
        </div>

        <div class="p-grid cols-4" style="margin-bottom:16px;">
            <div class="p-card stat-tile"><div class="p-cell-muted" style="font-size:12px;">إجمالي الإنفاق</div><div class="stat-value" id="statSpend">-</div></div>
            <div class="p-card stat-tile"><div class="p-cell-muted" style="font-size:12px;">حملات نشطة</div><div class="stat-value" id="statActive">-</div></div>
            <div class="p-card stat-tile"><div class="p-cell-muted" style="font-size:12px;">إجمالي الظهور</div><div class="stat-value" id="statImpressions">-</div></div>
            <div class="p-card stat-tile"><div class="p-cell-muted" style="font-size:12px;">إجمالي النقرات</div><div class="stat-value" id="statClicks">-</div></div>
        </div>

        <div id="adsWizardConfig" data-ctas="{$ctasJson}" style="display:none;"></div>

        <div class="p-toolbar" style="gap:10px;flex-wrap:wrap;justify-content:space-between;">
            <div class="p-tabs" id="platformTabs">
                <button class="p-tab active" data-platform="all" onclick="setPlatformFilter('all')">كل المنصات</button>
                <button class="p-tab" data-platform="meta_ads" onclick="setPlatformFilter('meta_ads')">📘 Meta Ads</button>
                <button class="p-tab" data-platform="google_ads" onclick="setPlatformFilter('google_ads')">🎯 Google Ads</button>
                <button class="p-tab" data-platform="manual" onclick="setPlatformFilter('manual')">حملات يدوية</button>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button class="p-btn primary" onclick="openAiWizard()">✨ حملة إعلانية بالذكاء الاصطناعي</button>
                <button class="p-btn outline" onclick="document.getElementById('newCampaignModal').classList.add('open')">+ حملة يدوية</button>
            </div>
        </div>
        <div class="p-card no-pad">
            <div class="p-table-scroll"><table class="p-table" id="campaignsTable">
                <thead><tr><th>المنصة</th><th>الاسم</th><th>الميزانية اليومية</th><th>الحالة</th><th>الأداء</th><th>النصوص الإعلانية</th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="6">جارِ التحميل...</td></tr></tbody>
            </table></div>
        </div>

        <div class="p-modal-overlay" id="newCampaignModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>حملة إعلانية جديدة (يدوي)</h3><button class="p-modal-close" onclick="document.getElementById('newCampaignModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label>المنصة</label>
                    <select id="campaignPlatform" class="p-select" style="width:100%;margin-bottom:10px;">
                        <option value="manual">حملة يدوية (تتبع فقط)</option>
                        <option value="meta_ads">Meta Ads</option>
                        <option value="google_ads">Google Ads</option>
                    </select>
                    <label>اسم الحملة</label>
                    <input type="text" id="campaignName" class="p-select" style="width:100%;margin-bottom:10px;">
                    <label>الميزانية اليومية (USD)</label>
                    <input type="number" id="campaignBudget" class="p-select" style="width:100%;">
                </div>
                <div class="p-modal-foot"><button class="p-btn" onclick="createCampaign()">إنشاء</button></div>
            </div>
        </div>

        <div class="p-modal-overlay" id="aiWizardModal">
            <div class="p-modal wide">
                <div class="p-modal-head">
                    <h3>✨ حملة إعلانية بالذكاء الاصطناعي</h3>
                    <button class="p-modal-close" onclick="closeAiWizard()">×</button>
                </div>
                <div class="p-modal-body">
                    <div id="aiWizardStep1">
                        <label>المنصة</label>
                        <div class="p-grid cols-2" style="margin-bottom:14px;">
                            <label class="ads-platform-choice"><input type="radio" name="aiPlatform" value="meta_ads" checked><span>📘 Meta Ads <small>(Facebook / Instagram)</small></span></label>
                            <label class="ads-platform-choice"><input type="radio" name="aiPlatform" value="google_ads"><span>🎯 Google Ads <small>(بحث + كلمات مفتاحية)</small></span></label>
                        </div>

                        <label>الهدف من الحملة</label>
                        <select id="aiObjective" class="p-select" style="width:100%;margin-bottom:14px;">{$objectiveOptionsHtml}</select>

                        <label>وصف مختصر لعرضك</label>
                        <textarea id="aiGoalDescription" class="p-select" rows="3" style="width:100%;margin-bottom:14px;" placeholder="مثال: رحلة الغردقة 3 أيام 2 ليلة شاملة الإقامة والإفطار بـ 5000 جنيه للفرد" maxlength="2000"></textarea>

                        <label>الميزانية اليومية المتوقعة (USD) - اختياري</label>
                        <input type="number" id="aiDailyBudget" class="p-select" style="width:100%;margin-bottom:6px;" min="1" step="0.5">
                        <div class="p-cell-muted" style="font-size:11.5px;margin-bottom:16px;">سيب الحقل ده فاضي لو عايز الذكاء الاصطناعي يقترحلك رقم مناسب</div>

                        <button class="p-btn primary btn-block" id="aiGenerateBtn" onclick="generateAiBrief()">توليد الحملة بالذكاء الاصطناعي ✨</button>
                        <div class="p-cell-muted" style="font-size:11px;text-align:center;margin-top:8px;">هيتم خصم سعر التوليد من رصيد محفظتك عند نجاح التوليد بس</div>
                        <div id="aiWizardError" class="alert alert-danger" style="display:none;margin-top:12px;"></div>
                    </div>

                    <div id="aiWizardStep2" style="display:none;"></div>
                </div>
                <div class="p-modal-foot" id="aiWizardFoot" style="display:none;">
                    <button class="p-btn outline" onclick="backToAiStep1()">‹ رجوع للتعديل</button>
                    <button class="p-btn primary" id="aiConfirmCreateBtn" onclick="confirmCreateAiCampaign()">إنشاء الحملة ✅</button>
                </div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON;
    const ALLOWED_CTAS = JSON.parse(document.getElementById('adsWizardConfig').dataset.ctas || '[]');
    const LIMITS_BY_PLATFORM = {
        meta_ads: {
            headline: { recommended: 27, max: 40 },
            description: { recommended: 27, max: 30 },
            primary_text: { recommended: 125, max: 220, label: 'النص الأساسي (Primary Text)' },
        },
        google_ads: {
            headline: { recommended: 30, max: 30 },
            description: { recommended: 90, max: 90 },
            primary_text: { recommended: 90, max: 90, label: 'وصف ثاني (Description 2)' },
        },
    };
    let LIMITS = LIMITS_BY_PLATFORM.meta_ads;
    let currentBrief = null;
    let allCampaigns = [];
    let activePlatformFilter = 'all';

    const PLATFORM_LABELS = { meta_ads: '📘 Meta Ads', google_ads: '🎯 Google Ads', manual: 'يدوية' };

    function platformBadge(platform) {
        const p = PLATFORM_LABELS[platform] ? platform : 'manual';
        return '<span class="ads-platform-badge ' + p + '">' + PLATFORM_LABELS[p] + '</span>';
    }

    async function loadMetaStatus() {
        const res = await fetchJSON('/api/ads/meta/status');
        const box = document.getElementById('metaConnectionStatus');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحقق من حالة الربط</div>'; return; }

        if (!res.data.configured) {
            box.innerHTML = '<div class="p-cell-muted">ربط Meta Ads لسه مش مفعّل من إدارة النظام.</div>';
            return;
        }

        if (res.data.connected) {
            box.innerHTML = `
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                    <span><span class="pill green">✔ مربوط</span> ${esc(res.data.account_name || res.data.external_account_id || '')}</span>
                    <div style="display:flex;gap:8px;">
                        <button class="p-btn outline xs" onclick="syncMetaCampaigns()">🔄 مزامنة الآن</button>
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
        const res = await fetchJSON('/api/ads/google-ads/status');
        const box = document.getElementById('googleAdsConnectionStatus');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحقق من حالة الربط</div>'; return; }

        if (!res.data.configured) {
            box.innerHTML = '<div class="p-cell-muted">ربط Google Ads لسه مش مفعّل من إدارة النظام.</div>';
            return;
        }

        if (res.data.connected) {
            box.innerHTML = `
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                    <span><span class="pill green">✔ مربوط</span> ${esc(res.data.external_account_id || '')}</span>
                    <div style="display:flex;gap:8px;">
                        <button class="p-btn outline xs" onclick="syncGoogleAdsCampaigns()">🔄 مزامنة الآن</button>
                        <button class="p-btn danger xs" onclick="disconnectGoogleAds()">فصل الربط</button>
                    </div>
                </div>`;
        } else {
            box.innerHTML = `<a href="/ads/connect/google-ads" class="p-btn primary xs">🔗 ربط حساب Google Ads</a>`;
        }
    }

    window.syncGoogleAdsCampaigns = async function () {
        P.toast('جارِ سحب الحملات من Google Ads...', 'success');
        const res = await fetchJSON('/api/ads/google-ads/sync', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('تمت المزامنة: ' + res.data.synced + ' حملة', 'success'); load(); }
        else P.toast(res.error || 'تعذرت المزامنة', 'error');
    };

    window.disconnectGoogleAds = async function () {
        if (!confirm('متأكد من فصل ربط Google Ads؟')) return;
        const res = await fetchJSON('/api/ads/google-ads/disconnect', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('تم فصل الربط', 'success'); loadGoogleAdsStatus(); }
        else P.toast(res.error || 'تعذر الفصل', 'error');
    };

    window.setPlatformFilter = function (platform) {
        activePlatformFilter = platform;
        document.querySelectorAll('#platformTabs .p-tab').forEach(function (btn) {
            btn.classList.toggle('active', btn.dataset.platform === platform);
        });
        renderCampaignsTable();
    };

    function computeStats(campaigns) {
        let spend = 0, impressions = 0, clicks = 0, active = 0;
        campaigns.forEach(function (c) {
            spend += parseFloat(c.spend || 0);
            impressions += parseInt(c.impressions || 0, 10);
            clicks += parseInt(c.clicks || 0, 10);
            if (c.status === 'active' || c.status === 'enabled') active++;
        });
        document.getElementById('statSpend').textContent = '$' + spend.toFixed(2);
        document.getElementById('statActive').textContent = active;
        document.getElementById('statImpressions').textContent = impressions.toLocaleString();
        document.getElementById('statClicks').textContent = clicks.toLocaleString();
    }

    async function load() {
        const res = await fetchJSON('/api/ads/campaigns');
        allCampaigns = (res.success && res.data.campaigns) ? res.data.campaigns : [];
        computeStats(allCampaigns);
        renderCampaignsTable();
    }

    function renderCampaignsTable() {
        const tbody = document.querySelector('#campaignsTable tbody');
        const filtered = activePlatformFilter === 'all'
            ? allCampaigns
            : allCampaigns.filter(function (c) { return (c.platform || 'manual') === activePlatformFilter; });

        if (filtered.length) {
            tbody.innerHTML = filtered.map(c => `
                <tr>
                    <td>${platformBadge(c.platform || 'manual')}</td>
                    <td>
                        ${esc(c.name)}
                        ${c.ai_generated ? '<span class="pill blue xs" style="margin-inline-start:6px;">✨ ذكاء اصطناعي</span>' : ''}
                        ${c.target_audience_brief ? '<div class="p-cell-muted" style="font-size:11px;margin-top:3px;">🎯 ' + esc(c.target_audience_brief) + '</div>' : ''}
                    </td>
                    <td>${esc(c.daily_budget || '-')} ${esc(c.currency)}</td>
                    <td>${statusCell(c)}${managementButtonsHtml(c)}</td>
                    <td>
                        <div class="p-cell-muted" style="font-size:11.5px;">💰 ${esc(c.spend || 0)} ${esc(c.currency)}</div>
                        <div class="p-cell-muted" style="font-size:11.5px;">👁 ${Number(c.impressions || 0).toLocaleString()} · 🖱 ${Number(c.clicks || 0).toLocaleString()}</div>
                    </td>
                    <td>
                        <button class="p-btn outline xs" onclick="generateCopies(${c.id})">توليد ✨</button>
                        ${(!c.external_campaign_id && (c.platform === 'meta_ads' || c.platform === 'google_ads'))
                            ? '<button class="p-btn primary xs" onclick="publishCampaign(' + c.id + ')" id="publishBtn-' + c.id + '" style="margin-inline-start:6px;">🚀 نشر فعلي</button>'
                            : ''}
                        <div id="copies-${c.id}" style="margin-top:6px;"></div>
                        ${(c.platform === 'google_ads') ? '<div id="keywords-' + c.id + '" style="margin-top:6px;"></div>' : ''}
                    </td>
                </tr>
            `).join('');
            filtered.forEach(c => {
                if (c.id) loadCopiesInline(c.id);
                if (c.id && c.platform === 'google_ads') loadKeywordsInline(c.id);
            });
        } else {
            tbody.innerHTML = '<tr><td colspan="6" class="p-empty">لا يوجد حملات في القسم ده بعد</td></tr>';
        }
    }

    function statusCell(c) {
        if (!c.external_campaign_id && (c.platform === 'meta_ads' || c.platform === 'google_ads')) {
            return '<span class="pill xs">📝 مسودة محلية</span>';
        }
        if (c.status === 'removed') {
            return '<span class="pill red xs">🗑 ملغاة</span>';
        }
        if (c.status === 'paused') {
            return '<span class="pill orange xs">⏸ متوقفة</span>';
        }
        if (c.status === 'active' || c.status === 'enabled') {
            return '<span class="pill green xs">▶ نشطة</span>';
        }
        return '<span class="pill xs">' + esc(c.status) + '</span>';
    }

    function managementButtonsHtml(c) {
        if (!c.external_campaign_id || c.status === 'removed') return '';
        const toggleLabel = c.status === 'active' ? '⏸ إيقاف' : '▶ تشغيل';
        return `
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;">
                <button class="p-btn outline xs" onclick="toggleCampaignStatus(${c.id})">${toggleLabel}</button>
                <button class="p-btn outline xs" onclick="editCampaignBudget(${c.id}, ${c.daily_budget || 0})">💰 تعديل الميزانية</button>
                <button class="p-btn ghost xs" onclick="cancelCampaign(${c.id})">🗑 إلغاء</button>
            </div>`;
    }

    window.publishCampaign = async function (id, pageId) {
        if (!pageId && !confirm('هيتم إنشاء الحملة فعليًا على المنصة (بحالة متوقفة عشان تراجعها الأول). متابعة؟')) return;
        const btn = document.getElementById('publishBtn-' + id);
        if (btn) { btn.disabled = true; btn.textContent = 'جارِ النشر...'; }

        const payload = pageId ? { page_id: pageId } : {};
        const res = await fetchJSON('/api/ads/campaigns/' + id + '/publish', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });

        if (res.success) {
            P.toast(res.message || 'تم النشر بنجاح', 'success');
            load();
            return;
        }

        // عنده أكتر من صفحة فيسبوك - نعرضله يختار واحدة
        if (res.code === 409 && res.details && res.details.pages && res.details.pages.length) {
            const list = res.details.pages.map((p, i) => (i + 1) + '. ' + p.name).join('\n');
            const choice = prompt('عندك أكتر من صفحة فيسبوك، اكتب رقم الصفحة اللي عايز تنشر عليها:\n' + list);
            const idx = parseInt(choice, 10) - 1;
            if (res.details.pages[idx]) {
                return window.publishCampaign(id, res.details.pages[idx].id);
            }
        }

        P.toast(res.error || 'فشل النشر', 'error');
        if (btn) { btn.disabled = false; btn.textContent = '🚀 نشر فعلي'; }
    };

    window.toggleCampaignStatus = async function (id) {
        const res = await fetchJSON('/api/ads/campaigns/' + id + '/toggle-status', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast(res.message || 'تم التعديل', 'success'); load(); }
        else P.toast(res.error || 'تعذر التعديل', 'error');
    };

    window.cancelCampaign = async function (id) {
        if (!confirm('متأكد من إلغاء الحملة نهائيًا على المنصة؟ الإجراء ده لا يمكن التراجع عنه.')) return;
        const res = await fetchJSON('/api/ads/campaigns/' + id + '/cancel', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast(res.message || 'تم الإلغاء', 'success'); load(); }
        else P.toast(res.error || 'تعذر الإلغاء', 'error');
    };

    window.editCampaignBudget = async function (id, currentBudget) {
        const newBudget = prompt('الميزانية اليومية الجديدة (USD):', currentBudget || 10);
        if (!newBudget || isNaN(parseFloat(newBudget))) return;
        const res = await fetchJSON('/api/ads/campaigns/' + id + '/update-budget', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ daily_budget: parseFloat(newBudget) }),
        });
        if (res.success) { P.toast(res.message || 'تم تعديل الميزانية', 'success'); load(); }
        else P.toast(res.error || 'تعذر التعديل', 'error');
    };

    async function loadCopiesInline(campaignId) {
        const box = document.getElementById('copies-' + campaignId);
        if (!box) return;
        const res = await fetchJSON('/api/ads/campaigns/' + campaignId + '/copies');
        if (res.success && res.data.copies && res.data.copies.length) {
            renderCopiesList(box, res.data.copies);
        }
    }

    async function loadKeywordsInline(campaignId) {
        const box = document.getElementById('keywords-' + campaignId);
        if (!box) return;
        const res = await fetchJSON('/api/ads/campaigns/' + campaignId + '/keywords');
        if (res.success && res.data.keywords && res.data.keywords.length) {
            box.innerHTML = '<div class="p-cell-muted" style="font-size:10.5px;margin-bottom:3px;">🔑 كلمات مفتاحية:</div>'
                + res.data.keywords.map(function (k) {
                    return '<span class="pill xs" style="margin:0 3px 3px 0;display:inline-block;">' + esc(k.keyword) + '</span>';
                }).join('');
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
        const platform = document.getElementById('campaignPlatform').value;
        if (!name) return;
        const res = await fetchJSON('/api/ads/campaigns', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name, daily_budget, platform }) });
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
        document.querySelectorAll('input[name="aiPlatform"]').forEach(function (r) { r.checked = (r.value === 'meta_ads'); });
        updatePlatformChoiceStyles();
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

    function updatePlatformChoiceStyles() {
        document.querySelectorAll('.ads-platform-choice').forEach(function (label) {
            const input = label.querySelector('input');
            label.classList.toggle('selected', input && input.checked);
        });
    }
    document.querySelectorAll('input[name="aiPlatform"]').forEach(function (r) {
        r.addEventListener('change', updatePlatformChoiceStyles);
    });
    updatePlatformChoiceStyles();

    function selectedAiPlatform() {
        const checked = document.querySelector('input[name="aiPlatform"]:checked');
        return checked ? checked.value : 'meta_ads';
    }

    window.generateAiBrief = async function () {
        const objective = document.getElementById('aiObjective').value;
        const platform = selectedAiPlatform();
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

        const payload = { objective: objective, goal_description: goalDescription, platform: platform };
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
            LIMITS = LIMITS_BY_PLATFORM[currentBrief.platform] || LIMITS_BY_PLATFORM.meta_ads;
            renderAiReview(currentBrief);
            document.getElementById('aiWizardStep1').style.display = 'none';
            document.getElementById('aiWizardStep2').style.display = 'block';
            document.getElementById('aiWizardFoot').style.display = 'flex';
        } else {
            if (res.details && res.details.shortfall) {
                errBox.textContent = 'رصيدك في المحفظة مش كافي - محتاج تودّع $' + res.details.shortfall + ' إضافية';
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
        const primaryLabel = LIMITS.primary_text.label || 'النص الأساسي (Primary Text)';

        let html = '<div class="ads-copy-card">';
        html += '<div class="ads-copy-card-head">نسخة ' + esc(c.variant_label || String.fromCharCode(65 + i)) + '</div>';

        html += '<label>العنوان (Headline)</label>';
        html += '<div class="ads-field-row"><input type="text" class="p-select ads-cc-headline" data-idx="' + i + '" style="width:100%;" maxlength="' + LIMITS.headline.max + '" value="' + esc(headline) + '">';
        html += '<span class="ads-char-badge ' + badgeClass(hLen, LIMITS.headline) + '" id="badge-headline-' + i + '">' + hLen + '/' + LIMITS.headline.max + '</span></div>';

        html += '<label>الوصف (Description)</label>';
        html += '<div class="ads-field-row"><input type="text" class="p-select ads-cc-description" data-idx="' + i + '" style="width:100%;" maxlength="' + LIMITS.description.max + '" value="' + esc(description) + '">';
        html += '<span class="ads-char-badge ' + badgeClass(dLen, LIMITS.description) + '" id="badge-description-' + i + '">' + dLen + '/' + LIMITS.description.max + '</span></div>';

        html += '<label>' + esc(primaryLabel) + '</label>';
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

    function renderKeywordsSection(keywords) {
        if (!keywords || !keywords.length) return '';
        const matchLabels = { exact: 'مطابقة تامة', phrase: 'عبارة', broad: 'واسعة', negative: 'استبعاد' };
        let html = '<div class="p-card" style="margin-bottom:14px;padding:14px;">';
        html += '<div style="font-weight:800;font-size:13.5px;margin-bottom:10px;">🔑 كلمات مفتاحية مقترحة (Google Ads)</div>';
        html += '<div id="reviewKeywordsList" style="display:flex;flex-wrap:wrap;gap:8px;">';
        keywords.forEach(function (k, i) {
            html += '<span class="pill" style="display:inline-flex;align-items:center;gap:6px;" data-kw="' + esc(k.keyword) + '" data-match="' + esc(k.match_type) + '">'
                + esc(k.keyword) + ' <span class="p-cell-muted" style="font-size:10px;">(' + esc(matchLabels[k.match_type] || k.match_type) + ')</span>'
                + ' <button type="button" onclick="this.closest(\'span\').remove()" style="border:none;background:none;cursor:pointer;color:inherit;">×</button></span>';
        });
        html += '</div>';
        html += '<div class="p-cell-muted" style="font-size:11px;margin-top:8px;">اضغط × لحذف أي كلمة مش مناسبة قبل الإنشاء</div>';
        html += '</div>';
        return html;
    }

    function renderAiReview(brief) {
        const step2 = document.getElementById('aiWizardStep2');
        const a = brief.audience || {};
        const b = brief.budget_recommendation || {};
        let html = '';

        html += '<div class="ads-platform-badge ' + esc(brief.platform || 'meta_ads') + '" style="margin-bottom:12px;">' + esc(PLATFORM_LABELS[brief.platform] || 'Meta Ads') + '</div>';

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

        if (brief.platform === 'google_ads') {
            html += renderKeywordsSection(brief.keywords || []);
        }

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

        const keywords = [];
        document.querySelectorAll('#reviewKeywordsList [data-kw]').forEach(function (el) {
            keywords.push({ keyword: el.dataset.kw, match_type: el.dataset.match });
        });

        return {
            name: document.getElementById('reviewCampaignName').value.trim(),
            objective: currentBrief.objective,
            platform: currentBrief.platform || 'meta_ads',
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
            keywords: keywords,
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

    loadMetaStatus();
    loadGoogleAdsStatus();
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'إدارة الإعلانات', 'حملاتك الإعلانية عبر كل المنصات المربوطة', $body, $script);
        exit;
    }

    /** GET /api/ads/campaigns */
    public function list(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $campaigns = $this->service->listForUser((int) $this->user['id']);
        return $this->success(['campaigns' => array_map(fn($c) => $c->toArray(), $campaigns)]);
    }

    /**
     * POST /api/ads/campaigns
     * بيقبل إنشاء يدوي بسيط (اسم + ميزانية بس، زي الأول)، أو إنشاء كامل
     * من ويزارد الذكاء الاصطناعي (لما يبعت objective/product_or_service/
     * target_audience_brief/audience/budget_recommendation/copies بعد
     * ما العميل يراجع معاينة /api/ads/campaigns/ai-generate ويأكّدها).
     */
    public function create(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!$this->validate(['name' => 'required'])) return $this->error('اسم الحملة مطلوب', 422);

        try {
            $websiteId = $this->get('website_id');
            if (empty($websiteId)) {
                // الواجهة (يدوي وويزارد الذكاء الاصطناعي) مبتبعتش website_id خالص -
                // من غيره، publishCampaign() هيفشل دايمًا لأنه محتاج رابط الموقع.
                // بنرجع لأول موقع للعميل تلقائيًا، زي ما بيحصل بالظبط وقت ربط
                // حساب Meta/Google Ads نفسه (firstWebsiteForUser).
                $defaultWebsite = $this->firstWebsiteForUser((int) $this->user['id']);
                $websiteId = $defaultWebsite['id'] ?? null;
            }

            $campaign = $this->service->create((int) $this->user['id'], [
                'name' => $this->get('name'),
                'objective' => $this->get('objective'),
                'platform' => $this->get('platform'),
                'product_or_service' => $this->get('product_or_service'),
                'target_audience_brief' => $this->get('target_audience_brief'),
                'daily_budget' => $this->get('daily_budget'),
                'budget_total' => $this->get('budget_total'),
                'start_date' => $this->get('start_date'),
                'end_date' => $this->get('end_date'),
                'ai_generated' => $this->get('ai_generated'),
                'website_id' => $websiteId,
                'audience' => $this->get('audience'),
                'budget_recommendation' => $this->get('budget_recommendation'),
                'copies' => $this->get('copies'),
                'keywords' => $this->get('keywords'),
            ]);
            return $this->success(['campaign' => $campaign->toArray()], 'تم إنشاء الحملة كمسودة', 201);
        } catch (Exception $e) {
            Logger::error('createCampaign Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنشاء الحملة', 500);
        }
    }

    /**
     * POST /api/ads/campaigns/ai-generate
     * ويزارد الحملة الاحترافي: من وصف بسيط لعرض العميل، الذكاء الاصطناعي
     * بيجهّز حزمة حملة كاملة (اسم + جمهور مستهدف + توصية ميزانية + 3
     * نصوص إعلانية مطابقة لحدود المنصات فعليًا). دي "معاينة" فقط - محفظتش
     * حاجة في قاعدة البيانات لحد ما العميل يراجعها ويأكّد الإنشاء عبر
     * POST /api/ads/campaigns العادي.
     */
    public function aiGenerateCampaign(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!$this->validate(['goal_description' => 'required', 'objective' => 'required'])) {
            return $this->error('اكتب وصف مختصر لعرضك واختار هدف الحملة', 422);
        }

        $objective = (string) $this->get('objective');
        if (!array_key_exists($objective, AdCopyGenerationService::OBJECTIVES)) {
            return $this->error('هدف الحملة غير معروف', 422);
        }

        $platform = (string) ($this->get('platform') ?: 'meta_ads');
        if (!array_key_exists($platform, AdCopyGenerationService::PLATFORMS)) {
            $platform = 'meta_ads';
        }

        $walletService = new WalletService();
        $priceCheck = $walletService->canAffordUsage((int) $this->user['id'], 'ai_ad_campaign_generation');
        if (!$priceCheck['can_afford']) {
            return $this->error('رصيدك في المحفظة مش كافي لتوليد حملة بالذكاء الاصطناعي', 402, [
                'shortfall' => $priceCheck['shortfall'] ?? null,
            ]);
        }

        try {
            $goalDescription = (string) $this->get('goal_description');
            $dailyBudget = $this->get('daily_budget');

            $service = new AdCopyGenerationService();
            $brief = $service->generateCampaignBrief($goalDescription, $objective, $dailyBudget !== null && $dailyBudget !== '' ? (float) $dailyBudget : null, $platform);

            $walletService->chargeForUsage((int) $this->user['id'], 'ai_ad_campaign_generation', 'توليد حملة إعلانية بالذكاء الاصطناعي');

            return $this->success([
                'brief' => $brief,
                'new_balance' => $walletService->getBalance((int) $this->user['id']),
            ]);
        } catch (Exception $e) {
            Logger::error('aiGenerateCampaign Error', ['message' => $e->getMessage()]);
            return $this->error($e->getMessage(), 502);
        }
    }

    /** GET /api/ads/campaigns/{id}/copies */
    public function listCopies(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign || (int) $campaign->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $items = (new AdCopy())->where(['campaign_id' => (int) $campaign->getAttribute('id')], ['created_at' => 'DESC']);
        return $this->success(['copies' => array_map(fn($c) => $c->toArray(), $items)]);
    }

    /** GET /api/ads/campaigns/{id}/keywords - كلمات مفتاحية Google Ads المحفوظة للحملة */
    public function listKeywords(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign || (int) $campaign->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $items = (new AdKeyword())->where(['campaign_id' => (int) $campaign->getAttribute('id')], ['id' => 'ASC']);
        return $this->success(['keywords' => array_map(fn($k) => $k->toArray(), $items)]);
    }

    /**
     * توليد نصوص إعلانية بالذكاء الاصطناعي لحملة موجودة.
     * POST /api/ads/campaigns/{id}/generate-copies
     *
     * ملحوظة: كانت دي الـ endpoint اللي زرار "توليد ✨" في صفحة الإعلانات
     * بينده عليها من غير ما تكون موجودة أصلاً - يعني كل ضغطة على الزرار
     * كانت بتسبب خطأ فادح فوري. الـ Service الحقيقي (AdCopyGenerationService)
     * كان مبني ومفعّل وحقيقي (Gemini فعلي) من زمان، بس مربوطش بأي controller
     * method أو route.
     */
    public function generateCopies(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign || (int) $campaign->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('الحملة غير موجودة', 404);
        }

        try {
            $service = new AdCopyGenerationService();
            $copies = $service->generateCopies($campaign, 3);
            return $this->success(['copies' => array_map(fn($c) => $c->toArray(), $copies)], 'تم توليد النصوص الإعلانية', 201);
        } catch (Exception $e) {
            Logger::error('generateCopies Error', ['campaign_id' => $params['id'] ?? null, 'message' => $e->getMessage()]);
            return $this->error($e->getMessage(), 502);
        }
    }

    /** PATCH /api/ads/copies/{id}/approve - اعتماد نسخة إعلانية معيّنة كالنسخة المستخدمة فعليًا */
    public function approveCopy(array $params = []): array {
        return $this->updateCopyStatus($params, 'approved');
    }

    /** PATCH /api/ads/copies/{id}/reject - استبعاد نسخة إعلانية */
    public function rejectCopy(array $params = []): array {
        return $this->updateCopyStatus($params, 'rejected');
    }

    private function updateCopyStatus(array $params, string $status): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        $copy = (new AdCopy())->find((int) ($params['id'] ?? 0));
        if (!$copy) return $this->error('النسخة الإعلانية غير موجودة', 404);

        $campaign = (new AdCampaign())->find((int) $copy->getAttribute('campaign_id'));
        if (!$campaign || (int) $campaign->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('غير مصرح', 403);
        }

        try {
            $copy->fill(['status' => $status]);
            $copy->save();
            return $this->success(['copy' => $copy->toArray()], $status === 'approved' ? 'تم اعتماد النسخة' : 'تم استبعاد النسخة');
        } catch (Exception $e) {
            Logger::error('updateCopyStatus Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحديث حالة النسخة', 500);
        }
    }

    /**
     * POST /api/ads/campaigns/{id}/publish
     * النشر الفعلي: بياخد الحملة المحفوظة محليًا (مسودة) والنصوص
     * المعتمدة، ويبعتها فعليًا لـ Meta Ads أو Google Ads عشان تتعمل
     * كحملة حقيقية هناك - دايمًا بحالة متوقفة (Paused) كإجراء أمان،
     * العميل لازم يراجعها ويفعّلها بنفسه من داخل حساب المنصة الرسمي.
     */
    public function publishCampaign(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign || (int) $campaign->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('الحملة غير موجودة', 404);
        }

        $platform = $campaign->getAttribute('platform');
        if (!in_array($platform, ['meta_ads', 'google_ads'], true)) {
            return $this->error('الحملة دي يدوية (تتبع فقط) - مفيش منصة إعلانات مرتبطة بيها للنشر عليها', 422);
        }

        if (!empty($campaign->getAttribute('external_campaign_id'))) {
            return $this->error('الحملة دي منشورة بالفعل على المنصة', 422);
        }

        $approvedCopies = (new AdCopy())->where(['campaign_id' => (int) $campaign->getAttribute('id'), 'status' => 'approved']);
        if (empty($approvedCopies)) {
            return $this->error('لازم تعتمد نسخة إعلانية واحدة على الأقل قبل النشر (زرار "اعتماد" تحت النصوص)', 422);
        }
        $copiesData = array_map(fn($c) => $c->toArray(), $approvedCopies);

        $website = (new Website())->find((int) $campaign->getAttribute('website_id'));
        $destinationUrl = $website ? trim((string) $website->getAttribute('main_url')) : '';
        if ($destinationUrl === '') {
            return $this->error('محتاج رابط موقع صحيح مربوط بحسابك عشان الإعلان يوصّل الزوار له', 422);
        }
        if (!preg_match('#^https?://#i', $destinationUrl)) {
            $destinationUrl = 'https://' . $destinationUrl;
        }

        try {
            $connection = $this->db->query(
                "SELECT * FROM platform_connections WHERE user_id = ? AND platform = ? AND status = 'connected' LIMIT 1",
                [$this->user['id'], $platform]
            );
            if (empty($connection)) {
                return $this->error('لازم تربط حساب ' . ($platform === 'meta_ads' ? 'Meta Ads' : 'Google Ads') . ' الأول من أعلى الصفحة', 422);
            }
            $conn = $connection[0];
            $encryption = new Encryption();
            $accessToken = $encryption->decrypt($conn['access_token']);

            $campaignPayload = [
                'name' => $campaign->getAttribute('name'),
                'objective' => $campaign->getAttribute('objective') ?: 'traffic',
                'daily_budget' => $campaign->getAttribute('daily_budget') ?: 10,
                'start_date' => $campaign->getAttribute('start_date'),
                'end_date' => $campaign->getAttribute('end_date'),
            ];

            if ($platform === 'meta_ads') {
                $pages = $this->db->query(
                    "SELECT external_location_id, external_location_name FROM platform_connections
                     WHERE website_id = ? AND platform = 'facebook' AND status = 'connected'",
                    [$conn['website_id']]
                );
                if (empty($pages)) {
                    return $this->error('محتاج صفحة فيسبوك مربوطة عشان تظهر عليها الإعلانات - اتأكد إن عندك صفحة فيسبوك أدمن عليها وأعد ربط Meta Ads من جديد', 422);
                }

                $pageId = $this->get('page_id');
                if (!$pageId) {
                    if (count($pages) === 1) {
                        $pageId = $pages[0]['external_location_id'];
                    } else {
                        // أكتر من صفحة - محتاجين العميل يختار، بنرجّع القائمة عشان الواجهة تعرضها
                        return $this->error('عندك أكتر من صفحة فيسبوك - اختار واحدة للنشر عليها', 409, [
                            'pages' => array_map(fn($p) => ['id' => $p['external_location_id'], 'name' => $p['external_location_name']], $pages),
                        ]);
                    }
                }

                $audienceRows = (new AdAudience())->where(['campaign_id' => (int) $campaign->getAttribute('id')]);
                $audienceRow = !empty($audienceRows) ? $audienceRows[0]->toArray() : [];
                $audience = [
                    'age_min' => $audienceRow['age_min'] ?? 18,
                    'age_max' => $audienceRow['age_max'] ?? 65,
                    'genders' => $audienceRow['genders'] ?? 'all',
                    'locations' => !empty($audienceRow['locations_json']) ? (json_decode($audienceRow['locations_json'], true) ?: []) : [],
                ];

                $api = new MetaAdsAPI($accessToken);
                $imageUrl = $api->fetchOgImageFromWebsite($destinationUrl); // best-effort - ممكن ترجع null وده مقبول
                $result = $api->createCampaign($conn['external_account_id'], $pageId, $campaignPayload, $audience, $copiesData, $destinationUrl, $imageUrl);
            } else {
                $keywordRows = (new AdKeyword())->where(['campaign_id' => (int) $campaign->getAttribute('id')]);
                $keywords = array_map(fn($k) => ['keyword' => $k->getAttribute('keyword'), 'match_type' => $k->getAttribute('match_type')], $keywordRows);

                $budgetRecRows = (new AdBudgetRecommendation())->where(['campaign_id' => (int) $campaign->getAttribute('id')]);
                $bidStrategyHint = !empty($budgetRecRows) ? (string) $budgetRecRows[0]->getAttribute('bid_strategy') : '';

                $api = new GoogleAdsAPI($accessToken);
                $result = $api->createSearchCampaign($conn['external_account_id'], $campaignPayload, $copiesData, $keywords, $destinationUrl, $bidStrategyHint);
            }

            if (!($result['success'] ?? false)) {
                // لو اتعمل جزء من الحملة على المنصة (external_campaign_id راجع) بنسجّله برضه، عشان العميل يلاقيها ويكمّلها يدويًا بدل ما تتوه
                if (!empty($result['external_campaign_id'])) {
                    $campaign->fill([
                        'external_campaign_id' => $result['external_campaign_id'],
                        'external_adset_id' => $result['external_adset_id'] ?? null,
                        'external_budget_resource' => $result['external_budget_resource'] ?? null,
                        'platform_connection_id' => $conn['id'],
                    ]);
                    $campaign->save();
                }
                return $this->error($result['error'] ?? 'فشل النشر على المنصة', 502);
            }

            $campaign->fill([
                'external_campaign_id' => $result['external_campaign_id'],
                'external_adset_id' => $result['external_adset_id'] ?? null,
                'external_budget_resource' => $result['external_budget_resource'] ?? null,
                'platform_connection_id' => $conn['id'],
                'status' => 'paused',
                'published_at' => date('Y-m-d H:i:s'),
            ]);
            $campaign->save();

            ActivityLog::record('ads', 'ad_campaign.published', [
                'subject_type' => 'ad_campaigns', 'subject_id' => (int) $campaign->getAttribute('id'),
                'meta' => ['platform' => $platform, 'external_campaign_id' => $result['external_campaign_id']],
            ]);

            return $this->success([
                'campaign' => $campaign->toArray(),
            ], 'تم إنشاء الحملة فعليًا على ' . ($platform === 'meta_ads' ? 'Meta Ads' : 'Google Ads') . ' بحالة متوقفة - راجعها وفعّلها من حسابك الرسمي هناك');
        } catch (Exception $e) {
            Logger::error('publishCampaign Error', ['campaign_id' => $params['id'] ?? null, 'message' => $e->getMessage()]);
            return $this->error('تعذر النشر: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/ads/campaigns/{id}/toggle-status
     * تشغيل/إيقاف حملة منشورة فعليًا على المنصة (Meta أو Google) - بيغيّر
     * الحالة هناك مباشرة، مش بس محليًا، عشان الإنفاق الفعلي يتأثر فورًا.
     */
    public function toggleCampaignStatus(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        [$campaign, $conn, $err] = $this->loadPublishedCampaignForManagement((int) ($params['id'] ?? 0));
        if ($err) return $err;

        $newStatus = $campaign->getAttribute('status') === 'active' ? 'paused' : 'active';

        try {
            $encryption = new Encryption();
            $accessToken = $encryption->decrypt($conn['access_token']);

            if ($campaign->getAttribute('platform') === 'meta_ads') {
                $api = new MetaAdsAPI($accessToken);
                $result = $api->updateCampaignStatus((string) $campaign->getAttribute('external_campaign_id'), $newStatus === 'active' ? 'ACTIVE' : 'PAUSED');
            } else {
                $api = new GoogleAdsAPI($accessToken);
                $result = $api->updateCampaignStatus($conn['external_account_id'], (string) $campaign->getAttribute('external_campaign_id'), $newStatus === 'active' ? 'ENABLED' : 'PAUSED');
            }

            if (!($result['success'] ?? false)) {
                return $this->error($result['error'] ?? 'فشل تعديل حالة الحملة على المنصة', 502);
            }

            $campaign->fill(['status' => $newStatus]);
            $campaign->save();

            return $this->success(['campaign' => $campaign->toArray()], $newStatus === 'active' ? 'تم تشغيل الحملة' : 'تم إيقاف الحملة');
        } catch (Exception $e) {
            Logger::error('toggleCampaignStatus Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تعديل الحالة', 500);
        }
    }

    /**
     * POST /api/ads/campaigns/{id}/cancel
     * إلغاء حملة منشورة نهائيًا على المنصة (أرشفة على Meta، أو status=REMOVED على Google).
     */
    public function cancelCampaign(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        [$campaign, $conn, $err] = $this->loadPublishedCampaignForManagement((int) ($params['id'] ?? 0));
        if ($err) return $err;

        try {
            $encryption = new Encryption();
            $accessToken = $encryption->decrypt($conn['access_token']);

            if ($campaign->getAttribute('platform') === 'meta_ads') {
                $api = new MetaAdsAPI($accessToken);
                $result = $api->deleteCampaign((string) $campaign->getAttribute('external_campaign_id'));
            } else {
                $api = new GoogleAdsAPI($accessToken);
                $result = $api->deleteCampaign($conn['external_account_id'], (string) $campaign->getAttribute('external_campaign_id'));
            }

            if (!($result['success'] ?? false)) {
                return $this->error($result['error'] ?? 'فشل إلغاء الحملة على المنصة', 502);
            }

            $campaign->fill(['status' => 'removed']);
            $campaign->save();

            return $this->success(['campaign' => $campaign->toArray()], 'تم إلغاء الحملة');
        } catch (Exception $e) {
            Logger::error('cancelCampaign Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إلغاء الحملة', 500);
        }
    }

    /**
     * POST /api/ads/campaigns/{id}/update-budget
     * تعديل الميزانية اليومية لحملة منشورة بالفعل - محتاج البيانات المحفوظة
     * وقت النشر (external_adset_id لـ Meta، external_budget_resource لـ Google).
     */
    public function updateCampaignBudget(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        [$campaign, $conn, $err] = $this->loadPublishedCampaignForManagement((int) ($params['id'] ?? 0));
        if ($err) return $err;

        $newBudget = (float) $this->get('daily_budget');
        if ($newBudget <= 0) return $this->error('الميزانية لازم تكون أكبر من صفر', 422);

        try {
            $encryption = new Encryption();
            $accessToken = $encryption->decrypt($conn['access_token']);

            if ($campaign->getAttribute('platform') === 'meta_ads') {
                if (empty($campaign->getAttribute('external_adset_id'))) {
                    return $this->error('مفيش معرّف مجموعة إعلانية محفوظ لهذه الحملة - راجعها يدويًا من Meta Ads Manager', 422);
                }
                $api = new MetaAdsAPI($accessToken);
                $result = $api->updateAdSetBudget((string) $campaign->getAttribute('external_adset_id'), $newBudget);
            } else {
                if (empty($campaign->getAttribute('external_budget_resource'))) {
                    return $this->error('مفيش معرّف ميزانية محفوظ لهذه الحملة - راجعها يدويًا من Google Ads', 422);
                }
                $api = new GoogleAdsAPI($accessToken);
                $result = $api->updateBudget((string) $campaign->getAttribute('external_budget_resource'), $newBudget);
            }

            if (!($result['success'] ?? false)) {
                return $this->error($result['error'] ?? 'فشل تعديل الميزانية على المنصة', 502);
            }

            $campaign->fill(['daily_budget' => $newBudget]);
            $campaign->save();

            return $this->success(['campaign' => $campaign->toArray()], 'تم تعديل الميزانية اليومية');
        } catch (Exception $e) {
            Logger::error('updateCampaignBudget Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تعديل الميزانية', 500);
        }
    }

    /**
     * يحمّل حملة منشورة فعليًا مع بيانات ربطها للتعامل الإداري (إيقاف/تشغيل/إلغاء/تعديل ميزانية).
     * @return array{0: ?AdCampaign, 1: ?array, 2: ?array} [الحملة, صف الربط, رد خطأ لو فيه مشكلة]
     */
    private function loadPublishedCampaignForManagement(int $campaignId): array {
        $campaign = (new AdCampaign())->find($campaignId);
        if (!$campaign || (int) $campaign->getAttribute('user_id') !== (int) $this->user['id']) {
            return [null, null, $this->error('الحملة غير موجودة', 404)];
        }

        if (empty($campaign->getAttribute('external_campaign_id'))) {
            return [null, null, $this->error('الحملة دي لسه مسودة محلية - مش منشورة على أي منصة', 422)];
        }

        $connRows = $this->db->query(
            "SELECT * FROM platform_connections WHERE id = ? AND status = 'connected' LIMIT 1",
            [$campaign->getAttribute('platform_connection_id')]
        );
        if (empty($connRows)) {
            return [null, null, $this->error('الربط بالمنصة اتفصل - أعد الربط الأول', 422)];
        }

        return [$campaign, $connRows[0], null];
    }

    // ============================================
    // Meta Ads OAuth - ربط ومزامنة حقيقية مع Meta Marketing API
    // ============================================

    /** GET /ads/connect/meta */
    public function connectMeta(array $params = []): array {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads'));
            exit;
        }

        $oauth = new MetaOAuthClient();
        if (!$oauth->isConfigured()) {
            $this->renderAdsOAuthError('ربط Meta Ads لسه مش مفعّل من إدارة النظام (بيانات META_APP_ID/META_APP_SECRET ناقصة في إعدادات السيرفر).');
            exit;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['meta_oauth_nonce'] = $nonce;

        $state = base64_encode(json_encode(['nonce' => $nonce], JSON_UNESCAPED_UNICODE));
        header('Location: ' . $oauth->buildAuthUrl($state));
        exit;
    }

    /** GET /ads/connect/meta/callback */
    public function metaOAuthCallback(array $params = []): array {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $error = $this->get('error');
        if ($error) {
            $this->renderAdsOAuthError('العميل رفض الموافقة أو حصل خطأ من Meta: ' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $code = $this->get('code');
        $state = $this->get('state');
        if (!$code || !$state) {
            $this->renderAdsOAuthError('رد غير مكتمل من Meta');
            exit;
        }

        $decodedState = json_decode(base64_decode((string) $state), true);
        $expectedNonce = $_SESSION['meta_oauth_nonce'] ?? null;

        if (!$decodedState || !$expectedNonce || !hash_equals($expectedNonce, $decodedState['nonce'] ?? '')) {
            $this->renderAdsOAuthError('انتهت صلاحية الجلسة أو محاولة غير موثوقة، جرّب تربط الحساب تاني');
            exit;
        }

        $oauth = new MetaOAuthClient();
        $tokenResult = $oauth->exchangeCodeForTokens((string) $code);

        if (!$tokenResult['success']) {
            $this->renderAdsOAuthError('فشل تبادل التوكن مع Meta: ' . htmlspecialchars($tokenResult['error'] ?? '', ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $_SESSION['meta_oauth_temp'] = [
            'access_token' => $tokenResult['access_token'],
            'expires_in' => $tokenResult['expires_in'],
        ];
        unset($_SESSION['meta_oauth_nonce']);

        header('Location: /ads/connect/meta/choose');
        exit;
    }

    /** GET /ads/connect/meta/choose - يختار العميل حساب الإعلانات بتاعه */
    public function showMetaAdAccountPicker(array $params = []): array {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $temp = $_SESSION['meta_oauth_temp'] ?? null;
        if (!$temp) {
            header('Location: /ads');
            exit;
        }

        $api = new MetaAdsAPI($temp['access_token']);
        $accountsResult = $api->listAdAccounts();

        if (!$accountsResult['success'] || empty($accountsResult['accounts'])) {
            $this->renderAdsOAuthError('مفيش حسابات إعلانات Meta مرتبطة بالحساب ده. تأكد إنك مسجّل دخول بنفس حساب Facebook اللي عليه صلاحية على حساب الإعلانات في Business Manager.<br><br>تفاصيل تقنية: ' . htmlspecialchars($accountsResult['error'] ?? '', ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $optionsHtml = '';
        foreach ($accountsResult['accounts'] as $acc) {
            $id = htmlspecialchars($acc['id'], ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars($acc['name'], ENT_QUOTES, 'UTF-8');
            $currency = htmlspecialchars($acc['currency'], ENT_QUOTES, 'UTF-8');
            $optionsHtml .= "<button class=\"p-btn outline\" style=\"width:100%;text-align:start;margin-bottom:8px;\" onclick=\"chooseAccount('{$id}')\">{$name} <span class=\"p-cell-muted\">({$currency})</span></button>";
        }

        $body = <<<HTML
        <div class="p-card">
            <div class="p-card-head"><h3>اختار حساب الإعلانات</h3><span class="p-card-sub">هنربط حملاتك الحقيقية من الحساب ده</span></div>
            <div id="accountOptions">{$optionsHtml}</div>
        </div>
HTML;

        $script = <<<'JS'
window.chooseAccount = async function (accountId) {
    const res = await window.Panel.fetchJSON('/api/ads/meta/choose-account', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ account_id: accountId })
    });
    if (res.success) { window.location.href = '/ads'; }
    else { window.Panel.toast(res.error || 'تعذر الربط', 'error'); }
};
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'اختيار حساب Meta Ads', '', $body, $script);
        exit;
    }

    /** POST /api/ads/meta/choose-account */
    public function chooseMetaAdAccount(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        $temp = $_SESSION['meta_oauth_temp'] ?? null;
        if (!$temp) return $this->error('انتهت الجلسة، ابدأ الربط تاني', 400);

        $accountId = $this->get('account_id');
        if (!$accountId) return $this->error('account_id مطلوب', 422);

        try {
            $website = $this->firstWebsiteForUser((int) $this->user['id']);
            if (!$website) {
                return $this->error('لازم يكون عندك موقع مضاف الأول من صفحة "المواقع"', 422);
            }

            $encryption = new Encryption();
            // تصحيح أمان: التوكن كان بيتخزن كنص صريح في قاعدة البيانات من غير
            // تشفير، خلاف كل باقي التكاملات (Google, TripAdvisor...) اللي
            // بتشفّر التوكن دايمًا. اتصلح هنا ليتطابق مع باقي النظام.
            $encryptedToken = $encryption->encrypt($temp['access_token']);
            $expiresAt = date('Y-m-d H:i:s', time() + (int) $temp['expires_in']);

            $existing = $this->db->query(
                "SELECT id FROM platform_connections WHERE website_id = ? AND platform = 'meta_ads' LIMIT 1",
                [$website['id']]
            );

            if (!empty($existing)) {
                $this->db->exec(
                    "UPDATE platform_connections SET access_token = ?, token_expires_at = ?, external_account_id = ?, status = 'connected', last_error = NULL, connected_at = NOW() WHERE id = ?",
                    [$encryptedToken, $expiresAt, $accountId, $existing[0]['id']]
                );
            } else {
                $this->db->exec(
                    "INSERT INTO platform_connections (website_id, user_id, platform, access_token, token_expires_at, external_account_id, status)
                     VALUES (?, ?, 'meta_ads', ?, ?, ?, 'connected')",
                    [$website['id'], $this->user['id'], $encryptedToken, $expiresAt, $accountId]
                );
            }

            // ربط تلقائي لأي صفحات فيسبوك (وحسابات انستجرام بيزنس المرتبطة
            // بيها) متاحة لنفس حساب Meta ده، عشان تبقى جاهزة للنشر عليها
            // فورًا من "السوشيال ميديا" من غير خطوة ربط منفصلة تانية.
            $this->autoConnectMetaSocialPages($website['id'], (int) $this->user['id'], $temp['access_token'], $encryption);

            unset($_SESSION['meta_oauth_temp']);
            return $this->success([], 'تم ربط حساب Meta Ads والصفحات المتاحة');
        } catch (Exception $e) {
            Logger::error('chooseMetaAdAccount Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حفظ الربط', 500);
        }
    }

    /**
     * يجيب كل صفحات فيسبوك (وانستجرام المرتبط بيها) المتاحة لتوكن
     * المستخدم، ويحفظهم كاتصالات منصة جاهزة للنشر (platform='facebook'
     * لكل صفحة، وplatform='instagram' لو فيها حساب بيزنس مرتبط).
     */
    private function autoConnectMetaSocialPages(int $websiteId, int $userId, string $userAccessToken, Encryption $encryption): void {
        try {
            $api = new MetaSocialAPI($userAccessToken);
            $pagesResult = $api->listPages();

            if (!$pagesResult['success']) {
                Logger::warning('Auto-connect Meta pages skipped', ['error' => $pagesResult['error'] ?? '']);
                return;
            }

            foreach ($pagesResult['pages'] as $page) {
                if (empty($page['access_token'])) {
                    continue;
                }
                $encryptedPageToken = $encryption->encrypt($page['access_token']);

                // صفحة الفيسبوك نفسها
                $this->upsertSocialConnection($websiteId, $userId, 'facebook', $page['id'], $page['name'], $encryptedPageToken);

                // حساب انستجرام بيزنس المرتبط بالصفحة (لو موجود)
                if (!empty($page['instagram_id'])) {
                    $this->upsertSocialConnection(
                        $websiteId, $userId, 'instagram', $page['instagram_id'],
                        $page['instagram_username'] ?? $page['name'], $encryptedPageToken
                    );
                }
            }
        } catch (Exception $e) {
            Logger::error('autoConnectMetaSocialPages Error', ['message' => $e->getMessage()]);
        }
    }

    private function upsertSocialConnection(int $websiteId, int $userId, string $platform, string $externalId, string $name, string $encryptedToken): void {
        $existing = $this->db->query(
            "SELECT id FROM platform_connections WHERE website_id = ? AND platform = ? AND external_location_id = ? LIMIT 1",
            [$websiteId, $platform, $externalId]
        );

        if (!empty($existing)) {
            $this->db->exec(
                "UPDATE platform_connections SET access_token = ?, external_location_name = ?, status = 'connected', last_error = NULL, connected_at = NOW() WHERE id = ?",
                [$encryptedToken, $name, $existing[0]['id']]
            );
        } else {
            $this->db->exec(
                "INSERT INTO platform_connections (website_id, user_id, platform, access_token, external_location_id, external_location_name, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'connected')",
                [$websiteId, $userId, $platform, $encryptedToken, $externalId, $name]
            );
        }
    }

    /** GET /api/ads/meta/status */
    public function getMetaConnectionStatus(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        $oauth = new MetaOAuthClient();
        if (!$oauth->isConfigured()) {
            return $this->success(['configured' => false, 'connected' => false]);
        }

        try {
            $row = $this->db->query(
                "SELECT external_account_id FROM platform_connections
                 WHERE user_id = ? AND platform = 'meta_ads' AND status = 'connected' LIMIT 1",
                [$this->user['id']]
            );

            if (empty($row)) {
                return $this->success(['configured' => true, 'connected' => false]);
            }

            return $this->success([
                'configured' => true,
                'connected' => true,
                'external_account_id' => $row[0]['external_account_id'],
            ]);
        } catch (Exception $e) {
            Logger::error('getMetaConnectionStatus Error', ['message' => $e->getMessage()]);
            return $this->success(['configured' => true, 'connected' => false]);
        }
    }

    /** POST /api/ads/meta/sync - سحب حملات حقيقية من Meta وتحديث ad_campaigns */
    public function syncMetaCampaigns(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        try {
            $connection = $this->db->query(
                "SELECT id, website_id, access_token, external_account_id FROM platform_connections
                 WHERE user_id = ? AND platform = 'meta_ads' AND status = 'connected' LIMIT 1",
                [$this->user['id']]
            );

            if (empty($connection)) {
                return $this->error('مفيش حساب Meta Ads مربوط', 400);
            }

            $conn = $connection[0];
            $decryptedToken = (new Encryption())->decrypt($conn['access_token']);
            $api = new MetaAdsAPI($decryptedToken);
            $result = $api->listCampaignsWithInsights($conn['external_account_id']);

            if (!$result['success']) {
                $this->db->exec(
                    "UPDATE platform_connections SET status = 'error', last_error = ? WHERE id = ?",
                    [$result['error'] ?? 'unknown error', $conn['id']]
                );
                return $this->error('تعذرت المزامنة مع Meta: ' . ($result['error'] ?? ''), 502);
            }

            $synced = 0;
            foreach ($result['campaigns'] as $c) {
                $existing = $this->db->query(
                    "SELECT id FROM ad_campaigns WHERE user_id = ? AND external_campaign_id = ? LIMIT 1",
                    [$this->user['id'], $c['external_campaign_id']]
                );

                if (!empty($existing)) {
                    $this->db->exec(
                        "UPDATE ad_campaigns SET name = ?, objective = ?, daily_budget = ?, status = ?, impressions = ?, clicks = ?, spend = ?, started_at = ?, ended_at = ?, updated_at = NOW()
                         WHERE id = ?",
                        [$c['name'], $c['objective'], $c['daily_budget'], $c['status'], $c['impressions'], $c['clicks'], $c['spend'], $c['started_at'], $c['ended_at'], $existing[0]['id']]
                    );
                } else {
                    $this->db->exec(
                        "INSERT INTO ad_campaigns (user_id, website_id, platform_connection_id, platform, name, objective, daily_budget, status, external_campaign_id, impressions, clicks, spend, started_at, ended_at)
                         VALUES (?, ?, ?, 'meta_ads', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$this->user['id'], $conn['website_id'], $conn['id'], $c['name'], $c['objective'], $c['daily_budget'], $c['status'], $c['external_campaign_id'], $c['impressions'], $c['clicks'], $c['spend'], $c['started_at'], $c['ended_at']]
                    );
                }
                $synced++;
            }

            $this->db->exec("UPDATE platform_connections SET last_synced_at = NOW(), status = 'connected', last_error = NULL WHERE id = ?", [$conn['id']]);

            return $this->success(['synced' => $synced]);
        } catch (Exception $e) {
            Logger::error('syncMetaCampaigns Error', ['message' => $e->getMessage()]);
            return $this->error('تعذرت المزامنة', 500);
        }
    }

    /** POST /api/ads/meta/disconnect */
    public function disconnectMeta(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        try {
            $this->db->exec(
                "UPDATE platform_connections SET status = 'disconnected', access_token = NULL WHERE user_id = ? AND platform = 'meta_ads'",
                [$this->user['id']]
            );
            return $this->success([], 'تم فصل الربط');
        } catch (Exception $e) {
            Logger::error('disconnectMeta Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الفصل', 500);
        }
    }

    // ============================================
    // Google Ads OAuth - ربط ومزامنة حقيقية مع Google Ads API
    // (نفس نمط Meta فوق بالظبط، بس بتوكن Google + Developer Token)
    // ============================================

    private function googleAdsOAuthClient(): GoogleOAuthClient {
        return new GoogleOAuthClient(GoogleOAuthClient::SCOPE_ADS, env('GOOGLE_ADS_OAUTH_REDIRECT_URI') ?: null);
    }

    private function isGoogleAdsConfigured(): bool {
        $oauth = $this->googleAdsOAuthClient();
        $developerToken = class_exists('SystemSettingsService')
            ? (new SystemSettingsService())->get('google_ads_developer_token', (string) (env('GOOGLE_ADS_DEVELOPER_TOKEN') ?: ''))
            : (string) (env('GOOGLE_ADS_DEVELOPER_TOKEN') ?: '');
        return $oauth->isConfigured() && $developerToken !== '';
    }

    /** GET /ads/connect/google-ads */
    public function connectGoogleAds(array $params = []): array {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads'));
            exit;
        }

        if (!$this->isGoogleAdsConfigured()) {
            $this->renderAdsOAuthError('ربط Google Ads لسه مش مفعّل من إدارة النظام (Google Client ID/Secret أو Developer Token ناقصين في إعدادات السيرفر).');
            exit;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['google_ads_oauth_nonce'] = $nonce;

        $state = base64_encode(json_encode(['nonce' => $nonce], JSON_UNESCAPED_UNICODE));
        header('Location: ' . $this->googleAdsOAuthClient()->buildAuthUrl($state));
        exit;
    }

    /** GET /ads/connect/google-ads/callback */
    public function googleAdsOAuthCallback(array $params = []): array {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $error = $this->get('error');
        if ($error) {
            $this->renderAdsOAuthError('العميل رفض الموافقة أو حصل خطأ من Google: ' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $code = $this->get('code');
        $state = $this->get('state');
        if (!$code || !$state) {
            $this->renderAdsOAuthError('رد غير مكتمل من Google');
            exit;
        }

        $decodedState = json_decode(base64_decode((string) $state), true);
        $expectedNonce = $_SESSION['google_ads_oauth_nonce'] ?? null;

        if (!$decodedState || !$expectedNonce || !hash_equals($expectedNonce, $decodedState['nonce'] ?? '')) {
            $this->renderAdsOAuthError('انتهت صلاحية الجلسة أو محاولة غير موثوقة، جرّب تربط الحساب تاني');
            exit;
        }

        $tokenResult = $this->googleAdsOAuthClient()->exchangeCodeForTokens((string) $code);
        if (!$tokenResult['success']) {
            $this->renderAdsOAuthError('فشل تبادل التوكن مع Google: ' . htmlspecialchars($tokenResult['error'] ?? '', ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $_SESSION['google_ads_oauth_temp'] = [
            'access_token' => $tokenResult['access_token'],
            'refresh_token' => $tokenResult['refresh_token'] ?? null,
            'expires_in' => $tokenResult['expires_in'],
        ];
        unset($_SESSION['google_ads_oauth_nonce']);

        header('Location: /ads/connect/google-ads/choose');
        exit;
    }

    /** GET /ads/connect/google-ads/choose - يختار العميل حساب الإعلانات بتاعه */
    public function showGoogleAdsAccountPicker(array $params = []): array {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $temp = $_SESSION['google_ads_oauth_temp'] ?? null;
        if (!$temp) {
            header('Location: /ads');
            exit;
        }

        $api = new GoogleAdsAPI($temp['access_token']);
        $accountsResult = $api->listAdAccounts();

        if (!$accountsResult['success'] || empty($accountsResult['accounts'])) {
            $this->renderAdsOAuthError('مفيش حسابات إعلانات Google Ads مرتبطة بالحساب ده. تأكد إنك مسجّل دخول بنفس حساب Google اللي عليه صلاحية Admin/Standard على حساب Google Ads فعلي (مش حساب Manager بس).<br><br>تفاصيل تقنية: ' . htmlspecialchars($accountsResult['error'] ?? '', ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $optionsHtml = '';
        foreach ($accountsResult['accounts'] as $acc) {
            $id = htmlspecialchars($acc['id'], ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars($acc['name'], ENT_QUOTES, 'UTF-8');
            $currency = htmlspecialchars($acc['currency'], ENT_QUOTES, 'UTF-8');
            $optionsHtml .= "<button class=\"p-btn outline\" style=\"width:100%;text-align:start;margin-bottom:8px;\" onclick=\"chooseAccount('{$id}')\">{$name} <span class=\"p-cell-muted\">({$currency})</span></button>";
        }

        $body = <<<HTML
        <div class="p-card">
            <div class="p-card-head"><h3>اختار حساب Google Ads</h3><span class="p-card-sub">هنربط حملاتك الحقيقية من الحساب ده</span></div>
            <div id="accountOptions">{$optionsHtml}</div>
        </div>
HTML;

        $script = <<<'JS'
window.chooseAccount = async function (accountId) {
    const res = await window.Panel.fetchJSON('/api/ads/google-ads/choose-account', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ account_id: accountId })
    });
    if (res.success) { window.location.href = '/ads'; }
    else { window.Panel.toast(res.error || 'تعذر الربط', 'error'); }
};
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'اختيار حساب Google Ads', '', $body, $script);
        exit;
    }

    /** POST /api/ads/google-ads/choose-account */
    public function chooseGoogleAdsAccount(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        $temp = $_SESSION['google_ads_oauth_temp'] ?? null;
        if (!$temp) return $this->error('انتهت الجلسة، ابدأ الربط تاني', 400);

        $accountId = $this->get('account_id');
        if (!$accountId) return $this->error('account_id مطلوب', 422);

        try {
            $website = $this->firstWebsiteForUser((int) $this->user['id']);
            if (!$website) {
                return $this->error('لازم يكون عندك موقع مضاف الأول من صفحة "المواقع"', 422);
            }

            $encryption = new Encryption();
            $encryptedAccessToken = $encryption->encrypt($temp['access_token']);
            $encryptedRefreshToken = !empty($temp['refresh_token']) ? $encryption->encrypt($temp['refresh_token']) : null;
            $expiresAt = date('Y-m-d H:i:s', time() + (int) $temp['expires_in']);

            $existing = $this->db->query(
                "SELECT id FROM platform_connections WHERE website_id = ? AND platform = 'google_ads' LIMIT 1",
                [$website['id']]
            );

            if (!empty($existing)) {
                $this->db->exec(
                    "UPDATE platform_connections SET access_token = ?, refresh_token = COALESCE(?, refresh_token), token_expires_at = ?, external_account_id = ?, status = 'connected', last_error = NULL, connected_at = NOW() WHERE id = ?",
                    [$encryptedAccessToken, $encryptedRefreshToken, $expiresAt, $accountId, $existing[0]['id']]
                );
            } else {
                $this->db->exec(
                    "INSERT INTO platform_connections (website_id, user_id, platform, access_token, refresh_token, token_expires_at, external_account_id, status)
                     VALUES (?, ?, 'google_ads', ?, ?, ?, ?, 'connected')",
                    [$website['id'], $this->user['id'], $encryptedAccessToken, $encryptedRefreshToken, $expiresAt, $accountId]
                );
            }

            unset($_SESSION['google_ads_oauth_temp']);
            return $this->success([], 'تم ربط حساب Google Ads');
        } catch (Exception $e) {
            Logger::error('chooseGoogleAdsAccount Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حفظ الربط', 500);
        }
    }

    /** GET /api/ads/google-ads/status */
    public function getGoogleAdsConnectionStatus(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        if (!$this->isGoogleAdsConfigured()) {
            return $this->success(['configured' => false, 'connected' => false]);
        }

        try {
            $row = $this->db->query(
                "SELECT external_account_id FROM platform_connections
                 WHERE user_id = ? AND platform = 'google_ads' AND status = 'connected' LIMIT 1",
                [$this->user['id']]
            );

            if (empty($row)) {
                return $this->success(['configured' => true, 'connected' => false]);
            }

            return $this->success([
                'configured' => true,
                'connected' => true,
                'external_account_id' => $row[0]['external_account_id'],
            ]);
        } catch (Exception $e) {
            Logger::error('getGoogleAdsConnectionStatus Error', ['message' => $e->getMessage()]);
            return $this->success(['configured' => true, 'connected' => false]);
        }
    }

    /** POST /api/ads/google-ads/sync - سحب حملات حقيقية من Google Ads وتحديث ad_campaigns */
    public function syncGoogleAdsCampaigns(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        try {
            $connection = $this->db->query(
                "SELECT id, website_id, access_token, refresh_token, token_expires_at, external_account_id FROM platform_connections
                 WHERE user_id = ? AND platform = 'google_ads' AND status = 'connected' LIMIT 1",
                [$this->user['id']]
            );

            if (empty($connection)) {
                return $this->error('مفيش حساب Google Ads مربوط', 400);
            }

            $conn = $connection[0];
            $encryption = new Encryption();
            $accessToken = $encryption->decrypt($conn['access_token']);

            // Google access_token عمره ساعة بس - لو منتهي بنجدده فورًا بالـ refresh_token المحفوظ
            $isExpired = empty($conn['token_expires_at']) || strtotime($conn['token_expires_at']) <= (time() + 60);
            if ($isExpired && !empty($conn['refresh_token'])) {
                $refreshResult = $this->googleAdsOAuthClient()->refreshAccessToken($encryption->decrypt($conn['refresh_token']));
                if ($refreshResult['success']) {
                    $accessToken = $refreshResult['access_token'];
                    $this->db->exec(
                        "UPDATE platform_connections SET access_token = ?, token_expires_at = ? WHERE id = ?",
                        [$encryption->encrypt($accessToken), date('Y-m-d H:i:s', time() + (int) $refreshResult['expires_in']), $conn['id']]
                    );
                }
            }

            $api = new GoogleAdsAPI($accessToken);
            $result = $api->listCampaignsWithInsights($conn['external_account_id']);

            if (!$result['success']) {
                $this->db->exec(
                    "UPDATE platform_connections SET status = 'error', last_error = ? WHERE id = ?",
                    [$result['error'] ?? 'unknown error', $conn['id']]
                );
                return $this->error('تعذرت المزامنة مع Google Ads: ' . ($result['error'] ?? ''), 502);
            }

            $synced = 0;
            foreach ($result['campaigns'] as $c) {
                $existing = $this->db->query(
                    "SELECT id FROM ad_campaigns WHERE user_id = ? AND external_campaign_id = ? LIMIT 1",
                    [$this->user['id'], $c['external_campaign_id']]
                );

                if (!empty($existing)) {
                    $this->db->exec(
                        "UPDATE ad_campaigns SET name = ?, objective = ?, daily_budget = ?, status = ?, impressions = ?, clicks = ?, spend = ?, started_at = ?, ended_at = ?, updated_at = NOW()
                         WHERE id = ?",
                        [$c['name'], $c['objective'], $c['daily_budget'], $c['status'], $c['impressions'], $c['clicks'], $c['spend'], $c['started_at'], $c['ended_at'], $existing[0]['id']]
                    );
                } else {
                    $this->db->exec(
                        "INSERT INTO ad_campaigns (user_id, website_id, platform_connection_id, platform, name, objective, daily_budget, status, external_campaign_id, impressions, clicks, spend, started_at, ended_at)
                         VALUES (?, ?, ?, 'google_ads', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$this->user['id'], $conn['website_id'], $conn['id'], $c['name'], $c['objective'], $c['daily_budget'], $c['status'], $c['external_campaign_id'], $c['impressions'], $c['clicks'], $c['spend'], $c['started_at'], $c['ended_at']]
                    );
                }
                $synced++;
            }

            $this->db->exec("UPDATE platform_connections SET last_synced_at = NOW(), status = 'connected', last_error = NULL WHERE id = ?", [$conn['id']]);

            return $this->success(['synced' => $synced]);
        } catch (Exception $e) {
            Logger::error('syncGoogleAdsCampaigns Error', ['message' => $e->getMessage()]);
            return $this->error('تعذرت المزامنة', 500);
        }
    }

    /** POST /api/ads/google-ads/disconnect */
    public function disconnectGoogleAds(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        try {
            $this->db->exec(
                "UPDATE platform_connections SET status = 'disconnected', access_token = NULL, refresh_token = NULL WHERE user_id = ? AND platform = 'google_ads'",
                [$this->user['id']]
            );
            return $this->success([], 'تم فصل الربط');
        } catch (Exception $e) {
            Logger::error('disconnectGoogleAds Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الفصل', 500);
        }
    }

    private function firstWebsiteForUser(int $userId): ?array {
        $rows = $this->db->query("SELECT id FROM websites WHERE user_id = ? ORDER BY created_at ASC LIMIT 1", [$userId]);
        return $rows[0] ?? null;
    }

    private function renderAdsOAuthError(string $message): void {
        $body = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">⚠️</div>' . $message . '<br><br><a href="/ads" class="p-btn primary">الرجوع لصفحة الإعلانات</a></div></div>';
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'تعذر الربط', 'إدارة الإعلانات', $body, '');
    }
}
