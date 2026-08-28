<?php

/**
 * Tourfecto - Website Builder Controller
 * معالج شات موجّه لتوليد موقع سياحي كامل، + عرض عام للموقع المولّد على
 * tourfecto.pro/sites/{slug}، + تعديل بسيط بعد التوليد.
 * @version 1.0.0
 */
class WebsiteBuilderController extends Controller
{
    /** @var WebsiteBuilderService */
    private $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new WebsiteBuilderService();
    }

    /** GET /website-builder */
    public function index(array $params = []): array
    {
        $tTitle = $this->tr('wb.title');
        $tSubtitle = $this->tr('wb.subtitle');
        $tPlaceholder = $this->tr('wb.input_placeholder');
        $tMyWebsites = $this->tr('wb.my_websites');

        $body = <<<HTML
        <div class="assistant-shell">
            <aside class="assistant-sidebar">
                <button class="p-btn primary btn-block" onclick="restartWizard()">+ {$this->tr('wb.new_website')}</button>
                <h4 style="margin:16px 0 8px;font-size:12.5px;color:var(--panel-text-muted);">{$tMyWebsites}</h4>
                <div id="myWebsitesList" class="assistant-conv-list"><div class="p-empty">{$this->tr('common.loading')}</div></div>
            </aside>
            <main class="assistant-main">
                <div id="messagesArea" class="assistant-messages"></div>
                <div class="assistant-input-box">
                    <textarea id="messageInput" rows="1" placeholder="{$tPlaceholder}" onkeydown="handleInputKeydown(event)"></textarea>
                    <button id="sendBtn" class="p-btn primary" onclick="sendAnswer()">➤</button>
                </div>
                <div id="wbAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
            </main>
        </div>

        <div class="p-modal-overlay" id="editSiteModal">
            <div class="p-modal" style="max-width:720px;">
                <div class="p-modal-head"><h3>{$this->tr('wb.manage_title')}</h3><button class="p-modal-close" onclick="P.closeModal('editSiteModal')">×</button></div>
                <div class="p-modal-body">
                    <div class="p-tabs" style="margin-bottom:16px;">
                        <button class="p-tab active" id="mgTabGeneralBtn" onclick="switchManageTab('general')">⚙️ {$this->tr('wb.tab_general')}</button>
                        <button class="p-tab" id="mgTabToursBtn" onclick="switchManageTab('tours')">🧳 {$this->tr('wb.tab_tours')}</button>
                    </div>

                    <div id="mgTabGeneral">
                        <label class="form-label">{$this->tr('wb.field.theme_color')}</label>
                        <select id="editThemeColor" class="form-control" style="margin-bottom:10px;">
                            <option value="gold">🟡 {$this->tr('wb.theme.gold')}</option>
                            <option value="blue">🔵 {$this->tr('wb.theme.blue')}</option>
                            <option value="green">🟢 {$this->tr('wb.theme.green')}</option>
                            <option value="red">🔴 {$this->tr('wb.theme.red')}</option>
                            <option value="purple">🟣 {$this->tr('wb.theme.purple')}</option>
                        </select>
                        <label class="form-label">{$this->tr('wb.field.business_name')}</label>
                        <input type="text" id="editBusinessName" class="form-control" style="margin-bottom:10px;">
                        <label class="form-label">{$this->tr('wb.field.tagline')}</label>
                        <input type="text" id="editTagline" class="form-control" style="margin-bottom:10px;">
                        <label class="form-label">{$this->tr('wb.field.hero_headline')}</label>
                        <input type="text" id="editHeroHeadline" class="form-control" style="margin-bottom:10px;">
                        <label class="form-label">{$this->tr('wb.field.hero_subtext')}</label>
                        <input type="text" id="editHeroSubtext" class="form-control" style="margin-bottom:10px;">
                        <label class="form-label">{$this->tr('wb.field.about_text')}</label>
                        <textarea id="editAboutText" class="form-control" rows="3" style="margin-bottom:10px;"></textarea>
                        <label class="form-label">{$this->tr('wb.field.cta_text')}</label>
                        <input type="text" id="editCtaText" class="form-control" style="margin-bottom:10px;">
                        <div class="p-grid cols-2">
                            <div><label class="form-label">{$this->tr('wb.field.contact_phone')}</label><input type="text" id="editPhone" class="form-control" dir="ltr"></div>
                            <div><label class="form-label">{$this->tr('wb.field.contact_whatsapp')}</label><input type="text" id="editWhatsapp" class="form-control" dir="ltr"></div>
                            <div><label class="form-label">{$this->tr('wb.field.contact_email')}</label><input type="email" id="editEmail" class="form-control" dir="ltr"></div>
                            <div><label class="form-label">{$this->tr('wb.field.contact_address')}</label><input type="text" id="editAddress" class="form-control"></div>
                        </div>
                        <button class="p-btn primary" style="margin-top:14px;" onclick="saveSiteEdit()">{$this->tr('common.save')}</button>
                    </div>

                    <div id="mgTabTours" style="display:none;">
                        <div id="toursManageList"></div>
                        <div class="p-card" style="margin-top:14px;">
                            <h4 style="margin-bottom:8px;font-size:13.5px;">➕ {$this->tr('wb.add_tour_title')}</h4>
                            <textarea id="newTourDescription" class="form-control" rows="2" placeholder="{$this->tr('wb.add_tour_placeholder')}" style="margin-bottom:10px;"></textarea>
                            <button class="p-btn primary" onclick="addNewTour()">✨ {$this->tr('wb.add_tour_button')}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    let editingWebsiteId = null;

    function addMessage(role, html) {
        const area = document.getElementById('messagesArea');
        const div = document.createElement('div');
        div.className = 'assistant-msg ' + role;
        div.innerHTML = `<div class="assistant-msg-bubble">${html}</div>`;
        area.appendChild(div);
        area.scrollTop = area.scrollHeight;
    }

    function showQuickOptions(options) {
        if (!options || !options.length) return;
        const area = document.getElementById('messagesArea');
        const div = document.createElement('div');
        div.className = 'ws-quick-options';
        div.innerHTML = options.map(opt => `<button class="p-btn outline xs" onclick="pickOption(this, '${esc(opt).replace(/'/g, "\\'")}')">${esc(opt)}</button>`).join('');
        area.appendChild(div);
        area.scrollTop = area.scrollHeight;
    }

    window.pickOption = function (btn, value) {
        // نمنع الضغط تاني على نفس الأزرار بعد الاختيار
        btn.parentElement.querySelectorAll('button').forEach(b => b.disabled = true);
        submitAnswerValue(value);
    };

    async function loadState() {
        const res = await fetchJSON('/api/website-builder/state');
        if (!res.success) return;
        document.getElementById('messagesArea').innerHTML = '';
        if (res.data.done) {
            addMessage('assistant', I18N['wb.ready_message']);
            showGenerateButton();
        } else {
            addMessage('assistant', esc(res.data.question));
            showQuickOptions(res.data.options);
        }
    }

    function showGenerateButton() {
        const area = document.getElementById('messagesArea');
        const div = document.createElement('div');
        div.className = 'assistant-msg assistant';
        div.innerHTML = `<div class="assistant-msg-bubble"><button class="p-btn primary" onclick="generateSite()">✨ ${I18N['wb.generate_button']}</button></div>`;
        area.appendChild(div);
        area.scrollTop = area.scrollHeight;
    }

    window.handleInputKeydown = function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendAnswer(); }
    };

    window.sendAnswer = async function () {
        const input = document.getElementById('messageInput');
        const text = input.value.trim();
        if (!text) return;
        input.value = '';
        await submitAnswerValue(text);
    };

    async function submitAnswerValue(text) {
        addMessage('user', esc(text));

        const res = await fetchJSON('/api/website-builder/answer', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ message: text }),
        });

        if (res.success) {
            if (res.data.done) {
                addMessage('assistant', I18N['wb.ready_message']);
                showGenerateButton();
            } else {
                addMessage('assistant', esc(res.data.question));
                showQuickOptions(res.data.options);
            }
        }
    }

    window.generateSite = async function () {
        const alertBox = document.getElementById('wbAlert');
        alertBox.style.display = 'none';
        addMessage('assistant', '⏳ ' + I18N['wb.generating']);
        document.getElementById('sendBtn').disabled = true;

        const res = await fetchJSON('/api/website-builder/generate', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        document.getElementById('sendBtn').disabled = false;

        if (res.success) {
            const w = res.data.website;
            addMessage('assistant', `✅ ${I18N['wb.generated_success']} <br><a href="/sites/${w.slug}" target="_blank" class="p-btn outline xs" style="margin-top:8px;display:inline-block;">👁 ${I18N['wb.preview']}</a> <button class="p-btn success xs" onclick="publishSite(${w.id})" style="margin-top:8px;">🚀 ${I18N['wb.publish']}</button>`);
            loadMyWebsites();
        } else {
            if (res.data && res.data.shortfall) {
                alertBox.textContent = I18N['wb.insufficient_balance'].replace('{amount}', res.data.shortfall);
            } else {
                alertBox.textContent = res.error || I18N['wb.generate_failed'];
            }
            alertBox.style.display = 'block';
        }
    };

    window.publishSite = async function (id) {
        const res = await fetchJSON('/api/website-builder/' + id + '/publish', { method: 'POST' });
        if (res.success) { toast(I18N['wb.published'], 'success'); loadMyWebsites(); }
        else toast(res.error || I18N['common.update_failed'], 'error');
    };

    window.restartWizard = async function () {
        await fetchJSON('/api/website-builder/reset', { method: 'POST' });
        loadState();
    };

    async function loadMyWebsites() {
        const res = await fetchJSON('/api/website-builder/my-websites');
        const box = document.getElementById('myWebsitesList');
        if (!res.success || !res.data.websites || !res.data.websites.length) {
            box.innerHTML = `<div class="p-cell-muted" style="padding:8px;font-size:11.5px;">${I18N['wb.no_websites']}</div>`;
            return;
        }
        box.innerHTML = res.data.websites.map(w => `
            <div class="assistant-conv-item">
                <span>${esc(w.business_name)} ${w.status === 'published' ? '✅' : '📝'}</span>
                <a href="/dashboard/sites/${w.id}" class="conv-delete" title="لوحة التحكم">⚙️</a>
                <button class="conv-delete" onclick="editWebsite(${w.id})" title="${I18N['common.edit']}">✏️</button>
            </div>`).join('');
    }

    let currentWebsiteContent = null;

    window.editWebsite = async function (id) {
        editingWebsiteId = id;
        const res = await fetchJSON('/api/website-builder/' + id);
        if (!res.success) return;
        currentWebsiteContent = res.data.content;
        const c = currentWebsiteContent;

        document.getElementById('editBusinessName').value = c.business_name || '';
        document.getElementById('editThemeColor').value = res.data.theme_color || 'gold';
        document.getElementById('editTagline').value = c.tagline || '';
        document.getElementById('editHeroHeadline').value = c.hero_headline || '';
        document.getElementById('editHeroSubtext').value = c.hero_subtext || '';
        document.getElementById('editAboutText').value = c.about_text || '';
        document.getElementById('editCtaText').value = c.cta_text || '';
        document.getElementById('editPhone').value = (c.contact && c.contact.phone) || '';
        document.getElementById('editWhatsapp').value = (c.contact && c.contact.whatsapp) || '';
        document.getElementById('editEmail').value = (c.contact && c.contact.email) || '';
        document.getElementById('editAddress').value = (c.contact && c.contact.address) || '';

        switchManageTab('general');
        renderToursManageList();
        P.openModal('editSiteModal');
    };

    window.switchManageTab = function (tab) {
        document.getElementById('mgTabGeneral').style.display = tab === 'general' ? 'block' : 'none';
        document.getElementById('mgTabTours').style.display = tab === 'tours' ? 'block' : 'none';
        document.getElementById('mgTabGeneralBtn').classList.toggle('active', tab === 'general');
        document.getElementById('mgTabToursBtn').classList.toggle('active', tab === 'tours');
    };

    function getItemsKey() {
        return (currentWebsiteContent && currentWebsiteContent.industry === 'hotel') ? 'rooms' : 'tours';
    }

    function renderToursManageList() {
        const box = document.getElementById('toursManageList');
        const itemsKey = getItemsKey();
        const items = (currentWebsiteContent && currentWebsiteContent[itemsKey]) || [];
        if (!items.length) {
            box.innerHTML = `<div class="p-cell-muted" style="padding:10px 0;">${I18N['wb.no_tours_yet']}</div>`;
            return;
        }
        box.innerHTML = items.map(t => `
            <div class="p-kv">
                <span class="k">${esc(t.name || '')} ${t.price ? '· ' + esc(t.price) : ''}</span>
                <span class="v">
                    <button class="p-btn outline xs" onclick="openEditTour('${t.slug}')">✏️ ${I18N['common.edit']}</button>
                    <button class="p-btn outline xs" onclick="deleteTour('${t.slug}')">🗑️</button>
                </span>
            </div>`).join('');
    }

    window.addNewTour = async function () {
        const desc = document.getElementById('newTourDescription').value.trim();
        if (!desc) return;
        const btn = event.target;
        btn.disabled = true;
        btn.textContent = '⏳ ' + I18N['wb.generating'];

        const res = await fetchJSON('/api/website-builder/' + editingWebsiteId + '/tours', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ description: desc }),
        });

        btn.disabled = false;
        btn.textContent = '✨ ' + I18N['wb.add_tour_button'];

        if (res.success) {
            toast(I18N['wb.tour_added'], 'success');
            document.getElementById('newTourDescription').value = '';
            const itemsKey = getItemsKey();
            currentWebsiteContent[itemsKey] = currentWebsiteContent[itemsKey] || [];
            currentWebsiteContent[itemsKey].push(res.data.tour);
            renderToursManageList();
            loadMyWebsites();
        } else {
            if (res.data && res.data.shortfall) toast(I18N['wb.insufficient_balance'].replace('{amount}', res.data.shortfall), 'error');
            else toast(res.error || I18N['wb.generate_failed'], 'error');
        }
    };

    window.deleteTour = async function (tourSlug) {
        if (!confirm(I18N['wb.delete_tour_confirm'])) return;
        const res = await fetchJSON('/api/website-builder/' + editingWebsiteId + '/tours/' + tourSlug, { method: 'DELETE' });
        if (res.success) {
            const itemsKey = getItemsKey();
            currentWebsiteContent[itemsKey] = currentWebsiteContent[itemsKey].filter(t => t.slug !== tourSlug);
            renderToursManageList();
            toast(I18N['common.deleted'], 'success');
        } else {
            toast(res.error || I18N['common.update_failed'], 'error');
        }
    };

    window.openEditTour = function (tourSlug) {
        const itemsKey = getItemsKey();
        const item = currentWebsiteContent[itemsKey].find(t => t.slug === tourSlug);
        if (!item) return;
        const newName = prompt(I18N['wb.field.business_name'] + ':', item.name || '');
        if (newName === null) return;
        const newDesc = prompt(I18N['wb.field.tagline'] + ':', item.short_description || '');
        if (newDesc === null) return;
        const newPrice = prompt(I18N['wb.field.price'] + ':', item.price || '');
        if (newPrice === null) return;

        fetchJSON('/api/website-builder/' + editingWebsiteId + '/tours/' + tourSlug, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: newName, short_description: newDesc, price: newPrice }),
        }).then(res => {
            if (res.success) {
                item.name = newName; item.short_description = newDesc; item.price = newPrice;
                renderToursManageList();
                toast(I18N['common.updated'], 'success');
            } else {
                toast(res.error || I18N['common.update_failed'], 'error');
            }
        });
    };

    window.saveSiteEdit = async function () {
        const res = await fetchJSON('/api/website-builder/' + editingWebsiteId, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                theme_color: document.getElementById('editThemeColor').value,
                business_name: document.getElementById('editBusinessName').value,
                tagline: document.getElementById('editTagline').value,
                hero_headline: document.getElementById('editHeroHeadline').value,
                hero_subtext: document.getElementById('editHeroSubtext').value,
                about_text: document.getElementById('editAboutText').value,
                cta_text: document.getElementById('editCtaText').value,
                contact_phone: document.getElementById('editPhone').value,
                contact_whatsapp: document.getElementById('editWhatsapp').value,
                contact_email: document.getElementById('editEmail').value,
                contact_address: document.getElementById('editAddress').value,
            }),
        });
        if (res.success) { toast(I18N['common.updated'], 'success'); loadMyWebsites(); }
        else toast(res.error || I18N['common.update_failed'], 'error');
    };

    loadState();
    loadMyWebsites();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('website_builder', $tTitle, $tSubtitle, $body, $script);
        exit;
    }

    /** GET /api/website-builder/state */
    public function getState(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success($this->service->getCurrentState());
    }

    /** POST /api/website-builder/answer */
    public function submitAnswer(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['message' => 'required'])) {
            return $this->error('اكتب إجابة', 422);
        }
        return $this->success($this->service->submitAnswer((string) $this->get('message')));
    }

    /** POST /api/website-builder/reset */
    public function resetWizard(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $this->service->resetWizard();
        return $this->success([]);
    }

    /** POST /api/website-builder/generate */
    public function generateSite(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->service->generateSite((int) $this->user['id']);
        if (!$result['success']) {
            return $this->error($result['error'], 402, $result);
        }
        return $this->success($result);
    }

    /** GET /api/website-builder/my-websites */
    public function myWebsites(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $websites = (new GeneratedWebsite())->where(['user_id' => (int) $this->user['id']], ['created_at' => 'DESC']);
            $result = array_map(function ($w) {
                $content = $w->getContent();
                return ['id' => $w->getAttribute('id'), 'slug' => $w->getAttribute('slug'), 'status' => $w->getAttribute('status'), 'business_name' => $content['business_name'] ?? $w->getAttribute('slug')];
            }, $websites);
            return $this->success(['websites' => $result]);
        } catch (Exception $e) {
            Logger::error('myWebsites Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الجلب', 500);
        }
    }

    /** GET /api/website-builder/{id} */
    public function getWebsite(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }
        return $this->success(['content' => $website->getContent(), 'theme_color' => $website->getAttribute('theme_color')]);
    }

    /** PUT /api/website-builder/{id} */
    public function updateWebsite(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $content = $website->getContent();
        if ($this->get('theme_color') !== null && array_key_exists((string) $this->get('theme_color'), ['gold' => 1, 'blue' => 1, 'green' => 1, 'red' => 1, 'purple' => 1])) {
            $website->setAttribute('theme_color', (string) $this->get('theme_color'));
        }
        $textFields = ['business_name', 'tagline', 'hero_headline', 'hero_subtext', 'about_title', 'about_text', 'cta_text'];
        foreach ($textFields as $field) {
            if ($this->get($field) !== null) {
                $content[$field] = (string) $this->get($field);
            }
        }
        $contactFields = ['phone', 'whatsapp', 'email', 'address'];
        foreach ($contactFields as $field) {
            if ($this->get('contact_' . $field) !== null) {
                $content['contact'][$field] = (string) $this->get('contact_' . $field);
            }
        }

        $website->setAttribute('content_json', json_encode($content, JSON_UNESCAPED_UNICODE));
        $website->save();

        return $this->success(['content' => $content], 'تم الحفظ');
    }

    /** POST /api/website-builder/{id}/tours - إضافة رحلة جديدة بالذكاء الاصطناعي من وصف مختصر */
    /** POST /api/website-builder/{id}/tours - إضافة عنصر جديد (رحلة أو غرفة حسب مجال الموقع) */
    public function addTour(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        if (!$this->validate(['description' => 'required'])) {
            return $this->error('اكتب وصف مختصر الأول', 422);
        }

        $content = $website->getContent();
        $industry = $content['industry'] ?? 'tours';
        $itemsKey = $industry === 'hotel' ? 'rooms' : 'tours';
        $existingSlugs = array_column($content[$itemsKey] ?? [], 'slug');

        $result = $this->service->generateSingleItem(
            (int) $this->user['id'],
            $industry,
            $content['service_type'] ?? '',
            $content['language'] ?? 'ar',
            (string) $this->get('description'),
            $existingSlugs
        );

        if (!$result['success']) {
            return $this->error($result['error'], 402, $result);
        }

        $item = $result['item'];

        // نولّد صورة للعنصر الجديد كمان
        $uploadsDir = ROOT_PATH . '/public_html/uploads/generated-sites/' . $this->user['id'] . '-' . $website->getAttribute('id');
        @mkdir($uploadsDir, 0755, true);
        $item['image_url'] = $this->service->generateTourImage($item, $content['service_type'] ?? '', $uploadsDir, $item['slug']);

        $content[$itemsKey][] = $item;
        $website->setAttribute('content_json', json_encode($content, JSON_UNESCAPED_UNICODE));
        $website->save();

        $this->syncTourToProduct($website, $item);

        return $this->success(['tour' => $item, 'items_key' => $itemsKey], 'تمت الإضافة', 201);
    }

    /** PUT /api/website-builder/{id}/tours/{tourSlug} - تعديل عنصر موجود (رحلة أو غرفة) */
    public function updateTour(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $itemSlug = (string) ($params['tourSlug'] ?? '');
        $content = $website->getContent();
        $industry = $content['industry'] ?? 'tours';
        $itemsKey = $industry === 'hotel' ? 'rooms' : 'tours';
        $found = false;

        // الحقول المشتركة بين المجالين + حقول خاصة بكل مجال
        $sharedFields = ['name', 'short_description', 'price'];
        $industryFields = $industry === 'hotel' ? ['capacity', 'size'] : ['duration', 'group_size'];

        foreach ($content[$itemsKey] ?? [] as &$item) {
            if (($item['slug'] ?? '') === $itemSlug) {
                $found = true;
                foreach (array_merge($sharedFields, $industryFields) as $field) {
                    if ($this->get($field) !== null) {
                        $item[$field] = (string) $this->get($field);
                    }
                }
                foreach (['highlights' => 'highlights_text', 'includes' => 'includes_text', 'excludes' => 'excludes_text', 'amenities' => 'amenities_text'] as $key => $inputField) {
                    if ($this->get($inputField) !== null) {
                        $lines = array_filter(array_map('trim', explode("\n", (string) $this->get($inputField))));
                        $item[$key] = array_values($lines);
                    }
                }
                break;
            }
        }
        unset($item);

        if (!$found) {
            return $this->error('العنصر ده مش موجود', 404);
        }

        $website->setAttribute('content_json', json_encode($content, JSON_UNESCAPED_UNICODE));
        $website->save();

        foreach ($content[$itemsKey] ?? [] as $it) {
            if (($it['slug'] ?? '') === $itemSlug) {
                $this->syncTourToProduct($website, $it);
                break;
            }
        }

        return $this->success([], 'تم التحديث');
    }

    /** DELETE /api/website-builder/{id}/tours/{tourSlug} */
    public function deleteTour(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $itemSlug = (string) ($params['tourSlug'] ?? '');
        $content = $website->getContent();
        $industry = $content['industry'] ?? 'tours';
        $itemsKey = $industry === 'hotel' ? 'rooms' : 'tours';
        $content[$itemsKey] = array_values(array_filter($content[$itemsKey] ?? [], fn ($t) => ($t['slug'] ?? '') !== $itemSlug));

        $website->setAttribute('content_json', json_encode($content, JSON_UNESCAPED_UNICODE));
        $website->save();

        $this->deactivateLinkedProduct($website, $itemSlug);

        return $this->success([], 'تم الحذف');
    }

    /** POST /api/website-builder/{id}/publish */

    public function publish(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $website->setAttribute('status', 'published');
        $website->setAttribute('last_published_at', date('Y-m-d H:i:s'));
        $website->save();

        $this->syncAllSiteItems($website);

        return $this->success([], 'تم النشر');
    }

    /** POST /sites/{slug}/lead - طلب حجز/تواصل من زائر الموقع المنشور (بدون تسجيل دخول) */
    public function submitLead(array $params = []): array
    {
        $website = $this->findViewableWebsite((string) ($params['slug'] ?? ''));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }
        if (!$this->validate(['visitor_name' => 'required'])) {
            return $this->error('اكتب اسمك من فضلك', 422);
        }

        $lead = new WebsiteLead();
        $lead->setAttribute('website_id', (int) $website->getAttribute('id'));
        $lead->setAttribute('item_id', (string) $this->get('item_id', ''));
        $lead->setAttribute('visitor_name', (string) $this->get('visitor_name'));
        $lead->setAttribute('phone', (string) $this->get('phone', ''));
        $lead->setAttribute('email', (string) $this->get('email', ''));
        $lead->setAttribute('message', (string) $this->get('message', ''));
        $lead->setAttribute('status', 'new');
        $lead->save();

        return $this->success([], 'تم إرسال طلبك، هيتم التواصل معاك قريبًا', 201);
    }

    /** POST /sites/{slug}/review - تقييم من زائر الموقع المنشور (بدون تسجيل دخول، يحتاج اعتماد صاحب الموقع) */
    public function submitReview(array $params = []): array
    {
        $website = $this->findViewableWebsite((string) ($params['slug'] ?? ''));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }
        if (!$this->validate(['visitor_name' => 'required', 'rating' => 'required'])) {
            return $this->error('اكتب اسمك وتقييمك من فضلك', 422);
        }
        $rating = (int) $this->get('rating');
        if ($rating < 1 || $rating > 5) {
            return $this->error('التقييم لازم يكون من 1 لـ 5', 422);
        }

        $review = new WebsiteReview();
        $review->setAttribute('website_id', (int) $website->getAttribute('id'));
        $review->setAttribute('item_id', (string) $this->get('item_id', ''));
        $review->setAttribute('visitor_name', (string) $this->get('visitor_name'));
        $review->setAttribute('rating', $rating);
        $review->setAttribute('comment', (string) $this->get('comment', ''));
        $review->setAttribute('status', 'pending');
        $review->save();

        return $this->success([], 'شكرًا لتقييمك! هيظهر بعد مراجعة صاحب الموقع', 201);
    }

    /**
     * GET /sites/{slug} - الصفحة العامة للموقع المولّد (بدون تسجيل دخول)
     * قالب سياحي احترافي ثابت مبني على محتوى الـ JSON المولّد.
     */
    /**
     * دالة مشتركة: تجيب الموقع بالـ slug، وتتأكد من صلاحية العرض
     * (منشور = عام، مسوّدة = لصاحبها بس).
     */
    private function findViewableWebsite(string $slug): ?GeneratedWebsite
    {
        $rows = (new GeneratedWebsite())->where(['slug' => $slug], [], 1);
        if (empty($rows)) {
            return null;
        }
        $website = $rows[0];
        if ($website->getAttribute('status') !== 'published') {
            $isOwner = $this->isAuthenticated() && (int) $website->getAttribute('user_id') === (int) $this->user['id'];
            if (!$isOwner) {
                return null;
            }
        }
        return $website;
    }

    /** ألوان جاهزة لكل ثيم - كل موقع يقدر يختار واحد منهم فيبقى شكله مختلف فعليًا */
    private const THEMES = [
        'gold' => ['accent' => '#efb05e', 'accent_rgb' => '239,176,94'],
        'blue' => ['accent' => '#5b9bd5', 'accent_rgb' => '91,155,213'],
        'green' => ['accent' => '#4caf82', 'accent_rgb' => '76,175,130'],
        'red' => ['accent' => '#e0685c', 'accent_rgb' => '224,104,92'],
        'purple' => ['accent' => '#a084e8', 'accent_rgb' => '160,132,232'],
    ];

    /**
     * Phase 5 (Auto-Apply): بقت بتاخد description/canonical/ogImage اختياريين
     * (Backward-compatible - القيمة الافتراضية null يعني نفس السلوك القديم
     * بالظبط لو حد نادى الدالة من غير الباراميترات الجداد).
     * القيم دي هي بالظبط اللي كان الـWebsite Optimizer بيكتشف غيابها كمشكلة
     * (meta_description/canonical/open_graph) على أي موقع تاني - دلوقتي
     * مواقع الـWebsite Builder نفسها ماعادتش ناقصاها.
     */
    private function siteHeadHtml(string $title, string $themeKey = 'gold', ?string $description = null, ?string $canonicalUrl = null, ?string $ogImage = null): string
    {
        $theme = self::THEMES[$themeKey] ?? self::THEMES['gold'];
        $accent = $theme['accent'];
        $accentRgb = $theme['accent_rgb'];
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

        $metaDescription = $description ? "\n    <meta name=\"description\" content=\"" . $esc($description) . "\">" : '';
        $canonicalTag = $canonicalUrl ? "\n    <link rel=\"canonical\" href=\"" . $esc($canonicalUrl) . "\">" : '';
        $ogTags = '';
        if ($description || $canonicalUrl) {
            $ogTitle = $esc($title);
            $ogDesc = $esc($description ?: $title);
            $ogUrlTag = $canonicalUrl ? "\n    <meta property=\"og:url\" content=\"" . $esc($canonicalUrl) . "\">" : '';
            $ogImageTag = $ogImage ? "\n    <meta property=\"og:image\" content=\"" . $esc($ogImage) . "\">" : '';
            $ogTags = "\n    <meta property=\"og:title\" content=\"{$ogTitle}\">\n    <meta property=\"og:description\" content=\"{$ogDesc}\">\n    <meta property=\"og:type\" content=\"website\">{$ogUrlTag}{$ogImageTag}";
        }

        return <<<HTML
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>{$metaDescription}{$canonicalTag}{$ogTags}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/generated-site.css">
    <style>:root{--ws-accent:{$accent};--ws-accent-rgb:{$accentRgb};}</style>
HTML;
    }

    /** لغة واتجاه الصفحة بناءً على اللغة اللي اختارها العميل وقت الإنشاء */
    private function siteLangAttrs(array $content): array
    {
        $lang = $content['language'] ?? 'ar';
        $dirMap = ['ar' => 'rtl', 'en' => 'ltr', 'fr' => 'ltr', 'de' => 'ltr'];
        return [$lang, $dirMap[$lang] ?? 'rtl'];
    }

    /** GET /sites/{slug} - الصفحة الرئيسية: بتتفرّع حسب المجال (رحلات أو فندق) */
    public function showPublicSite(array $params = []): array
    {
        $slug = (string) ($params['slug'] ?? '');
        $website = $this->findViewableWebsite($slug);

        if (!$website) {
            header('HTTP/1.1 404 Not Found');
            echo '<h1 style="text-align:center;font-family:sans-serif;margin-top:100px;">الموقع ده مش موجود أو لسه مش منشور</h1>';
            exit;
        }

        // عدّاد زيارات بسيط - يفيد لوحة التحكم (نتجاهل فشله عشان ميكسرش عرض الصفحة)
        try {
            $website->setAttribute('views_count', (int) $website->getAttribute('views_count') + 1);
            $website->save();
        } catch (Exception $e) { /* لا شيء - العرض أهم من العداد */
        }

        $c = $website->getContent();
        $industry = $c['industry'] ?? 'tours';

        // Phase 5 (Auto-Apply): بنمرر seo_title/seo_description الفعليين من
        // الموديل (اللي دلوقتي ممكن يتحدّثوا تلقائيًا من الـWebsite Optimizer)
        // بدل ما الـhead يعتمد بس على business_name/tagline زي الأول.
        $seoTitle = (string) ($website->getAttribute('seo_title') ?? '');
        $seoDescription = (string) ($website->getAttribute('seo_description') ?? '');
        $canonicalUrl = $this->publicSiteUrl($slug);
        $ogImage = (string) ($website->getAttribute('logo_url') ?? '');

        if ($industry === 'hotel') {
            echo $this->renderHotelHome($slug, $c, $seoTitle, $seoDescription, $canonicalUrl, $ogImage);
        } else {
            echo $this->renderToursHome($slug, $c, $seoTitle, $seoDescription, $canonicalUrl, $ogImage);
        }
        exit;
    }

    /** رابط الموقع المنشور الفعلي - يستخدم في canonical/OG (Phase 5 Auto-Apply) */
    private function publicSiteUrl(string $slug): string
    {
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        return $base . '/sites/' . rawurlencode($slug);
    }

    private function renderToursHome(string $slug, array $c, string $seoTitle = '', string $seoDescription = '', string $canonicalUrl = '', string $ogImage = ''): string
    {
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        [$lang, $dir] = $this->siteLangAttrs($c);

        $toursHtml = '';
        foreach (($c['tours'] ?? []) as $t) {
            $priceLine = !empty($t['price']) ? '<div class="ws-package-price">' . $esc($t['price']) . '</div>' : '';
            $durationLine = !empty($t['duration']) ? '<div class="ws-package-duration">' . $esc($t['duration']) . '</div>' : '';
            $imageHtml = !empty($t['image_url'])
                ? '<div class="ws-package-img" style="background-image:url(\'' . $esc($t['image_url']) . '\');"></div>'
                : '<div class="ws-package-img ws-package-img-fallback">🏝️</div>';
            $toursHtml .= '<a href="/sites/' . $esc($slug) . '/tours/' . $esc($t['slug'] ?? '') . '" class="ws-package ws-package-link">'
                . $imageHtml
                . '<div class="ws-package-body"><h3>' . $esc($t['name'] ?? '') . '</h3><p>' . $esc($t['short_description'] ?? '') . '</p>'
                . $priceLine . $durationLine . '<span class="ws-view-more">شوف التفاصيل ←</span></div></a>';
        }

        $contact = $c['contact'] ?? [];
        $whatsappLink = !empty($contact['whatsapp']) ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $contact['whatsapp']) : '#';

        $businessName = $esc($c['business_name'] ?? '');
        $tagline = $esc($c['tagline'] ?? '');
        $heroHeadline = $esc($c['hero_headline'] ?? '');
        $heroSubtext = $esc($c['hero_subtext'] ?? '');
        $aboutTitle = $esc($c['about_title'] ?? '');
        $aboutText = $esc($c['about_text'] ?? '');
        $ctaText = $esc($c['cta_text'] ?? '');
        $phone = $esc($contact['phone'] ?? '');
        $email = $esc($contact['email'] ?? '');
        $address = $esc($contact['address'] ?? '');
        $head = $this->siteHeadHtml(
            $seoTitle !== '' ? $seoTitle : "{$businessName} | {$tagline}",
            'gold',
            $seoDescription !== '' ? $seoDescription : ($c['about_text'] ?? null),
            $canonicalUrl !== '' ? $canonicalUrl : null,
            $ogImage !== '' ? $ogImage : null
        );

        header('Content-Type: text/html; charset=utf-8');
        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
{$head}
</head>
<body>
    <nav class="ws-nav">
        <div class="ws-nav-inner">
            <span class="ws-logo">🌍 {$businessName}</span>
            <div class="ws-nav-links">
                <a href="#about">عننا</a>
                <a href="#tours">رحلاتنا</a>
                <a href="#contact">تواصل معنا</a>
                <a href="{$whatsappLink}" target="_blank" class="ws-nav-cta">احجز الآن</a>
            </div>
        </div>
    </nav>

    <header class="ws-hero">
        <h1>{$heroHeadline}</h1>
        <p>{$heroSubtext}</p>
        <a href="{$whatsappLink}" target="_blank" class="ws-btn">{$ctaText}</a>
    </header>

    <section class="ws-section" id="about">
        <h2>{$aboutTitle}</h2>
        <p class="ws-about-text">{$aboutText}</p>
    </section>

    <section class="ws-section ws-section-alt" id="tours">
        <h2>رحلاتنا وبرامجنا</h2>
        <div class="ws-grid">{$toursHtml}</div>
    </section>

    <section class="ws-section">
        <h2>ليه تحجز معانا</h2>
        <div class="ws-trust-grid">
            <div class="ws-trust-item"><div class="ws-trust-icon">🧭</div><h3>خبرة محلية حقيقية</h3><p>برامج مصمّمة من ناس عارفة المنطقة كويس</p></div>
            <div class="ws-trust-item"><div class="ws-trust-icon">💬</div><h3>تواصل مباشر وسريع</h3><p>رد فوري على أي استفسار عن طريق واتساب</p></div>
            <div class="ws-trust-item"><div class="ws-trust-icon">✅</div><h3>تفاصيل واضحة من الأول</h3><p>تعرف بالظبط إيه شامل وإيه لأ قبل ما تحجز</p></div>
        </div>
    </section>

    <section class="ws-section ws-section-alt ws-contact" id="contact">
        <h2>تواصل معنا</h2>
        <p>📞 {$phone}</p>
        <p>✉️ {$email}</p>
        <p>📍 {$address}</p>
        <a href="{$whatsappLink}" target="_blank" class="ws-btn">تواصل عبر واتساب</a>
    </section>

    <footer class="ws-footer">© {$businessName} - صُمم بواسطة Tourfecto</footer>
</body>
</html>
HTML;
    }

    private function renderHotelHome(string $slug, array $c, string $seoTitle = '', string $seoDescription = '', string $canonicalUrl = '', string $ogImage = ''): string
    {
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        [$lang, $dir] = $this->siteLangAttrs($c);

        $roomsHtml = '';
        foreach (($c['rooms'] ?? []) as $r) {
            $priceLine = !empty($r['price']) ? '<div class="ws-package-price">' . $esc($r['price']) . '</div>' : '';
            $capacityLine = !empty($r['capacity']) ? '<div class="ws-package-duration">' . $esc($r['capacity']) . '</div>' : '';
            $imageHtml = !empty($r['image_url'])
                ? '<div class="ws-package-img" style="background-image:url(\'' . $esc($r['image_url']) . '\');"></div>'
                : '<div class="ws-package-img ws-package-img-fallback">🛏️</div>';
            $roomsHtml .= '<a href="/sites/' . $esc($slug) . '/rooms/' . $esc($r['slug'] ?? '') . '" class="ws-package ws-package-link">'
                . $imageHtml
                . '<div class="ws-package-body"><h3>' . $esc($r['name'] ?? '') . '</h3><p>' . $esc($r['short_description'] ?? '') . '</p>'
                . $priceLine . $capacityLine . '<span class="ws-view-more">شوف التفاصيل ←</span></div></a>';
        }

        $amenitiesHtml = '';
        foreach (($c['hotel_amenities'] ?? []) as $a) {
            $amenitiesHtml .= '<div class="ws-trust-item"><div class="ws-trust-icon">✔</div><p>' . $esc($a) . '</p></div>';
        }

        $contact = $c['contact'] ?? [];
        $whatsappLink = !empty($contact['whatsapp']) ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $contact['whatsapp']) : '#';

        $businessName = $esc($c['business_name'] ?? '');
        $tagline = $esc($c['tagline'] ?? '');
        $heroHeadline = $esc($c['hero_headline'] ?? '');
        $heroSubtext = $esc($c['hero_subtext'] ?? '');
        $aboutTitle = $esc($c['about_title'] ?? '');
        $aboutText = $esc($c['about_text'] ?? '');
        $ctaText = $esc($c['cta_text'] ?? '');
        $phone = $esc($contact['phone'] ?? '');
        $email = $esc($contact['email'] ?? '');
        $address = $esc($contact['address'] ?? '');
        $head = $this->siteHeadHtml(
            $seoTitle !== '' ? $seoTitle : "{$businessName} | {$tagline}",
            'gold',
            $seoDescription !== '' ? $seoDescription : ($c['about_text'] ?? null),
            $canonicalUrl !== '' ? $canonicalUrl : null,
            $ogImage !== '' ? $ogImage : null
        );

        header('Content-Type: text/html; charset=utf-8');
        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
{$head}
</head>
<body>
    <nav class="ws-nav">
        <div class="ws-nav-inner">
            <span class="ws-logo">🏨 {$businessName}</span>
            <div class="ws-nav-links">
                <a href="#about">عننا</a>
                <a href="#rooms">غرفنا</a>
                <a href="#contact">تواصل معنا</a>
                <a href="{$whatsappLink}" target="_blank" class="ws-nav-cta">احجز الآن</a>
            </div>
        </div>
    </nav>

    <header class="ws-hero">
        <h1>{$heroHeadline}</h1>
        <p>{$heroSubtext}</p>
        <a href="{$whatsappLink}" target="_blank" class="ws-btn">{$ctaText}</a>
    </header>

    <section class="ws-section" id="about">
        <h2>{$aboutTitle}</h2>
        <p class="ws-about-text">{$aboutText}</p>
    </section>

    <section class="ws-section ws-section-alt" id="rooms">
        <h2>غرفنا وأجنحتنا</h2>
        <div class="ws-grid">{$roomsHtml}</div>
    </section>

    <section class="ws-section">
        <h2>مرافق الفندق</h2>
        <div class="ws-trust-grid">{$amenitiesHtml}</div>
    </section>

    <section class="ws-section ws-section-alt ws-contact" id="contact">
        <h2>تواصل معنا</h2>
        <p>📞 {$phone}</p>
        <p>✉️ {$email}</p>
        <p>📍 {$address}</p>
        <a href="{$whatsappLink}" target="_blank" class="ws-btn">تواصل عبر واتساب</a>
    </section>

    <footer class="ws-footer">© {$businessName} - صُمم بواسطة Tourfecto</footer>
</body>
</html>
HTML;
    }

    /** GET /sites/{slug}/tours/{tourSlug} - صفحة تفصيل رحلة واحدة */
    public function showTourDetail(array $params = []): array
    {
        $slug = (string) ($params['slug'] ?? '');
        $tourSlug = (string) ($params['tourSlug'] ?? '');
        $website = $this->findViewableWebsite($slug);

        if (!$website) {
            header('HTTP/1.1 404 Not Found');
            echo '<h1 style="text-align:center;font-family:sans-serif;margin-top:100px;">الموقع ده مش موجود أو لسه مش منشور</h1>';
            exit;
        }

        $c = $website->getContent();
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        [$lang, $dir] = $this->siteLangAttrs($c);

        $tour = null;
        foreach (($c['tours'] ?? []) as $t) {
            if (($t['slug'] ?? '') === $tourSlug) {
                $tour = $t;
                break;
            }
        }

        if (!$tour) {
            header('HTTP/1.1 404 Not Found');
            echo '<h1 style="text-align:center;font-family:sans-serif;margin-top:100px;">الرحلة دي مش موجودة</h1>';
            exit;
        }

        $contact = $c['contact'] ?? [];
        $whatsappNumber = preg_replace('/[^0-9]/', '', $contact['whatsapp'] ?? '');
        $bookingMessage = rawurlencode('أهلاً، عايز أحجز في "' . ($tour['name'] ?? '') . '" - ابعتلي التفاصيل من فضلك.');
        $whatsappLink = $whatsappNumber ? "https://wa.me/{$whatsappNumber}?text={$bookingMessage}" : '#';

        // نموذج الحجز المباشر بيظهر بس لو العنصر مرتبط بمنتج حقيقي (crm_products)
        $bookingFormHtml = '';
        $productLinked = $this->db->query(
            'SELECT id FROM crm_products WHERE website_id = ? AND tour_slug = ? AND is_active = 1 LIMIT 1',
            [(int) $website->getAttribute('id'), $tourSlug]
        );
        if (!empty($productLinked)) {
            $bookingFormHtml = $this->siteBookingFormHtml($slug, $tourSlug, 'tour');
        }

        $highlightsHtml = '';
        foreach (($tour['highlights'] ?? []) as $h) {
            $highlightsHtml .= '<li>✔ ' . $esc($h) . '</li>';
        }

        $itineraryHtml = '';
        foreach (($tour['itinerary'] ?? []) as $day) {
            $itineraryHtml .= '<div class="ws-day"><div class="ws-day-num">' . $esc($day['day'] ?? '') . '</div><div><h4>' . $esc($day['title'] ?? '') . '</h4><p>' . $esc($day['description'] ?? '') . '</p></div></div>';
        }

        $includesHtml = '';
        foreach (($tour['includes'] ?? []) as $inc) {
            $includesHtml .= '<li>✔ ' . $esc($inc) . '</li>';
        }
        $excludesHtml = '';
        foreach (($tour['excludes'] ?? []) as $exc) {
            $excludesHtml .= '<li>✖ ' . $esc($exc) . '</li>';
        }

        $businessName = $esc($c['business_name'] ?? '');
        $tourName = $esc($tour['name'] ?? '');
        $tourDesc = $esc($tour['short_description'] ?? '');
        $duration = $esc($tour['duration'] ?? '');
        $price = $esc($tour['price'] ?? '');
        $groupSize = $esc($tour['group_size'] ?? '');
        $head = $this->siteHeadHtml("{$tourName} | {$businessName}", 'gold');
        $heroImageHtml = !empty($tour['image_url'])
            ? '<div class="ws-tour-hero-img" style="background-image:url(\'' . $esc($tour['image_url']) . '\');"></div>'
            : '';

        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
{$head}
</head>
<body>
    <nav class="ws-nav">
        <div class="ws-nav-inner">
            <a href="/sites/{$slug}" class="ws-logo" style="text-decoration:none;">🌍 {$businessName}</a>
            <div class="ws-nav-links">
                <a href="/sites/{$slug}">الرئيسية</a>
                <a href="/sites/{$slug}#tours">كل الرحلات</a>
                <a href="{$whatsappLink}" target="_blank" class="ws-nav-cta">اطلب حجز</a>
            </div>
        </div>
    </nav>

    {$heroImageHtml}
    <header class="ws-hero ws-hero-sm">
        <h1>{$tourName}</h1>
        <p>{$tourDesc}</p>
    </header>

    <section class="ws-section">
        <div class="ws-tour-meta">
            <div><span>⏱ المدة</span><strong>{$duration}</strong></div>
            <div><span>💰 السعر</span><strong>{$price}</strong></div>
            <div><span>👥 حجم المجموعة</span><strong>{$groupSize}</strong></div>
        </div>

        <h2>أبرز المميزات</h2>
        <ul class="ws-list">{$highlightsHtml}</ul>

        <h2>برنامج الرحلة</h2>
        <div class="ws-itinerary">{$itineraryHtml}</div>

        <div class="ws-inex-grid">
            <div><h3>✔ شامل</h3><ul class="ws-list">{$includesHtml}</ul></div>
            <div><h3>✖ غير شامل</h3><ul class="ws-list">{$excludesHtml}</ul></div>
        </div>

        <div class="ws-booking-box">
            <h3>عايز تحجز؟</h3>
            <p>اختر التاريخ واكتب بياناتك وهنأكد الحجز مباشرة</p>
            {$bookingFormHtml}
            <div class="ws-booking-wa-divider">أو تواصل مباشرة معنا</div>
            <a href="{$whatsappLink}" target="_blank" class="ws-btn ws-btn-wa">📲 احجز عبر واتساب</a>
        </div>
    </section>

    <footer class="ws-footer">© {$businessName} - صُمم بواسطة Tourfecto</footer>
</body>
</html>
HTML;
        exit;
    }

    /** GET /sites/{slug}/rooms/{roomSlug} - صفحة تفصيل غرفة فندقية واحدة */
    public function showRoomDetail(array $params = []): array
    {
        $slug = (string) ($params['slug'] ?? '');
        $roomSlug = (string) ($params['roomSlug'] ?? '');
        $website = $this->findViewableWebsite($slug);

        if (!$website) {
            header('HTTP/1.1 404 Not Found');
            echo '<h1 style="text-align:center;font-family:sans-serif;margin-top:100px;">الموقع ده مش موجود أو لسه مش منشور</h1>';
            exit;
        }

        $c = $website->getContent();
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        [$lang, $dir] = $this->siteLangAttrs($c);

        $room = null;
        foreach (($c['rooms'] ?? []) as $r) {
            if (($r['slug'] ?? '') === $roomSlug) {
                $room = $r;
                break;
            }
        }

        if (!$room) {
            header('HTTP/1.1 404 Not Found');
            echo '<h1 style="text-align:center;font-family:sans-serif;margin-top:100px;">الغرفة دي مش موجودة</h1>';
            exit;
        }

        $contact = $c['contact'] ?? [];
        $whatsappNumber = preg_replace('/[^0-9]/', '', $contact['whatsapp'] ?? '');
        $bookingMessage = rawurlencode('أهلاً، عايز أحجز في "' . ($room['name'] ?? '') . '" - ابعتلي التفاصيل من فضلك.');
        $whatsappLink = $whatsappNumber ? "https://wa.me/{$whatsappNumber}?text={$bookingMessage}" : '#';

        // نموذج الحجز المباشر بيظهر بس لو الغرفة مرتبطة بمنتج حقيقي (crm_products)
        $bookingFormHtml = '';
        $productLinked = $this->db->query(
            'SELECT id FROM crm_products WHERE website_id = ? AND tour_slug = ? AND is_active = 1 LIMIT 1',
            [(int) $website->getAttribute('id'), $roomSlug]
        );
        if (!empty($productLinked)) {
            $bookingFormHtml = $this->siteBookingFormHtml($slug, $roomSlug, 'room');
        }

        $highlightsHtml = '';
        foreach (($room['highlights'] ?? []) as $h) {
            $highlightsHtml .= '<li>✔ ' . $esc($h) . '</li>';
        }
        $amenitiesHtml = '';
        foreach (($room['amenities'] ?? []) as $a) {
            $amenitiesHtml .= '<li>✔ ' . $esc($a) . '</li>';
        }

        $businessName = $esc($c['business_name'] ?? '');
        $roomName = $esc($room['name'] ?? '');
        $roomDesc = $esc($room['short_description'] ?? '');
        $price = $esc($room['price'] ?? '');
        $capacity = $esc($room['capacity'] ?? '');
        $size = $esc($room['size'] ?? '');
        $head = $this->siteHeadHtml("{$roomName} | {$businessName}", 'gold');
        $heroImageHtml = !empty($room['image_url'])
            ? '<div class="ws-tour-hero-img" style="background-image:url(\'' . $esc($room['image_url']) . '\');"></div>'
            : '';

        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
{$head}
</head>
<body>
    <nav class="ws-nav">
        <div class="ws-nav-inner">
            <a href="/sites/{$slug}" class="ws-logo" style="text-decoration:none;">🏨 {$businessName}</a>
            <div class="ws-nav-links">
                <a href="/sites/{$slug}">الرئيسية</a>
                <a href="/sites/{$slug}#rooms">كل الغرف</a>
                <a href="{$whatsappLink}" target="_blank" class="ws-nav-cta">اطلب حجز</a>
            </div>
        </div>
    </nav>

    {$heroImageHtml}
    <header class="ws-hero ws-hero-sm">
        <h1>{$roomName}</h1>
        <p>{$roomDesc}</p>
    </header>

    <section class="ws-section">
        <div class="ws-tour-meta">
            <div><span>💰 السعر لليلة</span><strong>{$price}</strong></div>
            <div><span>👥 السعة</span><strong>{$capacity}</strong></div>
            <div><span>📐 المساحة</span><strong>{$size}</strong></div>
        </div>

        <h2>أبرز المميزات</h2>
        <ul class="ws-list">{$highlightsHtml}</ul>

        <h2>مرافق الغرفة</h2>
        <ul class="ws-list">{$amenitiesHtml}</ul>

        <div class="ws-booking-box">
            <h3>عايز تحجز؟</h3>
            <p>اختر التاريخ واكتب بياناتك وهنأكد الحجز مباشرة</p>
            {$bookingFormHtml}
            <div class="ws-booking-wa-divider">أو تواصل مباشرة معنا</div>
            <a href="{$whatsappLink}" target="_blank" class="ws-btn ws-btn-wa">📲 احجز عبر واتساب</a>
        </div>
    </section>

    <footer class="ws-footer">© {$businessName} - صُمم بواسطة Tourfecto</footer>
</body>
</html>
HTML;
        exit;
    }

    /**
     * مزامنة عنصر واحد (رحلة/غرفة) من الموقع المولّد إلى crm_products.
     * Upsert آمن: البحث الأولي بـ (website_id + tour_slug) - لو موجود
     * نحدّث بياناته، وإلا ننشئ صفًا جديدًا (من غير تكرار في أي حالة).
     * أي خطأ هنا مش بيكسّر الطلب الأصلي (متستناش - الـ sync ثانوي).
     */
    private function syncTourToProduct(GeneratedWebsite $website, array $item): void
    {
        try {
            $slug = (string) ($item['slug'] ?? '');
            if ($slug === '') {
                return;
            }
            $userId = (int) $website->getAttribute('user_id');
            $websiteId = (int) $website->getAttribute('id');
            if (!$userId || !$websiteId) {
                return;
            }

            [$price, $currency] = $this->parseItemPrice((string) ($item['price'] ?? ''));
            $name = (string) ($item['name'] ?? $slug);
            $description = (string) ($item['short_description'] ?? '');
            $sku = 'WS' . $websiteId . '-' . $slug;
            $db = $this->db;

            $existing = $db->query(
                'SELECT id FROM crm_products WHERE website_id = ? AND tour_slug = ? LIMIT 1',
                [$websiteId, $slug]
            );

            if (!empty($existing)) {
                $db->query(
                    'UPDATE crm_products
                     SET name = ?, description = ?, sku = ?, price = ?, currency = ?, is_active = 1
                     WHERE id = ?',
                    [$name, $description, $sku, $price, $currency, $existing[0]['id']]
                );
            } else {
                $db->query(
                    'INSERT INTO crm_products
                        (user_id, website_id, tour_slug, name, description, sku, price, currency, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)',
                    [$userId, $websiteId, $slug, $name, $description, $sku, $price, $currency]
                );
            }
        } catch (Exception $e) {
            Logger::warning('syncTourToProduct failed', ['website_id' => (int) $website->getAttribute('id'), 'error' => $e->getMessage()]);
        }
    }

    /** مزامنة كل عناصر الموقع (رحلات أو غرف حسب المجال) - تُستخدم عند النشر */
    private function syncAllSiteItems(GeneratedWebsite $website): void
    {
        $c = $website->getContent();
        $itemsKey = ($c['industry'] ?? 'tours') === 'hotel' ? 'rooms' : 'tours';
        foreach (($c[$itemsKey] ?? []) as $item) {
            $this->syncTourToProduct($website, $item);
        }
    }

    /** تعطيل المنتج المرتبط عند حذف العنصر من الموقع (مش حذف - حماية سجل الحجوزات) */
    private function deactivateLinkedProduct(GeneratedWebsite $website, string $itemSlug): void
    {
        try {
            if ($itemSlug === '') {
                return;
            }
            $this->db->query(
                'UPDATE crm_products SET is_active = 0 WHERE website_id = ? AND tour_slug = ?',
                [(int) $website->getAttribute('id'), $itemSlug]
            );
        } catch (Exception $e) {
            Logger::warning('deactivateLinkedProduct failed', ['website_id' => (int) $website->getAttribute('id'), 'error' => $e->getMessage()]);
        }
    }

    /** استخراج رقم السعر + العملة من نص حر زي "350$" / "€120" / "1500 جنيه" */
    private function parseItemPrice(string $priceText): array
    {
        $priceText = trim((string) $priceText);
        $currency = 'USD';
        if (preg_match('/جنيه|ج\.م|EGP/i', $priceText)) {
            $currency = 'EGP';
        } elseif (strpos($priceText, '€') !== false || stripos($priceText, 'euro') !== false) {
            $currency = 'EUR';
        } elseif (strpos($priceText, '£') !== false || stripos($priceText, 'gbp') !== false) {
            $currency = 'GBP';
        }
        if (preg_match('/(\d+[.,]?\d*)/', $priceText, $m)) {
            return [(float) str_replace(',', '', $m[1]), $currency];
        }
        return [0.0, $currency];
    }

    /** هل الطلب عايز JSON (fetch) ولا صفحة/redirect عادية؟ */
    private function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return strpos($accept, 'application/json') !== false;
    }

    private const WEBSITE_BOOKING_DEFAULT_CAPACITY = 50;

    /**
     * POST /sites/{slug}/tours/{tourSlug}/book - حجز مباشر من صفحة الرحلة
     * (زائر بدون تسجيل دخول). بيبني حجز عبر BookingEngine::createBooking
     * بـ source='website' وproduct_id المرتبط بـ (website_id+tour_slug).
     */
    public function bookSiteTour(array $params = []): array
    {
        return $this->bookSiteItem($params, 'tour');
    }

    /** POST /sites/{slug}/rooms/{roomSlug}/book - نفس المنطق لغرف الفندق */
    public function bookSiteRoom(array $params = []): array
    {
        return $this->bookSiteItem($params, 'room');
    }

    /**
     * منطق الحجز المشترك للرحلات والغرف.
     *
     * Fallback آمن: أي فشل (مفيش منتج مرتبط، Stripe مش مفعّل، خطأ حجز)
     * مش بيكسّر الصفحة - بيرجع برسالة واضحة + خيار واتساب.
     */
    private function bookSiteItem(array $params, string $itemType): array
    {
        $slug = (string) ($params['slug'] ?? '');
        $itemSlug = (string) ($params[$itemType === 'room' ? 'roomSlug' : 'tourSlug'] ?? '');
        $website = $this->findViewableWebsite($slug);
        if (!$website) {
            return $this->error('الموقع مش موجود أو لسه مش منشور', 404);
        }

        $c = $website->getContent();
        $itemsKey = $itemType === 'room' ? 'rooms' : 'tours';
        $item = null;
        foreach (($c[$itemsKey] ?? []) as $it) {
            if (($it['slug'] ?? '') === $itemSlug) {
                $item = $it;
                break;
            }
        }
        if (!$item) {
            return $this->error('العنصر مش موجود', 404);
        }

        if (!$this->validate(['start_date' => 'required', 'customer_name' => 'required'])) {
            return $this->error('اكتب تاريخ الحجز واسمك من فضلك', 422);
        }
        $startDate = (string) $this->get('start_date');
        if (strtotime($startDate) === false || strtotime($startDate) < strtotime(date('Y-m-d'))) {
            return $this->error('تاريخ الحجز لازم يكون تاريخ صحيح في المستقبل', 422);
        }
        $adults = max(1, (int) $this->get('adults_count', 1));
        $children = max(0, (int) $this->get('children_count', 0));

        $websiteId = (int) $website->getAttribute('id');
        $userId = (int) $website->getAttribute('user_id');

        // 1) المنتج المرتبط بالعنصر داخل الموقع ده
        $productRow = $this->db->query(
            'SELECT id FROM crm_products WHERE website_id = ? AND tour_slug = ? AND is_active = 1 LIMIT 1',
            [$websiteId, $itemSlug]
        );
        if (empty($productRow)) {
            Logger::warning('Website booking attempt without linked product', ['website_id' => $websiteId, 'slug' => $itemSlug]);
            return $this->error('هذا العنصر غير متاح للحجز المباشر حاليًا - تواصل عبر واتساب', 422, ['whatsapp_fallback' => true]);
        }
        $productId = (int) $productRow[0]['id'];

        // 2) لو مفيش توفر مسجّل لليوم ده: نفتح سعة افتراضية (غير مُدارة)
        //    بدل رفض الحجز - صاحب الموقع لسه مدخلش توفر فعلي.
        try {
            $inventory = new InventoryService();
            $avail = $inventory->checkAvailability($productId, $startDate);
            if (!$avail['available'] && ($avail['reason'] ?? '') === 'no_inventory_set') {
                $inventory->setDay($userId, $productId, $startDate, self::WEBSITE_BOOKING_DEFAULT_CAPACITY);
            }
        } catch (Exception $e) {
            Logger::warning('Website booking inventory init failed', ['product_id' => $productId, 'error' => $e->getMessage()]);
        }

        // 3) إنشاء الحجز عبر Booking Engine (source='website'، أو source
        //    إعلاني + إسناد UTM لو الزائر جه من كليك إعلان خلال نافذة 30 يوم)
        $bookingSource = 'website';
        $attribution = null;
        try {
            if (class_exists('AdTrackingService')) {
                $attribution = (new AdTrackingService())->readAttribution();
            }
        } catch (Exception $e) {
            Logger::warning('Booking attribution read failed', ['website_id' => $websiteId, 'error' => $e->getMessage()]);
        }

        if ($attribution !== null) {
            $bookingSource = match ($attribution['platform']) {
                'meta_ads' => 'ad:meta',
                'google_ads' => 'ad:google',
                default => 'ad',
            };
        }

        try {
            $bookingData = [
                'product_id' => $productId,
                'start_date' => $startDate,
                'customer_name' => (string) $this->get('customer_name'),
                'customer_phone' => (string) $this->get('customer_phone', '') ?: null,
                'customer_email' => (string) $this->get('customer_email', '') ?: null,
                'adults_count' => $adults,
                'children_count' => $children,
                'source' => $bookingSource,
                'notes' => 'حجز مباشر من ' . ($itemType === 'room' ? 'صفحة الغرفة' : 'صفحة الرحلة') . ': /sites/' . $slug . '/' . $itemsKey . '/' . $itemSlug,
            ];
            if ($attribution !== null) {
                $bookingData['attributed_utm_link_id'] = $attribution['utm_link_id'];
            }

            $booking = (new BookingEngine())->createBooking($userId, $bookingData);

            // الإسناد اتحول لحجز - بنمسح الكوكي عشان الحجز الجاي مايتنسبش لنفس
            // الرابط القديم (نافذة الإسناد بتتبدأ من جديد من أول كليك إعلان جديد).
            if ($attribution !== null && class_exists('AdTrackingService')) {
                (new AdTrackingService())->clearAttribution();
            }
        } catch (Exception $e) {
            Logger::warning('Website booking failed', ['website_id' => $websiteId, 'product_id' => $productId, 'error' => $e->getMessage()]);
            return $this->error('ما قدرناش نأكد الحجز للتاريخ ده حاليًا - جرب تاريخ تاني أو تواصل عبر واتساب', 422, ['whatsapp_fallback' => true]);
        }

        $bookingId = (int) $booking['id'];
        $reference = (string) $booking['booking_reference'];
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        $successUrl = $base . '/sites/' . rawurlencode($slug) . '/booking/' . rawurlencode($reference);
        $cancelUrl = $base . '/sites/' . rawurlencode($slug) . '/' . ($itemType === 'room' ? 'rooms' : 'tours') . '/' . rawurlencode($itemSlug);

        // 4) دفع Stripe لو مفعّل، وإلا تأكيد بلا دفع إلكتروني (واتساب للتواصل)
        try {
            if ((new StripeCheckoutService())->isConfigured()) {
                $session = (new StripeCheckoutService())->createCheckoutSession($userId, $bookingId, $successUrl, $cancelUrl);
                if ($this->wantsJson()) {
                    return $this->success(['booking_reference' => $reference, 'checkout_url' => $session['checkout_url']], 'جاري تحويلك لصفحة الدفع الآمنة...');
                }
                header('Location: ' . $session['checkout_url']);
                exit;
            }
        } catch (Exception $e) {
            Logger::warning('Stripe checkout failed for website booking', ['booking_id' => $bookingId, 'error' => $e->getMessage()]);
        }

        if ($this->wantsJson()) {
            return $this->success(
                ['booking_reference' => $reference, 'checkout_url' => null, 'whatsapp_fallback' => true],
                'تم استلام طلب حجزك - كود الحجز: ' . $reference,
                201
            );
        }
        header('Location: ' . $successUrl);
        exit;
    }

    /** GET /sites/{slug}/booking/{reference} - صفحة تأكيد الحجز للزائر */
    public function showBookingConfirmation(array $params = []): array
    {
        $slug = (string) ($params['slug'] ?? '');
        $reference = (string) ($params['reference'] ?? '');
        $website = $this->findViewableWebsite($slug);
        if (!$website) {
            header('HTTP/1.1 404 Not Found');
            echo '<h1 style="text-align:center;font-family:sans-serif;margin-top:100px;">الموقع ده مش موجود أو لسه مش منشور</h1>';
            exit;
        }

        $booking = (new Booking())->findByReference($reference);
        if (!$booking
            || (int) $booking->getAttribute('user_id') !== (int) $website->getAttribute('user_id')) {
            header('HTTP/1.1 404 Not Found');
            echo '<h1 style="text-align:center;font-family:sans-serif;margin-top:100px;">الحجز ده مش موجود</h1>';
            exit;
        }

        $c = $website->getContent();
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        [$lang, $dir] = $this->siteLangAttrs($c);

        $contact = $c['contact'] ?? [];
        $whatsappNumber = preg_replace('/[^0-9]/', '', $contact['whatsapp'] ?? '');
        $bookingMessage = rawurlencode('أهلاً، بخصوص حجزي رقم "' . $reference . '" - محتاج مساعدة من فضلك.');
        $whatsappLink = $whatsappNumber ? "https://wa.me/{$whatsappNumber}?text={$bookingMessage}" : '#';

        $statusLabel = [
            'pending' => 'قيد الانتظار - هنأكد الحجز معاك خلال وقت قصير',
            'confirmed' => 'مؤكد - الحجز اتعمل بنجاح',
            'cancelled' => 'ملغي',
            'completed' => 'مكتمل',
            'no_show' => 'لم يتم الحضور',
        ][(string) $booking->getAttribute('status')] ?? 'قيد المراجعة';

        $statusClass = in_array((string) $booking->getAttribute('status'), ['confirmed', 'completed'], true)
            ? 'ws-ok' : 'ws-pending';

        $businessName = $esc($c['business_name'] ?? '');
        $head = $this->siteHeadHtml("تأكيد الحجز | {$businessName}", 'gold');

        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
{$head}
</head>
<body>
    <nav class="ws-nav">
        <div class="ws-nav-inner">
            <a href="/sites/{$slug}" class="ws-logo" style="text-decoration:none;">🌍 {$businessName}</a>
            <div class="ws-nav-links">
                <a href="/sites/{$slug}">الرئيسية</a>
                <a href="{$whatsappLink}" target="_blank" class="ws-nav-cta">تواصل معنا</a>
            </div>
        </div>
    </nav>

    <header class="ws-hero ws-hero-sm">
        <h1>استلمنا طلب حجزك</h1>
        <p>احتفظ بكود الحجز ده للمراجعة أو الاستفسار</p>
    </header>

    <section class="ws-section" style="max-width:560px;">
        <div class="ws-booking-box">
            <div class="ws-booking-confirm {$statusClass}">
                <strong>كود الحجز: {$esc($reference)}</strong>
                <p>{$esc($statusLabel)}</p>
            </div>
            <p style="margin:14px 0;">لو عندك أي استفسار عن الحجز، ابعتلنا رسالة على واتساب مباشرة.</p>
            <a href="{$whatsappLink}" target="_blank" class="ws-btn">📲 تواصل عبر واتساب</a>
            <p style="margin-top:12px;"><a href="/sites/{$slug}" style="color:var(--ws-accent);">الرجوع للموقع ←</a></p>
        </div>
    </section>

    <footer class="ws-footer">© {$businessName} - صُمم بواسطة Tourfecto</footer>
</body>
</html>
HTML;
        exit;
    }

    /**
     * نموذج الحجز المباشر المعروض في صفحة تفصيل الرحلة/الغرفة.
     * بيبعت بـ fetch لـ POST /sites/{slug}/{tours|rooms}/{itemSlug}/book
     * ويحوّل الزائر لصفحة Stripe أو يعرض كود الحجز.
     */
    private function siteBookingFormHtml(string $slug, string $itemSlug, string $itemType): string
    {
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $action = '/sites/' . rawurlencode($slug) . '/' . ($itemType === 'room' ? 'rooms' : 'tours') . '/' . rawurlencode($itemSlug) . '/book';
        $today = date('Y-m-d');

        return <<<HTML
<form id="wsBookingForm" class="ws-booking-form" data-action="{$esc($action)}" data-slug="{$esc($slug)}">
    <label class="ws-bf-label">📅 تاريخ الحجز
        <input type="date" name="start_date" min="{$today}" required class="ws-bf-input">
    </label>
    <div class="ws-booking-cols">
        <label class="ws-bf-label">👥 بالغين
            <input type="number" name="adults_count" min="1" value="1" class="ws-bf-input">
        </label>
        <label class="ws-bf-label">🧒 أطفال
            <input type="number" name="children_count" min="0" value="0" class="ws-bf-input">
        </label>
    </div>
    <label class="ws-bf-label">👤 الاسم
        <input type="text" name="customer_name" required placeholder="اسمك الكامل" class="ws-bf-input">
    </label>
    <label class="ws-bf-label">📱 الهاتف
        <input type="tel" name="customer_phone" placeholder="رقم واتساب/هاتف" class="ws-bf-input">
    </label>
    <label class="ws-bf-label">✉️ الإيميل
        <input type="email" name="customer_email" placeholder="بريدك الإلكتروني" class="ws-bf-input">
    </label>
    <button type="submit" class="ws-btn" style="width:100%;">تأكيد الحجز</button>
    <div id="wsBookingMsg" class="ws-booking-msg" role="status"></div>
</form>
<script>
(function () {
    var form = document.getElementById('wsBookingForm');
    if (!form) return;
    var msg = document.getElementById('wsBookingMsg');
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        msg.className = 'ws-booking-msg';
        msg.textContent = 'جاري تأكيد الحجز...';
        var payload = {};
        new FormData(form).forEach(function (value, key) { payload[key] = value; });
        fetch(form.getAttribute('data-action'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (res) { return res.json(); }).then(function (res) {
            if (res.success && res.data && res.data.checkout_url) {
                window.location.href = res.data.checkout_url;
                return;
            }
            if (res.success) {
                var text = (res.message || 'تم استلام طلبك');
                if (res.data && res.data.booking_reference) {
                    text += ' - كود الحجز: ' + res.data.booking_reference;
                }
                msg.className = 'ws-booking-msg ws-ok';
                msg.textContent = text;
            } else {
                msg.className = 'ws-booking-msg ws-pending';
                msg.textContent = res.error || 'حصل خطأ - جرب تاني أو تواصل عبر واتساب';
            }
        }).catch(function () {
            msg.className = 'ws-booking-msg ws-pending';
            msg.textContent = 'حصل خطأ في الاتصال - جرب تاني أو تواصل عبر واتساب';
        });
    });
})();
</script>
HTML;
    }

    private function ownedWebsite(int $id): ?GeneratedWebsite
    {
        if (!$id) {
            return null;
        }
        $website = (new GeneratedWebsite())->find($id);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return null;
        }
        return $website;
    }
}
