<?php

/**
 * Tourfecto - Integrations Controller
 * صفحة واحدة موحّدة تجمع كل نقاط الربط الحقيقية الموجودة في المنصة
 * (Google Business, TripAdvisor, Google Search Console, نشر المقالات
 * على ووردبريس/أي موقع تاني, واتساب UltraMsg, Meta Ads) بدل ما تكون
 * متفرقة في صفحات مختلفة.
 *
 * ملاحظة مهمة: الصفحة دي بتعرض بس الاتصالات اللي فعلاً شغالة في الكود
 * (كل واحدة منها لها controller/endpoint حقيقي بيتكلم مع الـ API
 * الخاص بالمنصة). لو حبيت تضيف تكامل جديد مستقبلاً، ضيفه هنا كـ"كارت"
 * جديد بعد ما تبني الـ connect/disconnect/status بتاعه الحقيقي - مش قبل
 * كده، عشان الصفحة تفضل معبّرة عن الواقع.
 * @version 1.0.0
 */
class IntegrationsController extends Controller
{
    /** GET /integrations */
    public function index(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/integrations'));
            exit;
        }

        $body = <<<'HTML'
        <div class="p-card">
            <label class="form-label">الموقع</label>
            <select id="intWebsiteId" class="p-select" style="width:100%;max-width:420px;"></select>
            <p class="p-cell-muted" style="margin-top:6px;">التكاملات اللي مرتبطة بموقع معيّن بتتغيّر لما تغيّر الموقع من هنا.</p>
        </div>

        <h3 style="margin:22px 0 10px;">⭐ السمعة والتقييمات</h3>
        <div class="p-grid cols-2" id="grid_reputation"></div>

        <h3 style="margin:22px 0 10px;">🔍 SEO</h3>
        <div class="p-grid cols-2" id="grid_seo"></div>

        <h3 style="margin:22px 0 10px;">✍️ نشر المحتوى</h3>
        <div class="p-grid cols-2" id="grid_publishing"></div>

        <h3 style="margin:22px 0 10px;">💬 المحادثات</h3>
        <div class="p-grid cols-2" id="grid_chat"></div>

        <h3 style="margin:22px 0 10px;">📣 الإعلانات (على مستوى الحساب كله)</h3>
        <div class="p-grid cols-2" id="grid_ads"></div>

        <h3 style="margin:22px 0 10px;">🎫 منصات الحجز والوساطة السياحية (OTA)</h3>
        <div class="p-grid cols-2" id="grid_ota"></div>

        <!-- مودال ربط منصة OTA (GetYourGuide / Viator) -->
        <div class="p-modal-overlay" id="otaModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3 id="otaModalTitle">🎫 ربط منصة</h3><button class="p-modal-close" onclick="closeModal('otaModal')">×</button></div>
                <div class="p-modal-body">
                    <label class="form-label" id="otaCredLabel">Access Token</label>
                    <input type="text" id="otaCredential" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label">Partner / Supplier ID (اختياري)</label>
                    <input type="text" id="otaPartnerId" class="form-control" style="margin-bottom:6px;">
                    <p class="p-cell-muted" style="font-size:12.5px;" id="otaHelp"></p>
                    <div id="otaAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" id="otaConfirmBtn" onclick="confirmConnectOTA()">ربط</button></div>
            </div>
        </div>

        <!-- مودال ربط النشر (ووردبريس / API مخصص) -->
        <div class="p-modal-overlay" id="pubModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>✍️ ربط نشر المقالات</h3><button class="p-modal-close" onclick="closeModal('pubModal')">×</button></div>
                <div class="p-modal-body">
                    <div style="display:flex;gap:10px;margin-bottom:14px;">
                        <button type="button" class="p-btn outline xs" id="pubTabWpBtn" onclick="switchPubTab('wordpress')">🅆 ووردبريس</button>
                        <button type="button" class="p-btn outline xs" id="pubTabCustomBtn" onclick="switchPubTab('custom_api')">🔧 أي موقع تاني</button>
                    </div>
                    <div id="pubTab_wordpress">
                        <label class="form-label">رابط الموقع</label>
                        <input type="url" id="pubSiteUrl" class="form-control" placeholder="https://example.com" style="margin-bottom:10px;">
                        <label class="form-label">اسم المستخدم (ووردبريس)</label>
                        <input type="text" id="pubUsername" class="form-control" style="margin-bottom:10px;">
                        <label class="form-label">Application Password</label>
                        <input type="text" id="pubAppPassword" class="form-control" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx" style="margin-bottom:6px;">
                        <p class="p-cell-muted" style="font-size:12.5px;">من لوحة تحكم موقعك: Users → Profile → Application Passwords.</p>
                    </div>
                    <div id="pubTab_custom_api" style="display:none;">
                        <label class="form-label">رابط نقطة الاستقبال (Endpoint)</label>
                        <input type="url" id="pubEndpointUrl" class="form-control" placeholder="https://example.com/tourfecto-publish" style="margin-bottom:10px;">
                        <label class="form-label">مفتاح سري (اختياري)</label>
                        <input type="text" id="pubAccessToken" class="form-control" style="margin-bottom:6px;">
                        <p class="p-cell-muted" style="font-size:12.5px;">لأي موقع مش ووردبريس - ابعت لمبرمج الموقع دليل CUSTOM_PUBLISHING_INTEGRATION.md.</p>
                    </div>
                    <div id="pubAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" id="pubConfirmBtn" onclick="confirmConnectPublishing()">ربط</button></div>
            </div>
        </div>

        <!-- مودال ربط واتساب UltraMsg -->
        <div class="p-modal-overlay" id="waModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>💬 ربط واتساب (UltraMsg)</h3><button class="p-modal-close" onclick="closeModal('waModal')">×</button></div>
                <div class="p-modal-body">
                    <label class="form-label">Instance ID</label>
                    <input type="text" id="waInstanceId" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label">API Token</label>
                    <input type="text" id="waApiKey" class="form-control" style="margin-bottom:6px;">
                    <p class="p-cell-muted" style="font-size:12.5px;">من لوحة تحكم UltraMsg بتاعتك (ultramsg.com) - Instance ID والـ Token بيظهروا في صفحة الـ Instance.</p>
                    <div id="waAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" id="waConfirmBtn" onclick="confirmConnectUltraMsg()">ربط</button></div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    let websiteId = null;

    function card(icon, title, connected, subtitle, actionsHtml) {
        return `
            <div class="p-card">
                <div class="p-card-head">
                    <h3>${icon} ${esc(title)}</h3>
                    <span class="pill ${connected ? 'green' : ''}">${connected ? '✔ متصل' : 'غير متصل'}</span>
                </div>
                ${subtitle ? `<p class="p-cell-muted" style="direction:ltr;text-align:left;font-size:13px;">${esc(subtitle)}</p>` : ''}
                <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">${actionsHtml}</div>
            </div>`;
    }

    window.closeModal = (id) => document.getElementById(id).classList.remove('open');

    async function loadWebsites() {
        const res = await fetchJSON('/api/websites');
        const sel = document.getElementById('intWebsiteId');
        if (res.success && res.data.websites && res.data.websites.length) {
            sel.innerHTML = res.data.websites.map(w => `<option value="${w.id}">${esc(w.company_name || w.main_url)}</option>`).join('');
            const P = window.Panel;
            const globalId = P.getCurrentWebsiteId();
            const validIds = res.data.websites.map(w => String(w.id));
            websiteId = (globalId && validIds.includes(String(globalId))) ? globalId : res.data.websites[0].id;
            sel.value = websiteId;
        } else {
            sel.innerHTML = '<option value="">لا يوجد مواقع - ضيف موقع الأول</option>';
        }
    }
    document.getElementById('intWebsiteId').addEventListener('change', (e) => { websiteId = e.target.value; window.Panel.setCurrentWebsiteId(websiteId); loadPerWebsiteCards(); });

    async function loadReputation() {
        const grid = document.getElementById('grid_reputation');
        if (!websiteId) { grid.innerHTML = ''; return; }
        const res = await fetchJSON('/api/reputation/platforms?website_id=' + websiteId);
        const d = res.success ? res.data : {};

        grid.innerHTML =
            card('🟦', 'Google Business Profile', !!d.google_connected, d.google_location_name,
                d.google_connected
                    ? `<button class="p-btn outline xs" onclick="disconnectSimple('/api/reputation/disconnect/google/' + websiteId, loadReputation)">فصل</button>`
                    : `<a class="p-btn primary xs" href="/reputation/connect/google/${websiteId}">ربط</a>`) +
            card('🦉', 'TripAdvisor', !!d.tripadvisor_connected, d.tripadvisor_location_name,
                d.tripadvisor_connected
                    ? `<button class="p-btn outline xs" onclick="disconnectSimple('/api/reputation/disconnect/tripadvisor/' + websiteId, loadReputation)">فصل</button>`
                    : `<a class="p-btn primary xs" href="/reputation/connect/tripadvisor/${websiteId}">ربط</a>`);
    }

    async function loadSeo() {
        const grid = document.getElementById('grid_seo');
        if (!websiteId) { grid.innerHTML = ''; return; }
        const res = await fetchJSON('/api/search-console/stats/' + websiteId);
        const connected = !!res.success;
        grid.innerHTML = card('🔍', 'Google Search Console', connected, connected ? res.data.site_url : '',
            connected
                ? `<button class="p-btn outline xs" onclick="disconnectSimple('/api/search-console/disconnect/' + websiteId, loadSeo)">فصل</button>`
                : `<a class="p-btn primary xs" href="/search-console/connect/${websiteId}">ربط</a>`);
    }

    async function loadPublishing() {
        const grid = document.getElementById('grid_publishing');
        if (!websiteId) { grid.innerHTML = ''; return; }
        const res = await fetchJSON('/api/publishing/status/' + websiteId);
        const connected = res.success && res.data.connected;
        grid.innerHTML = card('✍️', 'نشر المقالات', connected, connected ? `${res.data.label} · ${res.data.target}` : '',
            connected
                ? `<button class="p-btn outline xs" onclick="disconnectSimple('/api/publishing/disconnect/' + websiteId, loadPublishing)">فصل</button>`
                : `<button class="p-btn primary xs" onclick="openModal('pubModal')">ربط</button>`);
    }

    async function loadChat() {
        const grid = document.getElementById('grid_chat');
        if (!websiteId) { grid.innerHTML = ''; return; }
        const res = await fetchJSON('/api/chat/ultramsg/status?website_id=' + websiteId);
        const connected = res.success && res.data.connected;
        grid.innerHTML = card('💬', 'واتساب (UltraMsg)', connected, connected ? 'Instance: ' + res.data.instance_id : '',
            connected
                ? `<button class="p-btn outline xs" onclick="disconnectSimple('/api/chat/disconnect/ultramsg/' + websiteId, loadChat)">فصل</button>`
                : `<button class="p-btn primary xs" onclick="openModal('waModal')">ربط</button>`);
    }

    async function loadAds() {
        const grid = document.getElementById('grid_ads');
        const res = await fetchJSON('/api/ads/meta/status');
        const d = res.success ? res.data : {};
        if (!d.configured) {
            grid.innerHTML = card('📘', 'Meta Ads', false, 'لسه مش مفعّل من إدارة النظام', '');
            return;
        }
        grid.innerHTML = card('📘', 'Meta Ads (فيسبوك/انستجرام)', !!d.connected, d.connected ? 'Account: ' + d.external_account_id : '',
            d.connected
                ? `<button class="p-btn outline xs" onclick="disconnectSimple('/api/ads/meta/disconnect', loadAds)">فصل</button>`
                : `<a class="p-btn primary xs" href="/ads/connect/meta">ربط</a>`);
    }

    const OTA_PLATFORMS = {
        getyourguide: {
            label: 'GetYourGuide',
            icon: '🟠',
            credLabel: 'Access Token (X-ACCESS-TOKEN)',
            help: 'من حساب الـ Partner بتاعك على partner.getyourguide.com. لو مفيش عندك حساب Partner لسه، اطلبه الأول من GetYourGuide.',
        },
        viator: {
            label: 'Viator',
            icon: '🟢',
            credLabel: 'API Key (exp-api-key)',
            help: 'من حساب الـ Affiliate/Partner بتاعك على Viator. لو المفتاح مش ظاهر، تواصل مع affiliateapi@viator.com.',
        },
    };
    let otaActivePlatform = null;

    async function loadOTA() {
        const grid = document.getElementById('grid_ota');
        if (!websiteId) { grid.innerHTML = ''; return; }
        let html = '';
        for (const platform of Object.keys(OTA_PLATFORMS)) {
            const meta = OTA_PLATFORMS[platform];
            const res = await fetchJSON('/api/ota/status?website_id=' + websiteId + '&platform=' + platform);
            const d = res.success ? res.data : {};
            html += card(meta.icon, meta.label, !!d.connected, d.connected ? (d.external_account_id || '') : '',
                d.connected
                    ? `<button class="p-btn outline xs" onclick="disconnectSimple('/api/ota/disconnect/${platform}/' + websiteId, loadOTA)">فصل</button>`
                    : `<button class="p-btn primary xs" onclick="openOtaModal('${platform}')">ربط</button>`);
        }
        grid.innerHTML = html;
    }

    window.openOtaModal = function (platform) {
        otaActivePlatform = platform;
        const meta = OTA_PLATFORMS[platform];
        document.getElementById('otaModalTitle').textContent = meta.icon + ' ربط ' + meta.label;
        document.getElementById('otaCredLabel').textContent = meta.credLabel;
        document.getElementById('otaHelp').textContent = meta.help;
        document.getElementById('otaCredential').value = '';
        document.getElementById('otaPartnerId').value = '';
        document.getElementById('otaAlert').style.display = 'none';
        openModal('otaModal');
    };

    window.confirmConnectOTA = async function () {
        const alertBox = document.getElementById('otaAlert');
        alertBox.style.display = 'none';
        const credential = document.getElementById('otaCredential').value.trim();
        const partner_id = document.getElementById('otaPartnerId').value.trim();
        if (!credential) { alertBox.textContent = 'حط المفتاح الأول'; alertBox.style.display = 'block'; return; }

        const btn = document.getElementById('otaConfirmBtn');
        btn.disabled = true;
        const res = await fetchJSON('/api/ota/connect', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: websiteId, platform: otaActivePlatform, credential, partner_id }),
        });
        btn.disabled = false;

        if (!res.success) { alertBox.textContent = res.error || 'تعذر الربط'; alertBox.style.display = 'block'; return; }
        toast('تم الربط بنجاح ✔', 'success');
        closeModal('otaModal');
        loadOTA();
    };

    window.disconnectSimple = async function (url, reload) {
        if (!confirm('متأكد من الفصل؟')) return;
        const res = await fetchJSON(url, { method: 'POST' });
        if (res.success) { toast('تم الفصل', 'success'); reload(); }
        else toast(res.error || 'تعذر الفصل', 'error');
    };

    window.openModal = (id) => {
        document.getElementById(id).classList.add('open');
        if (id === 'pubModal') switchPubTab('wordpress');
    };

    window.switchPubTab = function (tab) {
        document.getElementById('pubTab_wordpress').style.display = tab === 'wordpress' ? 'block' : 'none';
        document.getElementById('pubTab_custom_api').style.display = tab === 'custom_api' ? 'block' : 'none';
        document.getElementById('pubTabWpBtn').classList.toggle('primary', tab === 'wordpress');
        document.getElementById('pubTabCustomBtn').classList.toggle('primary', tab === 'custom_api');
        document.getElementById('pubModal').dataset.activeTab = tab;
    };

    window.confirmConnectPublishing = async function () {
        const alertBox = document.getElementById('pubAlert');
        alertBox.style.display = 'none';
        const tab = document.getElementById('pubModal').dataset.activeTab || 'wordpress';
        const payload = { website_id: websiteId };
        let url = '/api/publishing/wordpress/connect';

        if (tab === 'custom_api') {
            url = '/api/publishing/custom/connect';
            payload.endpoint_url = document.getElementById('pubEndpointUrl').value.trim();
            payload.access_token = document.getElementById('pubAccessToken').value.trim();
            if (!payload.endpoint_url) { alertBox.textContent = 'حط رابط نقطة الاستقبال'; alertBox.style.display = 'block'; return; }
        } else {
            payload.site_url = document.getElementById('pubSiteUrl').value.trim();
            payload.username = document.getElementById('pubUsername').value.trim();
            payload.app_password = document.getElementById('pubAppPassword').value.trim();
            if (!payload.site_url || !payload.username || !payload.app_password) { alertBox.textContent = 'كمّل كل الحقول'; alertBox.style.display = 'block'; return; }
        }

        const btn = document.getElementById('pubConfirmBtn');
        btn.disabled = true;
        const res = await fetchJSON(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        btn.disabled = false;

        if (!res.success) { alertBox.textContent = res.error || 'تعذر الربط'; alertBox.style.display = 'block'; return; }
        toast('تم الربط بنجاح ✔', 'success');
        closeModal('pubModal');
        loadPublishing();
    };

    window.confirmConnectUltraMsg = async function () {
        const alertBox = document.getElementById('waAlert');
        alertBox.style.display = 'none';
        const instance_id = document.getElementById('waInstanceId').value.trim();
        const api_key = document.getElementById('waApiKey').value.trim();
        if (!instance_id || !api_key) { alertBox.textContent = 'كمّل الحقلين'; alertBox.style.display = 'block'; return; }

        const btn = document.getElementById('waConfirmBtn');
        btn.disabled = true;
        const res = await fetchJSON('/api/chat/connect/ultramsg', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: websiteId, instance_id, api_key }),
        });
        btn.disabled = false;

        if (!res.success) { alertBox.textContent = res.error || 'تعذر الربط'; alertBox.style.display = 'block'; return; }
        toast('تم الربط بنجاح ✔', 'success');
        closeModal('waModal');
        loadChat();
    };

    function loadPerWebsiteCards() {
        loadReputation();
        loadSeo();
        loadPublishing();
        loadChat();
        loadOTA();
    }

    loadWebsites().then(() => { loadPerWebsiteCards(); loadAds(); });
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_integrations', 'الربط والتكاملات', 'كل نقاط ربط موقعك وحساباتك الخارجية في مكان واحد', $body, $script);
        exit;
    }
}
