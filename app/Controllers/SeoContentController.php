<?php

/**
 * Tourfecto - SEO Content Engine Controller (Phase 24)
 * @version 1.0.0
 *
 * محرك محتوى SEO تلقائي بحلقة مغلقة: اكتشاف فرص -> توليد مقالات ->
 * فهرسة فورية -> تجربة A/B على العنوان -> قياس CTR من GSC.
 *
 * بيكمّل على AutoSeoController (التدقيق/الإصلاح/الفهرسة) وArticleGenerator
 * (توليد المقالات): المحرك ده بيحوّل فرص الـSEO لمحتوى فعلي منشور ومقيس.
 */
class SeoContentController extends Controller
{
    /** @var SeoContentService */
    private $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new SeoContentService($this->db);
    }

    /**
     * GET /seo-content - صفحة لوحة محرك المحتوى.
     */
    public function index(array $params = []): void
    {
        $body = <<<'HTML'
        <style>
        .scc-grid { display: grid; grid-template-columns: 320px 1fr; gap: 14px; align-items: start; }
        .scc-card { background: var(--panel-card-bg-2); border: 1px solid var(--panel-border); border-radius: var(--panel-radius-sm); padding: 14px 16px; margin-bottom: 12px; }
        .scc-card h3 { margin: 0 0 8px; font-size: 13.5px; }
        .scc-label { display: block; font-size: 11.5px; color: var(--panel-text-muted); font-weight: 700; margin: 10px 0 4px; }
        .scc-input, .scc-select, .scc-textarea { width: 100%; box-sizing: border-box; background: #060A13; border: 1px solid var(--panel-border); border-radius: 8px; color: var(--panel-text); padding: 8px 10px; font-size: 12.5px; font-family: inherit; }
        .scc-textarea { min-height: 90px; resize: vertical; }
        .scc-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .scc-pill { font-size: 10.5px; font-weight: 700; padding: 2px 8px; border-radius: 6px; white-space: nowrap; }
        .scc-pill.green { background: var(--panel-accent-light); color: var(--panel-accent); }
        .scc-pill.gray { background: rgba(255,255,255,.08); color: var(--panel-text-muted); }
        .scc-pill.orange { background: rgba(239,176,94,.18); color: var(--panel-warning); }
        .scc-pill.red { background: rgba(239,94,94,.18); color: #ef5e5e; }
        .scc-item { border: 1px solid var(--panel-border); border-radius: 8px; padding: 10px 12px; margin-bottom: 8px; }
        .scc-item-head { display: flex; justify-content: space-between; gap: 10px; align-items: center; flex-wrap: wrap; }
        .scc-item-title { font-weight: 800; font-size: 12.5px; }
        .scc-item-meta { font-size: 11px; color: var(--panel-text-muted); margin-top: 4px; }
        .scc-metric { display: inline-flex; flex-direction: column; align-items: center; padding: 8px 12px; border: 1px solid var(--panel-border); border-radius: 8px; min-width: 64px; }
        .scc-metric b { font-size: 15px; }
        .scc-metric span { font-size: 10px; color: var(--panel-text-muted); }
        @media (max-width: 820px) { .scc-grid { grid-template-columns: 1fr; } }
        </style>

        <div class="p-toolbar">
            <select id="sccWebsiteSelect" class="p-select"><option value="">اختر موقعًا</option></select>
            <span class="p-cell-muted" id="sccConnStatus" style="font-size:12px;"></span>
        </div>

        <div class="scc-grid" style="margin-top:14px;">
            <div>
                <div class="scc-card">
                    <h3>حملة محتوى جديدة</h3>
                    <label class="scc-label">اسم الحملة</label>
                    <input type="text" id="sccCampaignName" class="scc-input" placeholder="مثال: مقالات رحلات الخريف" />
                    <label class="scc-label">مصدر المواضيع</label>
                    <select id="sccSource" class="scc-select">
                        <option value="keywords">كلمات مفتاحية متابَعة</option>
                        <option value="gsc">استعلامات Search Console</option>
                        <option value="manual">يدوي</option>
                    </select>
                    <label class="scc-label">المواضيع (سطر لكل موضوع)</label>
                    <textarea id="sccTopics" class="scc-textarea" placeholder="رحلة سفاري صحراوية&#10;أفضل فنادق القاهرة"></textarea>
                    <div class="scc-row" style="margin-top:10px;">
                        <button class="p-btn" id="sccDiscoverBtn">اكتشاف مواضيع</button>
                        <button class="p-btn" id="sccCreateBtn" style="background:var(--panel-accent);color:#1a1206;">إنشاء الحملة</button>
                    </div>
                </div>
                <div class="scc-card">
                    <h3>الحملات</h3>
                    <div id="sccCampaignList" class="p-cell-muted" style="font-size:12px;">اختر موقعًا لعرض حملاته</div>
                </div>
            </div>

            <div>
                <div class="scc-card">
                    <div class="scc-row" style="justify-content:space-between;">
                        <h3 style="margin:0;">عناصر الحملة</h3>
                        <div class="scc-row" id="sccTotals"></div>
                    </div>
                    <div id="sccItems" class="p-cell-muted" style="font-size:12px; margin-top:8px;">اختر حملة لعرض عناصرها</div>
                </div>
            </div>
        </div>
        HTML;

        $script = <<<'JS'
        const sccApi = (url, opts) => fetch(url, { headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, ...opts })
            .then(r => r.json()).catch(e => ({ success: false, error: String(e) }));
        let sccWebsite = null, sccCampaign = null;

        async function sccLoadWebsites() {
            const res = await sccApi('/api/website-optimizer/websites');
            if (!res.success) return;
            const sel = document.getElementById('sccWebsiteSelect');
            sel.innerHTML = '<option value="">اختر موقعًا</option>' + res.data.websites.map(w => `<option value="${w.id}">${w.main_url}</option>`).join('');
            sel.onchange = () => { sccWebsite = sel.value; sccLoadCampaigns(); sccLoadDiscover(); };
        }
        async function sccLoadCampaigns() {
            if (!sccWebsite) return;
            const res = await sccApi(`/api/seo-content/campaigns/${sccWebsite}`);
            const box = document.getElementById('sccCampaignList');
            if (!res.success || !res.data.campaigns.length) { box.textContent = 'مفيش حملات بعد'; return; }
            box.innerHTML = res.data.campaigns.map(c => `<div class="scc-item"><div class="scc-item-head"><span class="scc-item-title">${c.name}</span><span class="scc-pill ${c.status==='ready'?'green':(c.status==='draft'?'gray':'orange')}">${c.status}</span></div><div class="scc-item-meta">${c.generated_items}/${c.total_items} مولّد</div><div class="scc-row" style="margin-top:6px;"><button class="p-btn" onclick="sccSelectCampaign(${c.id})">عرض</button><button class="p-btn" onclick="sccGenerateCampaign(${c.id})">توليد</button></div></div>`).join('');
        }
        async function sccSelectCampaign(id) {
            sccCampaign = id;
            const [stats, items] = await Promise.all([
                sccApi(`/api/seo-content/campaigns/${id}/stats`),
                sccApi(`/api/seo-content/campaigns/${id}/items`)
            ]);
            sccRenderStats(stats);
            sccRenderItems(items);
        }
        function sccRenderStats(stats) {
            const box = document.getElementById('sccTotals');
            if (!stats.success) { box.innerHTML = ''; return; }
            const t = stats.data.totals;
            box.innerHTML = `<div class="scc-metric"><b>${t.clicks}</b><span>نقرات</span></div><div class="scc-metric"><b>${t.impressions}</b><span>ظهور</span></div><div class="scc-metric"><b>${t.ctr}%</b><span>CTR</span></div>`;
        }
        function sccRenderItems(items) {
            const box = document.getElementById('sccItems');
            if (!items.success || !items.data.items.length) { box.textContent = 'مفيش عناصر'; return; }
            box.innerHTML = items.data.items.map(it => `<div class="scc-item"><div class="scc-item-head"><span class="scc-item-title">${it.topic || it.title || ''}</span><span class="scc-pill ${it.status==='generated'||it.status==='indexed'?'green':(it.status==='testing'?'orange':(it.status==='failed'?'red':'gray'))}">${it.status}</span></div><div class="scc-item-meta">${it.title ? ('العنوان: '+it.title) : ''} ${it.indexnow_code ? ('| IndexNow '+it.indexnow_code) : ''}</div><div class="scc-row" style="margin-top:6px;">${it.status==='queued'||it.status==='failed'?`<button class="p-btn" onclick="sccGenerateItem(${it.id})">توليد</button>`:''}${it.status==='generated'?`<button class="p-btn" onclick="sccIndexItem(${it.id})">فهرسة</button>`:''}${it.status==='indexed'?`<button class="p-btn" onclick="sccAbTest(${it.id})">A/B عنوان</button>`:''}</div></div>`).join('');
        }
        async function sccGenerateItem(id) { await sccApi(`/api/seo-content/items/${id}/generate`, { method: 'POST' }); sccSelectCampaign(sccCampaign); }
        async function sccIndexItem(id) { await sccApi(`/api/seo-content/items/${id}/index`, { method: 'POST' }); sccSelectCampaign(sccCampaign); }
        async function sccAbTest(id) { await sccApi(`/api/seo-content/items/${id}/ab-test`, { method: 'POST' }); sccSelectCampaign(sccCampaign); }
        async function sccGenerateCampaign(id) { await sccApi(`/api/seo-content/campaigns/${id}/generate`, { method: 'POST' }); sccSelectCampaign(id); }
        async function sccLoadDiscover() {
            if (!sccWebsite) return;
            const res = await sccApi(`/api/seo-content/discover/${sccWebsite}?source=keywords`);
            if (res.success && res.data.topics.length) {
                document.getElementById('sccTopics').value = res.data.topics.map(t => t.topic).join('\n');
            }
        }
        async function sccCreateCampaign() {
            if (!sccWebsite) { alert('اختر موقعًا أولًا'); return; }
            const name = document.getElementById('sccCampaignName').value.trim();
            const source = document.getElementById('sccSource').value;
            let topics = document.getElementById('sccTopics').value.split('\n').map(s => s.trim()).filter(Boolean);
            if (!name || !topics.length) { alert('اكتب اسم الحملة وموضوعًا واحدًا على الأقل'); return; }
            const res = await sccApi('/api/seo-content/campaigns', { method: 'POST', body: JSON.stringify({ website_id: Number(sccWebsite), name, source, topics }) });
            if (!res.success) { alert(res.error || 'فشل الإنشاء'); return; }
            document.getElementById('sccCampaignName').value = ''; document.getElementById('sccTopics').value = '';
            sccLoadCampaigns();
        }
        document.getElementById('sccDiscoverBtn').onclick = sccLoadDiscover;
        document.getElementById('sccCreateBtn').onclick = sccCreateCampaign;
        sccLoadWebsites();
        JS;

        echo $this->renderPanelPage('seo_content', 'محرك محتوى SEO', 'تحويل الفرص إلى مقالات مفهرسة ومختبَرة A/B', $body, $script);
    }

    /** GET /api/seo-content/discover/{website_id}?source=keywords|gsc */
    public function discover(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) ($params['website_id'] ?? 0);
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }
        $source = (string) $this->get('source', 'keywords');
        if (!in_array($source, ['keywords', 'gsc'], true)) {
            $source = 'keywords';
        }
        $topics = $this->service->discoverTopics($websiteId, $source);
        return $this->success(['topics' => $topics, 'source' => $source], 'تم الاكتشاف');
    }

    /** POST /api/seo-content/campaigns  { website_id, name, source, topics[] } */
    public function createCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id');
        $name = trim((string) $this->get('name'));
        $source = (string) $this->get('source', 'manual');
        $topics = $this->get('topics', []);
        if (!is_array($topics)) {
            $topics = [];
        }
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }
        if (!in_array($source, ['manual', 'gsc', 'keywords', 'competitors'], true)) {
            $source = 'manual';
        }
        $result = $this->service->createCampaign((int) $this->user['id'], $websiteId, $name, $topics, $source);
        if (empty($result['success'])) {
            return $this->error($result['error'] ?? 'فشل إنشاء الحملة', 422);
        }
        $this->log('SEO Content Campaign Created', ['website_id' => $websiteId, 'campaign_id' => $result['campaign_id']]);
        return $this->success($result, 'تم إنشاء الحملة');
    }

    /** GET /api/seo-content/campaigns/{website_id} */
    public function listCampaigns(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) ($params['website_id'] ?? 0);
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }
        return $this->success(['campaigns' => $this->service->listCampaigns($websiteId)]);
    }

    /** GET /api/seo-content/campaigns/{id}/items */
    public function listItems(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $campaignId = (int) ($params['id'] ?? 0);
        if (!$campaignId || !$this->ownsCampaign($campaignId)) {
            return $this->error('الحملة غير موجودة', 404);
        }
        return $this->success(['items' => $this->service->listItems($campaignId)]);
    }

    /** GET /api/seo-content/campaigns/{id}/stats */
    public function stats(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $campaignId = (int) ($params['id'] ?? 0);
        if (!$campaignId || !$this->ownsCampaign($campaignId)) {
            return $this->error('الحملة غير موجودة', 404);
        }
        $result = $this->service->campaignStats($campaignId);
        if (empty($result['success'])) {
            return $this->error($result['error'] ?? 'فشل', 500);
        }
        return $this->success($result);
    }

    /** POST /api/seo-content/campaigns/{id}/generate  (وضع خلفي اختياري ?background=1) */
    public function generateCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $campaignId = (int) ($params['id'] ?? 0);
        if (!$campaignId || !$this->ownsCampaign($campaignId)) {
            return $this->error('الحملة غير موجودة', 404);
        }

        if ($this->get('background')) {
            $queue = new QueueManager($this->db);
            $jobId = $queue->push(SeoContentGenerateJob::class, ['campaign_id' => $campaignId], 'default');
            return $this->success(['queued' => $jobId !== false, 'job_id' => $jobId], 'تمت الجدولة في الخلفية');
        }

        $result = $this->service->generateCampaign($campaignId);
        return $this->success($result, 'تم التوليد');
    }

    /** POST /api/seo-content/items/{id}/generate */
    public function generateItem(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $itemId = (int) ($params['id'] ?? 0);
        if (!$itemId || !$this->ownsItem($itemId)) {
            return $this->error('العنصر غير موجود', 404);
        }
        $result = $this->service->generateItem($itemId);
        if (empty($result['success'])) {
            return $this->error($result['error'] ?? 'فشل التوليد', 500);
        }
        return $this->success($result, 'تم توليد المقال');
    }

    /** POST /api/seo-content/items/{id}/index */
    public function indexItem(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $itemId = (int) ($params['id'] ?? 0);
        if (!$itemId || !$this->ownsItem($itemId)) {
            return $this->error('العنصر غير موجود', 404);
        }
        $result = $this->service->indexItem($itemId);
        if (empty($result['success'])) {
            return $this->error($result['error'] ?? 'فشل الفهرسة', 502);
        }
        return $this->success($result, 'تمت الفهرسة');
    }

    /** POST /api/seo-content/items/{id}/ab-test  { variant_title? } */
    public function abTest(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $itemId = (int) ($params['id'] ?? 0);
        if (!$itemId || !$this->ownsItem($itemId)) {
            return $this->error('العنصر غير موجود', 404);
        }
        $variantTitle = $this->get('variant_title') ? (string) $this->get('variant_title') : null;
        $result = $this->service->createTitleAbTest($itemId, $variantTitle);
        if (empty($result['success'])) {
            return $this->error($result['error'] ?? 'فشل إنشاء التجربة', 500);
        }
        return $this->success($result, 'تم إنشاء تجربة A/B');
    }

    // ==================== helpers ====================

    private function ownsWebsite(int $websiteId): bool
    {
        $rows = $this->db->query(
            "SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1",
            [$websiteId, $this->user['id']]
        );
        return !empty($rows);
    }

    private function ownsCampaign(int $campaignId): bool
    {
        $rows = $this->db->query(
            "SELECT c.id FROM seo_content_campaigns c WHERE c.id = ? AND c.user_id = ? LIMIT 1",
            [$campaignId, $this->user['id']]
        );
        return !empty($rows);
    }

    private function ownsItem(int $itemId): bool
    {
        $rows = $this->db->query(
            "SELECT i.id FROM seo_content_items i
              JOIN seo_content_campaigns c ON c.id = i.campaign_id
             WHERE i.id = ? AND c.user_id = ? LIMIT 1",
            [$itemId, $this->user['id']]
        );
        return !empty($rows);
    }
}
