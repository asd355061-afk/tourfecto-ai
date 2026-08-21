<?php

/**
 * Tourfecto - Email Marketing Controller
 * @version 1.0.0
 *
 * موديول تسويق البريد الاحترافي — منافس لـ Brevo/Mailchimp مبني بالكامل على
 * بنية Tourfecto (إرسال SMTP + تتبع فتح/كليك/إلغاء على جداول خاصة) من غير
 * أي اعتماد على خدمة خارجية. يضم:
 *   - لوحة تحكم (KPIs + أحدث الحملات + حالة بوابة الإرسال)
 *   - إدارة الجمهور (قوائم + مشتركين + استيراد + إلغاء اشتراك)
 *   - القوالب (متغيرات تخصيص + معاينة)
 *   - الحملات (إنشاء/جدولة/إرسال فوري/إلغاء/تقرير تفاعلي)
 *   - التتبع العام (بكسل فتح + كليك + إلغاء اشتراك) - من غير Auth
 *
 * العزل: كل استعلام مربوط بـ $this->uid() (user_id) - نفس نمط باقي
 * الموديولات (Tenant Isolation).
 */
class EmailMarketingController extends Controller
{
    /** @var EmailListService */
    private $listService;

    /** @var EmailCampaignService */
    private $campaignService;

    /** @var EmailTrackingService */
    private $trackingService;

    public function __construct()
    {
        parent::__construct();
        $this->listService = new EmailListService();
        $this->campaignService = new EmailCampaignService();
        $this->trackingService = new EmailTrackingService();
    }

    private function uid(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    // ============================================================
    //  Pages (web routes)
    // ============================================================

    public function index(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $tabs = $this->emailTabsHtml('dashboard');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>📬 تسويق البريد الاحترافي</h3>
                <span class="p-card-sub">منصة تسويق بريد مدمجة في Tourfecto — حملات بتتبع فتح/كليك وإلغاء اشتراك، من بياناتك وعلى بنيتك</span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;" id="emKpis">
                <div class="p-loading-row">جارِ التحميل...</div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;" id="emQuickWrap">
            <div class="p-card">
                <div class="p-card-head"><h3>🚀 إجراء سريع</h3></div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button class="p-btn primary" onclick="window.location.href='/email-marketing/campaigns?new=1'">+ حملة جديدة</button>
                    <button class="p-btn" onclick="window.location.href='/email-marketing/lists'">إدارة القوائم</button>
                    <button class="p-btn" onclick="window.location.href='/email-marketing/templates'">القوالب</button>
                </div>
            </div>
            <div class="p-card">
                <div class="p-card-head"><h3>📡 بوابة الإرسال</h3><span class="p-card-sub">عبر سيرفر البريد (SMTP)</span></div>
                <div id="emDeliveryStatus"><div class="p-loading-row">جارِ الفحص...</div></div>
            </div>
        </div>

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>📨 أحدث الحملات</h3></div>
            <div id="emRecentCampaigns"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>👥 القوائم الأعلى</h3></div>
            <div id="emTopLists"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>
        HTML;

        $script = $this->indexJs();
        echo $this->renderPanelPage('email_marketing', 'تسويق البريد', 'حملات بريد احترافية بتتبع كامل', $body, $script);
        return [];
    }

    public function showListsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('lists');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>👥 قوائم الجمهور</h3>
                <button class="p-btn primary xs" onclick="openListModal()">+ قائمة جديدة</button>
            </div>
            <div id="emListsTable"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card">
            <div class="p-card-head">
                <h3>📇 المشتركون <span id="emSubCountLabel"></span></h3>
                <div style="display:flex;gap:8px;">
                    <select id="emSubListFilter" class="p-select" onchange="loadSubscribers()">
                        <option value="0">كل القوائم</option>
                    </select>
                    <button class="p-btn primary xs" onclick="openSubscriberModal()">+ إضافة مشترك</button>
                    <button class="p-btn xs" onclick="openImportModal()">📥 استيراد</button>
                </div>
            </div>
            <div id="emSubscribersTable"><div class="p-loading-row">جارِ التحميل...</div></div>
            <div id="emSubscribersPager" style="margin-top:10px;"></div>
        </div>

        <!-- List modal -->
        <div class="p-modal-overlay" id="listModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3 id="listModalTitle">قائمة جديدة</h3>
                    <button class="p-modal-close" onclick="document.getElementById('listModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <input type="hidden" id="listId" value="">
                    <label class="p-cell-muted" style="font-size:12px;">اسم القائمة *</label>
                    <input type="text" id="listName" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="مثال: عملاء العروض الصيفية">
                    <label class="p-cell-muted" style="font-size:12px;">وصف (اختياري)</label>
                    <input type="text" id="listDesc" class="p-select" style="width:100%;margin-bottom:10px;" placeholder="وصف مختصر">
                    <button class="p-btn primary" onclick="saveList()">حفظ</button>
                </div>
            </div>
        </div>

        <!-- Subscriber modal -->
        <div class="p-modal-overlay" id="subscriberModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3>إضافة مشترك</h3>
                    <button class="p-modal-close" onclick="document.getElementById('subscriberModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <label class="p-cell-muted" style="font-size:12px;">البريد الإلكتروني *</label>
                    <input type="email" id="subEmail" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="client@example.com">
                    <label class="p-cell-muted" style="font-size:12px;">الاسم</label>
                    <input type="text" id="subName" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="الاسم الكامل">
                    <label class="p-cell-muted" style="font-size:12px;">القائمة</label>
                    <select id="subList" class="p-select" style="width:100%;margin-bottom:10px;"></select>
                    <button class="p-btn primary" onclick="saveSubscriber()">حفظ</button>
                </div>
            </div>
        </div>

        <!-- Import modal -->
        <div class="p-modal-overlay" id="importModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3>📥 استيراد مشتركين</h3>
                    <button class="p-modal-close" onclick="document.getElementById('importModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <label class="p-cell-muted" style="font-size:12px;">القائمة المستهدفة</label>
                    <select id="importList" class="p-select" style="width:100%;margin-bottom:8px;"></select>
                    <label class="p-cell-muted" style="font-size:12px;">البيانات (صيغة JSON أو CSV: email,name في كل سطر)</label>
                    <textarea id="importData" class="p-select" style="width:100%;min-height:120px;font-family:monospace;font-size:12px;"
                        placeholder='[{&quot;email&quot;:&quot;a@example.com&quot;,&quot;name&quot;:&quot;أحمد&quot;},{&quot;email&quot;:&quot;b@example.com&quot;,&quot;name&quot;:&quot;سارة&quot;}]'></textarea>
                    <button class="p-btn primary xs" style="margin-top:8px;" onclick="importSubscribers()">استيراد</button>
                    <div id="importResult" style="margin-top:10px;font-size:13px;"></div>
                </div>
            </div>
        </div>
        HTML;

        $script = $this->listsJs();
        echo $this->renderPanelPage('email_marketing', 'قوائم الجمهور', 'إدارة المشتركين والقوائم', $body, $script);
        return [];
    }

    public function showTemplatesPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('templates');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>🎨 قوالب البريد</h3>
                <button class="p-btn primary xs" onclick="openTemplateModal()">+ قالب جديد</button>
            </div>
            <div id="emTemplatesGrid"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <!-- Template modal -->
        <div class="p-modal-overlay" id="templateModal">
            <div class="p-modal wide">
                <div class="p-modal-head">
                    <h3 id="templateModalTitle">قالب جديد</h3>
                    <button class="p-modal-close" onclick="document.getElementById('templateModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <input type="hidden" id="templateId" value="">
                    <label class="p-cell-muted" style="font-size:12px;">اسم القالب *</label>
                    <input type="text" id="templateName" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="مثال: نشرة العروض الأسبوعية">
                    <label class="p-cell-muted" style="font-size:12px;">الموضوع الافتراضي (subject)</label>
                    <input type="text" id="templateSubject" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="مثال: عروض هذا الأسبوع {{first_name}}">
                    <label class="p-cell-muted" style="font-size:12px;">محتوى HTML</label>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px;">
                        <span style="font-size:12px;color:#6b7280;">إدراج متغير:</span>
                        <button class="p-btn xs" onclick="insertVar('{{first_name}}')">{{first_name}}</button>
                        <button class="p-btn xs" onclick="insertVar('{{email}}')">{{email}}</button>
                        <button class="p-btn xs" onclick="insertVar('{{company_name}}')">{{company_name}}</button>
                        <button class="p-btn xs" onclick="insertVar('{{campaign_name}}')">{{campaign_name}}</button>
                        <button class="p-btn xs" onclick="insertVar('{{unsubscribe_url}}')">{{unsubscribe_url}}</button>
                    </div>
                    <textarea id="templateHtml" class="p-select" style="width:100%;min-height:260px;font-family:monospace;font-size:12px;" placeholder="<p>مرحبًا {{first_name}}،</p>"></textarea>
                    <div style="display:flex;gap:8px;margin-top:10px;">
                        <button class="p-btn primary" onclick="saveTemplate()">حفظ</button>
                        <button class="p-btn" onclick="previewTemplateInline()">👁 معاينة</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Preview modal -->
        <div class="p-modal-overlay" id="previewModal">
            <div class="p-modal wide">
                <div class="p-modal-head">
                    <h3>معاينة القالب</h3>
                    <button class="p-modal-close" onclick="document.getElementById('previewModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body" id="previewBody" style="background:#f3f4f6;"></div>
            </div>
        </div>
        HTML;

        $script = $this->templatesJs();
        echo $this->renderPanelPage('email_marketing', 'قوالب البريد', 'قوالب جاهزة بمتغيرات التخصيص', $body, $script);
        return [];
    }

    public function showCampaignsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('campaigns');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>🚀 الحملات</h3>
                <button class="p-btn primary xs" onclick="openCampaignModal()">+ حملة جديدة</button>
            </div>
            <div id="emCampaignsTable"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <!-- Campaign modal -->
        <div class="p-modal-overlay" id="campaignModal">
            <div class="p-modal wide">
                <div class="p-modal-head">
                    <h3 id="campaignModalTitle">حملة جديدة</h3>
                    <button class="p-modal-close" onclick="document.getElementById('campaignModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <input type="hidden" id="campaignId" value="">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <div>
                            <label class="p-cell-muted" style="font-size:12px;">اسم الحملة *</label>
                            <input type="text" id="campaignName" class="p-select" style="width:100%;" placeholder="مثال: إطلاق عرض الصيف">
                        </div>
                        <div>
                            <label class="p-cell-muted" style="font-size:12px;">الجمهور (قائمة)</label>
                            <select id="campaignList" class="p-select" style="width:100%;"></select>
                        </div>
                    </div>
                    <label class="p-cell-muted" style="font-size:12px;margin-top:8px;display:block;">الموضوع (subject) *</label>
                    <input type="text" id="campaignSubject" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="مثال: عرض خاص لأول 50 عميل {{first_name}}">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <div>
                            <label class="p-cell-muted" style="font-size:12px;">من (الاسم)</label>
                            <input type="text" id="campaignFromName" class="p-select" style="width:100%;" placeholder="شركتك">
                        </div>
                        <div>
                            <label class="p-cell-muted" style="font-size:12px;">من (البريد)</label>
                            <input type="text" id="campaignFromEmail" class="p-select" style="width:100%;" placeholder="noreply@yourdomain.com">
                        </div>
                    </div>
                    <label class="p-cell-muted" style="font-size:12px;margin-top:8px;display:block;">محتوى HTML</label>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px;">
                        <span style="font-size:12px;color:#6b7280;">إدراج متغير:</span>
                        <button class="p-btn xs" onclick="insertVarCampaign('{{first_name}}')">{{first_name}}</button>
                        <button class="p-btn xs" onclick="insertVarCampaign('{{email}}')">{{email}}</button>
                        <button class="p-btn xs" onclick="insertVarCampaign('{{unsubscribe_url}}')">{{unsubscribe_url}}</button>
                    </div>
                    <textarea id="campaignHtml" class="p-select" style="width:100%;min-height:220px;font-family:monospace;font-size:12px;" placeholder="<p>مرحبًا {{first_name}}،</p><p>عرض هذا الأسبوع...</p>"></textarea>
                    <div id="campaignScheduleWrap" style="margin-top:10px;display:none;">
                        <label class="p-cell-muted" style="font-size:12px;">جدولة (تاريخ ووقت)</label>
                        <input type="datetime-local" id="campaignScheduledAt" class="p-select" style="width:100%;">
                    </div>
                    <div style="display:flex;gap:8px;margin-top:12px;">
                        <button class="p-btn primary" onclick="saveCampaign(false)">حفظ كمسودة</button>
                        <button class="p-btn" onclick="saveCampaign(true)">📅 جدولة</button>
                        <button class="p-btn danger" onclick="document.getElementById('campaignModal').classList.remove('open')">إلغاء</button>
                    </div>
                </div>
            </div>
        </div>
        HTML;

        $script = $this->campaignsJs();
        echo $this->renderPanelPage('email_marketing', 'الحملات', 'إنشاء وجدولة حملات تسويق البريد', $body, $script);
        return [];
    }

    public function showCampaignDetailsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $campaignId = (int) ($params['id'] ?? 0);
        $campaign = $this->campaignService->get($this->uid(), $campaignId);
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        $tabs = $this->emailTabsHtml('campaigns');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>📊 تقرير الحملة: <span id="crName"></span></h3>
                <div style="display:flex;gap:8px;">
                    <button class="p-btn xs" onclick="refreshCampaignReport()">↻ تحديث</button>
                    <button class="p-btn xs danger" id="crCancelBtn" onclick="cancelCampaign()" style="display:none;">إلغاء الجدولة</button>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;" id="crKpis">
                <div class="p-loading-row">جارِ التحميل...</div>
            </div>
        </div>

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>📈 معدلات التفاعل</h3></div>
            <div id="crRates"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>📨 المستلمون</h3></div>
            <div id="crRecipients"><div class="p-loading-row">جارِ التحميل...</div></div>
            <div id="crRecipientsPager" style="margin-top:10px;"></div>
        </div>
        HTML;

        $script = $this->campaignReportJs($campaignId);
        echo $this->renderPanelPage('email_marketing', 'تقرير الحملة', 'إحصائيات وتفاعل الحملة', $body, $script);
        return [];
    }

    public function showReportsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('reports');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>📈 إحصائيات تسويق البريد</h3><span class="p-card-sub">معدلات الفتح والكليك عبر كل حملاتك</span></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;" id="emStatsKpis">
                <div class="p-loading-row">جارِ التحميل...</div>
            </div>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>🗂 أداء كل حملة</h3></div>
            <div id="emStatsCampaigns"><div class="p-loading-row">جارِ التحميل...</div></div>
        </div>
        HTML;

        $script = $this->reportsJs();
        echo $this->renderPanelPage('email_marketing', 'التقارير', 'إحصائيات تفاعل حملاتك', $body, $script);
        return [];
    }

    // ============================================================
    //  API: Dashboard
    // ============================================================

    public function dashboard(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = $this->uid();

        $subscribers = (int) $this->db->query(
            "SELECT COUNT(*) AS total FROM email_subscribers WHERE user_id = ? AND status = 'subscribed'",
            [$userId]
        )[0]['total'];

        $lists = (int) $this->db->query(
            "SELECT COUNT(*) AS total FROM email_lists WHERE user_id = ?",
            [$userId]
        )[0]['total'];

        $campaigns = $this->db->query(
            "SELECT COUNT(*) AS total, COALESCE(SUM(sent_count),0) AS sent, COALESCE(SUM(opened_count),0) AS opened, COALESCE(SUM(clicked_count),0) AS clicked
             FROM email_campaigns WHERE user_id = ?",
            [$userId]
        )[0];

        $sentTotal = (int) ($campaigns['sent'] ?? 0);
        $openRate = $sentTotal > 0 ? round((int) $campaigns['opened'] / $sentTotal * 100, 1) : 0;
        $clickRate = $sentTotal > 0 ? round((int) $campaigns['clicked'] / $sentTotal * 100, 1) : 0;

        return $this->success([
            'kpis' => [
                ['label' => 'المشتركون النشطون', 'value' => $subscribers, 'icon' => '👥'],
                ['label' => 'القوائم', 'value' => $lists, 'icon' => '🗂'],
                ['label' => 'الحملات', 'value' => (int) $campaigns['total'], 'icon' => '🚀'],
                ['label' => 'معدل الفتح', 'value' => $openRate . '%', 'icon' => '👁'],
                ['label' => 'معدل الكليك', 'value' => $clickRate . '%', 'icon' => '🖱'],
            ],
            'delivery' => $this->deliveryStatus(),
            'recent_campaigns' => $this->campaignService->list($userId),
            'top_lists' => $this->listService->lists($userId),
        ], 'Email marketing dashboard');
    }

    // ============================================================
    //  API: Lists & Subscribers
    // ============================================================

    public function lists(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success(['lists' => $this->listService->lists($this->uid())]);
    }

    public function createList(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->listService->createList($this->uid(), (string) $this->get('name'), $this->get('description'));
        return $result['success']
            ? $this->success(['id' => $result['id']], 'تم إنشاء القائمة')
            : $this->error($result['error'], 422);
    }

    public function updateList(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->listService->updateList($this->uid(), (int) ($params['id'] ?? 0), $this->all());
        return $result['success']
            ? $this->success([], 'تم تحديث القائمة')
            : $this->error($result['error'], 404);
    }

    public function deleteList(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->listService->deleteList($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'تم حذف القائمة')
            : $this->error($result['error'], 404);
    }

    public function listSubscribers(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $listId = (int) ($params['id'] ?? 0);
        $result = $this->listService->subscribers(
            $this->uid(),
            $listId,
            ['status' => $this->get('status'), 'q' => $this->get('q')],
            (int) $this->get('page', 1),
            (int) $this->get('per_page', 50)
        );
        return $this->success($result);
    }

    public function allSubscribers(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->listService->subscribers(
            $this->uid(),
            (int) $this->get('list_id', 0),
            ['status' => $this->get('status'), 'q' => $this->get('q')],
            (int) $this->get('page', 1),
            (int) $this->get('per_page', 50)
        );
        return $this->success($result);
    }

    public function createSubscriber(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $listId = (int) $this->get('list_id', 0);
        $result = $this->listService->subscribe(
            $this->uid(),
            (string) $this->get('email'),
            ['name' => $this->get('name'), 'source' => 'manual'],
            $listId
        );
        return $result['success']
            ? $this->success(['id' => $result['id']], 'تمت إضافة المشترك')
            : $this->error($result['error'], 422);
    }

    public function importSubscribers(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $listId = (int) $this->get('list_id', 0);
        $raw = (string) $this->get('data');
        $rows = $this->parseImportData($raw);
        if (empty($rows)) {
            return $this->error('لا توجد بيانات صالحة للاستيراد', 422);
        }
        $result = $this->listService->import($this->uid(), $rows, $listId);
        return $this->success($result, 'تم الاستيراد');
    }

    public function unsubscribeSubscriber(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        // API (إدارة): إلغاء اشتراك مشترك محدد
        $id = (int) ($params['id'] ?? 0);
        $rows = $this->db->query(
            "SELECT unsubscribe_token FROM email_subscribers WHERE id = ? AND user_id = ?",
            [$id, $this->uid()]
        );
        if (empty($rows)) {
            return $this->error('المشترك غير موجود', 404);
        }
        $ok = $this->listService->unsubscribeByToken($rows[0]['unsubscribe_token']);
        return $ok ? $this->success([], 'تم إلغاء اشتراك المستخدم') : $this->error('تعذر الإلغاء', 422);
    }

    public function deleteSubscriber(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->listService->deleteSubscriber($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'تم حذف المشترك')
            : $this->error($result['error'], 404);
    }

    // ============================================================
    //  API: Templates
    // ============================================================

    public function templates(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success([
            'templates' => (new EmailTemplate())->where(['user_id' => $this->uid()], ['created_at' => 'DESC']),
            'variables' => EmailRenderer::variables(),
        ]);
    }

    public function createTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (trim((string) $this->get('name')) === '') {
            return $this->error('اسم القالب مطلوب', 422);
        }
        $template = new EmailTemplate([
            'user_id' => $this->uid(),
            'name' => trim((string) $this->get('name')),
            'subject' => (string) $this->get('subject', ''),
            'html_body' => (string) $this->get('html_body', ''),
        ]);
        $id = $template->save();
        return $id ? $this->success(['id' => (int) $id], 'تم إنشاء القالب') : $this->error('تعذر الحفظ', 422);
    }

    public function getTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $template = (new EmailTemplate())->find((int) ($params['id'] ?? 0));
        if (!$template || (int) $template->getAttribute('user_id') !== $this->uid()) {
            return $this->error('القالب غير موجود', 404);
        }
        return $this->success($template->toArray());
    }

    public function updateTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $template = (new EmailTemplate())->find((int) ($params['id'] ?? 0));
        if (!$template || (int) $template->getAttribute('user_id') !== $this->uid()) {
            return $this->error('القالب غير موجود', 404);
        }
        foreach (['name', 'subject', 'html_body'] as $field) {
            if ($this->get($field) !== null) {
                $template->setAttribute($field, (string) $this->get($field));
            }
        }
        $template->save();
        return $this->success([], 'تم تحديث القالب');
    }

    public function deleteTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $template = (new EmailTemplate())->find((int) ($params['id'] ?? 0));
        if (!$template || (int) $template->getAttribute('user_id') !== $this->uid()) {
            return $this->error('القالب غير موجود', 404);
        }
        $template->delete();
        return $this->success([], 'تم حذف القالب');
    }

    public function previewTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $template = (new EmailTemplate())->find((int) ($params['id'] ?? 0));
        if (!$template || (int) $template->getAttribute('user_id') !== $this->uid()) {
            return $this->error('القالب غير موجود', 404);
        }
        $renderer = new EmailRenderer();
        $html = $renderer->personalize(
            (string) $template->getAttribute('html_body'),
            ['name' => 'مثال المستخدم', 'first_name' => 'مثال', 'email' => 'client@example.com', 'company_name' => 'شركتك', 'campaign_name' => 'حملة تجريبية']
        );
        return $this->success(['html' => $html, 'subject' => (string) $template->getAttribute('subject')]);
    }

    /** معاينة HTML عارض (قبل الحفظ) - يخصص المتغيرات فقط من غير تتبع. */
    public function previewHtml(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $renderer = new EmailRenderer();
        $html = $renderer->personalize(
            (string) $this->get('html', ''),
            ['name' => 'مثال المستخدم', 'first_name' => 'مثال', 'email' => 'client@example.com', 'company_name' => 'شركتك', 'campaign_name' => 'حملة تجريبية']
        );
        return $this->success(['html' => $html]);
    }

    // ============================================================
    //  API: Campaigns
    // ============================================================

    public function campaigns(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success([
            'campaigns' => $this->campaignService->list($this->uid()),
            'lists' => $this->listService->lists($this->uid()),
            'templates' => (new EmailTemplate())->where(['user_id' => $this->uid()], ['created_at' => 'DESC']),
        ]);
    }

    public function createCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->campaignService->create($this->uid(), $this->all());
        return $result['success']
            ? $this->success(['id' => $result['id']], 'تم إنشاء الحملة')
            : $this->error($result['error'], 422);
    }

    public function updateCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->campaignService->update($this->uid(), (int) ($params['id'] ?? 0), $this->all());
        return $result['success']
            ? $this->success([], 'تم تحديث الحملة')
            : $this->error($result['error'], 404);
    }

    public function deleteCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->campaignService->delete($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'تم حذف الحملة')
            : $this->error($result['error'], 404);
    }

    public function getCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $campaign = $this->campaignService->get($this->uid(), (int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        return $this->success($campaign);
    }

    /**
     * إرسال فوري: يجهّز المستلمين ثم يدفع مهمة في طابور الإرسال.
     */
    public function sendCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $campaignId = (int) ($params['id'] ?? 0);
        $campaign = $this->campaignService->get($this->uid(), $campaignId);
        if (!$campaign) {
            return $this->error('الحملة غير موجودة', 404);
        }
        if (!in_array($campaign['status'], ['draft', 'scheduled', 'sending'], true)) {
            return $this->error('لا يمكن إرسال حملة بهذه الحالة', 422);
        }

        $prepared = $this->campaignService->prepareRecipients($this->uid(), $campaignId);
        if (!$prepared['success']) {
            return $this->error($prepared['error'], 422);
        }
        if ($prepared['total'] <= 0) {
            return $this->error('لا يوجد مشتركون نشطون في الجمهور المستهدف', 422);
        }

        if (!class_exists('QueueManager')) {
            return $this->error('نظام الطوابير غير متاح', 500);
        }
        $queue = new QueueManager();
        $queue->push(SendEmailCampaignBatchJob::class, [
            'user_id' => $this->uid(),
            'campaign_id' => $campaignId,
        ], 'email');

        $this->db->query(
            "UPDATE email_campaigns SET status = 'sending' WHERE id = ?",
            [$campaignId]
        );

        return $this->success(['total' => $prepared['total']], 'بدأ إرسال الحملة (' . $prepared['total'] . ' مستلم) — سيتم الإرسال عبر cron');
    }

    public function sendTestEmail(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $toEmail = (string) $this->get('email');
        $result = $this->campaignService->sendTest($this->uid(), (int) ($params['id'] ?? 0), $toEmail);
        return $result['success']
            ? $this->success([], 'تم إرسال بريد الاختبار')
            : $this->error($result['error'] ?? 'تعذر الإرسال', 422);
    }

    public function scheduleCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->campaignService->schedule($this->uid(), (int) ($params['id'] ?? 0), (string) $this->get('scheduled_at'));
        return $result['success']
            ? $this->success([], 'تمت جدولة الحملة')
            : $this->error($result['error'], 422);
    }

    public function cancelCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->campaignService->cancel($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'تم إلغاء الحملة المجدولة')
            : $this->error($result['error'], 404);
    }

    public function campaignReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $report = $this->campaignService->report($this->uid(), (int) ($params['id'] ?? 0));
        if (!$report) {
            return $this->error('الحملة غير موجودة', 404);
        }
        $report['recipients'] = $this->db->query(
            "SELECT r.id, r.email, r.name, r.status, r.opened_at, r.clicked_at, r.open_count, r.click_count, r.error_message,
                    s.unsubscribe_token
             FROM email_campaign_recipients r
             LEFT JOIN email_subscribers s ON s.id = r.subscriber_id
             WHERE r.campaign_id = ?
             ORDER BY r.id ASC
             LIMIT 200",
            [$report['id']]
        );
        return $this->success($report);
    }

    public function stats(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = $this->uid();
        $campaigns = $this->db->query(
            "SELECT c.id, c.name, c.status, c.total_recipients, c.sent_count, c.opened_count, c.clicked_count,
                    c.unsubscribed_count, c.bounced_count, c.created_at, c.sent_at
             FROM email_campaigns c WHERE c.user_id = ?
             ORDER BY c.created_at DESC",
            [$userId]
        );

        $sentTotal = $campaigns['sent'] ?? 0;
        $sentTotal = 0;
        $openedTotal = 0;
        $clickedTotal = 0;
        $unsubTotal = 0;
        foreach ($campaigns as $c) {
            $sentTotal += (int) $c['sent_count'];
            $openedTotal += (int) $c['opened_count'];
            $clickedTotal += (int) $c['clicked_count'];
            $unsubTotal += (int) $c['unsubscribed_count'];
        }

        $subscribers = (int) $this->db->query(
            "SELECT COUNT(*) AS total FROM email_subscribers WHERE user_id = ? AND status = 'subscribed'",
            [$userId]
        )[0]['total'];

        return $this->success([
            'kpis' => [
                ['label' => 'إجمالي المرسل', 'value' => $sentTotal, 'icon' => '📨'],
                ['label' => 'مرات الفتح', 'value' => $openedTotal, 'icon' => '👁'],
                ['label' => 'الكليكات', 'value' => $clickedTotal, 'icon' => '🖱'],
                ['label' => 'إلغاء الاشتراك', 'value' => $unsubTotal, 'icon' => '🚫'],
                ['label' => 'المشتركون النشطون', 'value' => $subscribers, 'icon' => '👥'],
            ],
            'open_rate' => $sentTotal > 0 ? round($openedTotal / $sentTotal * 100, 1) : 0,
            'click_rate' => $sentTotal > 0 ? round($clickedTotal / $sentTotal * 100, 1) : 0,
            'campaigns' => $campaigns,
        ]);
    }

    // ============================================================
    //  Public tracking endpoints (من غير Auth - بيطلبها عملاء البريد)
    // ============================================================

    public function trackOpen(array $params = []): array
    {
        $raw = (string) ($params['token'] ?? '');
        $token = preg_replace('/\.gif$/', '', $raw);

        if ($token === '') {
            $this->gif();
            exit;
        }
        $this->trackingService->recordOpen($token);
        $this->gif();
        exit;
    }

    public function trackClick(array $params = []): array
    {
        $clickToken = (string) ($params['click_token'] ?? '');
        $encoded = (string) $this->get('u', '');
        $url = $this->trackingService->recordClick($clickToken, $encoded);

        if ($url === null) {
            http_response_code(404);
            echo 'Invalid tracking link.';
            exit;
        }
        header('Location: ' . $url, true, 302);
        exit;
    }

    public function unsubscribeLink(array $params = []): array
    {
        $token = (string) ($params['unsubscribe_token'] ?? '');
        $this->trackingService->unsubscribe($token);

        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>إلغاء الاشتراك</title>'
            . '<body style="margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:Arial,sans-serif;background:#f9fafb;color:#111827;">'
            . '<div style="text-align:center;padding:32px;max-width:420px;background:#fff;border-radius:16px;box-shadow:0 10px 25px rgba(0,0,0,.08);">'
            . '<div style="font-size:44px;">🙏</div>'
            . '<h2 style="margin:16px 0 8px;">تم إلغاء اشتراكك بنجاح</h2>'
            . '<p style="color:#6b7280;font-size:14px;">لن تستلم رسائل تسويقية من هذه القائمة بعد الآن. إذا أخطأت في الإلغاء يمكنك إعادة الاشتراك في أي وقت.</p>'
            . '</div></body></html>';
        exit;
    }

    // ============================================================
    //  Helpers
    // ============================================================

    private function deliveryStatus(): array
    {
        $smtp = (new Mailer())->isConfigured();
        return [
            'provider' => 'smtp',
            'smtp' => $smtp,
            'label' => $smtp
                ? 'إرسال عبر SMTP (سيرفر البريد الخاص بك)'
                : 'غير مكوّن — أضف MAIL_USERNAME/MAIL_PASSWORD في .env',
        ];
    }

    private function parseImportData(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        // JSON array أولًا
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // غير كده فسر كـ CSV: email,name في كل سطر
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = str_getcsv($line);
            if (isset($parts[0])) {
                $rows[] = ['email' => $parts[0], 'name' => $parts[1] ?? ''];
            }
        }
        return $rows;
    }

    private function gif(): void
    {
        header('Content-Type: image/gif');
        header('Content-Length: 43');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        http_response_code(200);
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }

    private function emailTabsHtml(string $active): string
    {
        $tabs = [
            'dashboard' => ['/email-marketing', '📊 لوحة التحكم'],
            'lists' => ['/email-marketing/lists', '👥 الجمهور'],
            'templates' => ['/email-marketing/templates', '🎨 القوالب'],
            'campaigns' => ['/email-marketing/campaigns', '🚀 الحملات'],
            'reports' => ['/email-marketing/reports', '📈 التقارير'],
        ];
        $html = '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;">';
        foreach ($tabs as $key => [$href, $label]) {
            $activeClass = $key === $active ? 'p-btn primary xs' : 'p-btn xs';
            $html .= "<a href=\"{$href}\" class=\"{$activeClass}\">{$label}</a>";
        }
        $html .= '</div>';
        return $html;
    }

    // ============================================================
    //  Page scripts
    // ============================================================

    private function indexJs(): string
    {
        return <<<'JS'
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        async function loadDashboard() {
            const r = await emApi('/dashboard');
            if (!r.success) return;
            const kpis = r.data.kpis.map(k => `
                <div class="p-cell" style="text-align:center;padding:18px 10px;">
                    <div style="font-size:26px;">${k.icon}</div>
                    <div style="font-size:22px;font-weight:700;margin-top:6px;">${k.value}</div>
                    <div style="font-size:12px;color:#6b7280;">${k.label}</div>
                </div>`).join('');
            document.getElementById('emKpis').innerHTML = kpis;

            const d = r.data.delivery;
            document.getElementById('emDeliveryStatus').innerHTML = `
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:22px;">${d.smtp ? '📧' : '⚠️'}</span>
                    <span style="font-size:14px;">${d.label}</span>
                </div>`;

            const cs = r.data.recent_campaigns || [];
            document.getElementById('emRecentCampaigns').innerHTML = cs.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>الحملة</th><th>الحالة</th><th>المرسل</th><th>الفتح</th><th>الكليك</th><th></th></tr></thead>
                    <tbody>${cs.map(c => `
                        <tr>
                            <td>${c.name}</td>
                            <td>${statusBadge(c.status)}</td>
                            <td>${c.sent_count}</td>
                            <td>${c.opened_count}</td>
                            <td>${c.clicked_count}</td>
                            <td><a class="p-btn xs" href="/email-marketing/campaigns/${c.id}">تقرير</a></td>
                        </tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">لا توجد حملات بعد — أنشئ حملتك الأولى من تبويب الحملات.</p>';

            const ls = r.data.top_lists || [];
            document.getElementById('emTopLists').innerHTML = ls.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>القائمة</th><th>المشتركون</th><th></th></tr></thead>
                    <tbody>${ls.map(l => `
                        <tr><td>${l.name}</td><td>${l.actual_count}</td>
                        <td><a class="p-btn xs" href="/email-marketing/lists">إدارة</a></td></tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">أنشئ أول قائمة جمهور من تبويب الجمهور.</p>';
        }
        function statusBadge(s) {
            const map = {draft:'مسودة', scheduled:'مجدولة', sending:'قيد الإرسال', sent:'أُرسلت', cancelled:'ملغاة', failed:'فشلت'};
            return `<span style="background:#f3f4f6;padding:2px 10px;border-radius:20px;font-size:12px;">${map[s] || s}</span>`;
        }
        loadDashboard();
        JS;
    }

    private function listsJs(): string
    {
        return <<<'JS'
        let emLists = [];
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        async function loadLists() {
            const r = await emApi('/lists');
            if (!r.success) return;
            emLists = r.data.lists;
            const sel = document.getElementById('emSubListFilter');
            sel.innerHTML = '<option value="0">كل القوائم</option>' + emLists.map(l =>
                `<option value="${l.id}">${l.name} (${l.actual_count})</option>`).join('');
            const sl = document.getElementById('subList');
            sl.innerHTML = '<option value="0">بدون قائمة</option>' + emLists.map(l =>
                `<option value="${l.id}">${l.name}</option>`).join('');
            const il = document.getElementById('importList');
            il.innerHTML = '<option value="0">بدون قائمة</option>' + emLists.map(l =>
                `<option value="${l.id}">${l.name}</option>`).join('');
            const cl = document.getElementById('campaignList');
            if (cl) cl.innerHTML = '<option value="0">اختر قائمة</option>' + emLists.map(l =>
                `<option value="${l.id}">${l.name} (${l.actual_count})</option>`).join('');

            document.getElementById('emListsTable').innerHTML = emLists.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>الاسم</th><th>الوصف</th><th>المشتركون</th><th>أُنشئت</th><th></th></tr></thead>
                    <tbody>${emLists.map(l => `
                        <tr>
                            <td><b>${l.name}</b></td>
                            <td style="color:#6b7280;">${l.description || '—'}</td>
                            <td>${l.actual_count}</td>
                            <td style="color:#6b7280;">${(l.created_at || '').slice(0,10)}</td>
                            <td style="text-align:left;white-space:nowrap;">
                                <button class="p-btn xs" onclick="editList(${l.id}, ${JSON.stringify(l.name)}, ${JSON.stringify(l.description || '')})">تعديل</button>
                                <button class="p-btn xs danger" onclick="removeList(${l.id})">حذف</button>
                            </td>
                        </tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">لا توجد قوائم بعد.</p>';
            loadSubscribers();
        }
        function openListModal(id, name, desc) {
            document.getElementById('listModalTitle').textContent = id ? 'تعديل القائمة' : 'قائمة جديدة';
            document.getElementById('listId').value = id || '';
            document.getElementById('listName').value = name || '';
            document.getElementById('listDesc').value = desc || '';
            document.getElementById('listModal').classList.add('open');
        }
        function editList(id, name, desc) { openListModal(id, name, desc); }
        async function saveList() {
            const id = document.getElementById('listId').value;
            const name = document.getElementById('listName').value.trim();
            if (!name) { alert('اسم القائمة مطلوب'); return; }
            const path = id ? '/lists/' + id : '/lists';
            const opts = {
                method: id ? 'PATCH' : 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({name, description: document.getElementById('listDesc').value})
            };
            const r = await emApi(path, opts);
            if (r.success) { document.getElementById('listModal').classList.remove('open'); loadLists(); }
            else alert(r.error || 'خطأ');
        }
        async function removeList(id) {
            if (!confirm('حذف هذه القائمة؟ (المشتركون أنفسهم لا يُحذفون)')) return;
            const r = await emApi('/lists/' + id, {method: 'DELETE'});
            if (r.success) loadLists(); else alert(r.error);
        }
        let emSubPage = 1;
        async function loadSubscribers() {
            const listId = document.getElementById('emSubListFilter').value;
            const r = await emApi('/subscribers?list_id=' + listId + '&page=' + emSubPage + '&per_page=50');
            if (!r.success) return;
            const d = r.data;
            document.getElementById('emSubCountLabel').textContent = '(' + d.total + ')';
            const rows = (d.data || []).map(s => `
                <tr>
                    <td>${s.email}</td>
                    <td>${s.name || '—'}</td>
                    <td>${subStatusBadge(s.status)}</td>
                    <td>${s.list_count} قوائم</td>
                    <td style="text-align:left;white-space:nowrap;">
                        ${s.status === 'subscribed'
                            ? `<button class="p-btn xs" onclick="unsubSub(${s.id})">إلغاء اشتراك</button>` : ''}
                        <button class="p-btn xs danger" onclick="delSub(${s.id})">حذف</button>
                    </td>
                </tr>`).join('');
            document.getElementById('emSubscribersTable').innerHTML = rows
                ? `<table class="p-table" style="width:100%;"><thead><tr><th>البريد</th><th>الاسم</th><th>الحالة</th><th>القوائم</th><th></th></tr></thead><tbody>${rows}</tbody></table>`
                : '<p class="p-cell-muted" style="padding:16px;">لا يوجد مشتركون.</p>';
            const pages = Math.max(1, Math.ceil(d.total / d.per_page));
            document.getElementById('emSubscribersPager').innerHTML = `
                <button class="p-btn xs" ${emSubPage <= 1 ? 'disabled' : ''} onclick="emSubPage--;loadSubscribers()">السابق</button>
                <span style="margin:0 10px;font-size:13px;">صفحة ${emSubPage} من ${pages}</span>
                <button class="p-btn xs" ${emSubPage >= pages ? 'disabled' : ''} onclick="emSubPage++;loadSubscribers()">التالي</button>`;
        }
        function subStatusBadge(s) {
            const map = {subscribed:'نشط', unsubscribed:'أُلغي اشتراكه', bounced:'ارتداد'};
            return `<span style="background:${s==='subscribed'?'#dcfce7':s==='unsubscribed'?'#fee2e2':'#fef3c7'};padding:2px 10px;border-radius:20px;font-size:12px;">${map[s]||s}</span>`;
        }
        function openSubscriberModal() {
            document.getElementById('subEmail').value = '';
            document.getElementById('subName').value = '';
            document.getElementById('subscriberModal').classList.add('open');
        }
        async function saveSubscriber() {
            const body = {
                email: document.getElementById('subEmail').value.trim(),
                name: document.getElementById('subName').value.trim(),
                list_id: document.getElementById('subList').value
            };
            const r = await emApi('/subscribers', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)});
            if (r.success) { document.getElementById('subscriberModal').classList.remove('open'); loadSubscribers(); }
            else alert(r.error);
        }
        function openImportModal() { document.getElementById('importData').value = ''; document.getElementById('importResult').innerHTML=''; document.getElementById('importModal').classList.add('open'); }
        async function importSubscribers() {
            const body = {list_id: document.getElementById('importList').value, data: document.getElementById('importData').value};
            const r = await emApi('/subscribers/import', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)});
            document.getElementById('importResult').innerHTML = r.success
                ? `✅ تم الاستيراد: ${r.data.added} جديد، ${r.data.updated} محدث، ${r.data.invalid} غير صالح`
                : '❌ ' + (r.error || 'خطأ');
            if (r.success) setTimeout(loadSubscribers, 600);
        }
        async function unsubSub(id) {
            if (!confirm('إلغاء اشتراك هذا المشترك نهائيًا؟')) return;
            const r = await emApi('/subscribers/' + id + '/unsubscribe', {method:'POST'});
            if (r.success) loadSubscribers(); else alert(r.error);
        }
        async function delSub(id) {
            if (!confirm('حذف هذا المشترك نهائيًا؟')) return;
            const r = await emApi('/subscribers/' + id, {method:'DELETE'});
            if (r.success) loadSubscribers(); else alert(r.error);
        }
        loadLists();
        JS;
    }

    private function templatesJs(): string
    {
        return <<<'JS'
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        async function loadTemplates() {
            const r = await emApi('/templates');
            if (!r.success) return;
            const list = r.data.templates || [];
            document.getElementById('emTemplatesGrid').innerHTML = list.length ? `
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;">
                    ${list.map(t => `
                        <div style="border:1px solid #e5e7eb;border-radius:12px;padding:14px;">
                            <div style="font-weight:600;">${t.name}</div>
                            <div style="font-size:12px;color:#6b7280;margin:6px 0;">${t.subject || 'بدون موضوع'}</div>
                            <div style="font-size:11px;color:#9ca3af;">${(t.html_body || '').length} حرف</div>
                            <div style="margin-top:10px;display:flex;gap:6px;">
                                <button class="p-btn xs" onclick="editTemplate(${t.id})">تعديل</button>
                                <button class="p-btn xs" onclick="previewT(${t.id})">👁 معاينة</button>
                                <button class="p-btn xs danger" onclick="removeTemplate(${t.id})">حذف</button>
                            </div>
                        </div>`).join('')}
                </div>` : '<p class="p-cell-muted" style="padding:16px;">لا توجد قوالب — أنشئ قالبًا لتسرّع بناء الحملات.</p>';
        }
        function openTemplateModal(id, name, subject, html) {
            document.getElementById('templateModalTitle').textContent = id ? 'تعديل القالب' : 'قالب جديد';
            document.getElementById('templateId').value = id || '';
            document.getElementById('templateName').value = name || '';
            document.getElementById('templateSubject').value = subject || '';
            document.getElementById('templateHtml').value = html || '';
            document.getElementById('templateModal').classList.add('add');
            document.getElementById('templateModal').classList.add('open');
        }
        function editTemplate(id) {
            fetch('/api/email-marketing/templates/' + id).then(r=>r.json()).then(r => {
                if (r.success) openTemplateModal(r.data.id, r.data.name, r.data.subject, r.data.html_body);
            });
        }
        function insertVar(v) {
            const ta = document.getElementById('templateHtml');
            ta.value += v;
            ta.focus();
        }
        async function saveTemplate() {
            const id = document.getElementById('templateId').value;
            const body = {
                name: document.getElementById('templateName').value.trim(),
                subject: document.getElementById('templateSubject').value,
                html_body: document.getElementById('templateHtml').value
            };
            const path = id ? '/templates/' + id : '/templates';
            const opts = {method: id ? 'PATCH' : 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)};
            const r = await emApi(path, opts);
            if (r.success) { document.getElementById('templateModal').classList.remove('open'); loadTemplates(); }
            else alert(r.error);
        }
        async function previewT(id) {
            const r = await emApi('/templates/' + id + '/preview', {method:'POST'});
            if (!r.success) return;
            document.getElementById('previewBody').innerHTML = `<div style="background:#fff;max-width:620px;margin:0 auto;border-radius:12px;overflow:hidden;">
                <div style="padding:12px 20px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#374151;">الموضوع: <b>${r.data.subject}</b></div>
                <iframe sandbox="" style="width:100%;min-height:420px;border:none;background:#fff;" srcdoc="${encodeURI(r.data.html)}"></iframe>
            </div>`;
            document.getElementById('previewModal').classList.add('open');
        }
        async function previewTemplateInline() {
            const r = await emApi('/templates/preview-html', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({html: document.getElementById('templateHtml').value})});
            if (!r.success) return;
            document.getElementById('previewBody').innerHTML = `<div style="background:#fff;max-width:620px;margin:0 auto;border-radius:12px;overflow:hidden;">
                <iframe sandbox="" style="width:100%;min-height:420px;border:none;background:#fff;" srcdoc="${encodeURI(r.data.html)}"></iframe>
            </div>`;
            document.getElementById('previewModal').classList.add('open');
        }
        async function removeTemplate(id) {
            if (!confirm('حذف هذا القالب؟')) return;
            const r = await emApi('/templates/' + id, {method:'DELETE'});
            if (r.success) loadTemplates(); else alert(r.error);
        }
        loadTemplates();
        JS;
    }

    private function campaignsJs(): string
    {
        return <<<'JS'
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        async function loadCampaigns() {
            const r = await emApi('/campaigns');
            if (!r.success) return;
            const cl = document.getElementById('campaignList');
            if (cl) cl.innerHTML = '<option value="0">اختر قائمة</option>' + (r.data.lists || []).map(l =>
                `<option value="${l.id}">${l.name} (${l.actual_count})</option>`).join('');

            const list = r.data.campaigns || [];
            document.getElementById('emCampaignsTable').innerHTML = list.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>الاسم</th><th>الجمهور</th><th>الحالة</th><th>المرسل</th><th>الفتح</th><th>الكليك</th><th>موعد الإرسال</th><th></th></tr></thead>
                    <tbody>${list.map(c => `
                        <tr>
                            <td><b>${c.name}</b><div style="font-size:11px;color:#6b7280;">${c.subject}</div></td>
                            <td style="font-size:12px;">${c.list_name || '—'}</td>
                            <td>${cStatusBadge(c.status)}</td>
                            <td>${c.sent_count}</td>
                            <td>${c.opened_count}</td>
                            <td>${c.clicked_count}</td>
                            <td style="font-size:12px;color:#6b7280;">${c.scheduled_at || '—'}</td>
                            <td style="text-align:left;white-space:nowrap;">
                                ${canAct(c.status) ? `<button class="p-btn xs primary" onclick="sendNow(${c.id})">إرسال</button>` : ''}
                                ${c.status === 'scheduled' ? `<button class="p-btn xs danger" onclick="cancelC(${c.id})">إلغاء</button>` : ''}
                                ${['draft','scheduled','cancelled'].includes(c.status) ? `<button class="p-btn xs" onclick="editCampaign(${c.id})">تعديل</button>` : ''}
                                ${['draft','cancelled'].includes(c.status) ? `<button class="p-btn xs danger" onclick="deleteC(${c.id})">حذف</button>` : ''}
                                <a class="p-btn xs" href="/email-marketing/campaigns/${c.id}">تقرير</a>
                            </td>
                        </tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">لا توجد حملات — أنشئ حملتك الأولى الآن.</p>';
        }
        function cStatusBadge(s) {
            const map = {draft:'مسودة', scheduled:'مجدولة', sending:'قيد الإرسال', sent:'أُرسلت', cancelled:'ملغاة', failed:'فشلت'};
            const colors = {draft:'#f3f4f6', scheduled:'#fef3c7', sending:'#dbeafe', sent:'#dcfce7', cancelled:'#fee2e2', failed:'#fee2e2'};
            return `<span style="background:${colors[s]||'#f3f4f6'};padding:2px 10px;border-radius:20px;font-size:12px;">${map[s]||s}</span>`;
        }
        function canAct(s) { return ['draft','scheduled'].includes(s); }
        function openCampaignModal() {
            document.getElementById('campaignModalTitle').textContent = 'حملة جديدة';
            ['campaignId','campaignName','campaignSubject','campaignFromName','campaignFromEmail','campaignHtml','campaignScheduledAt'].forEach(id => document.getElementById(id).value='');
            document.getElementById('campaignList').value = '0';
            document.getElementById('campaignScheduleWrap').style.display = 'none';
            document.getElementById('campaignModal').classList.add('open');
        }
        async function editCampaign(id) {
            const r = await emApi('/campaigns/' + id);
            if (!r.success) return;
            const c = r.data;
            document.getElementById('campaignModalTitle').textContent = 'تعديل الحملة';
            document.getElementById('campaignId').value = c.id;
            document.getElementById('campaignName').value = c.name;
            document.getElementById('campaignSubject').value = c.subject;
            document.getElementById('campaignFromName').value = c.from_name || '';
            document.getElementById('campaignFromEmail').value = c.from_email || '';
            document.getElementById('campaignHtml').value = c.html_body || '';
            document.getElementById('campaignList').value = c.list_id || '0';
            document.getElementById('campaignScheduleWrap').style.display = c.status === 'scheduled' ? 'block' : 'none';
            document.getElementById('campaignScheduledAt').value = c.scheduled_at ? c.scheduled_at.slice(0,16) : '';
            document.getElementById('campaignModal').classList.add('open');
        }
        function insertVarCampaign(v) {
            const ta = document.getElementById('campaignHtml');
            ta.value += v;
            ta.focus();
        }
        async function saveCampaign(schedule) {
            const id = document.getElementById('campaignId').value;
            const body = {
                name: document.getElementById('campaignName').value.trim(),
                subject: document.getElementById('campaignSubject').value.trim(),
                from_name: document.getElementById('campaignFromName').value.trim(),
                from_email: document.getElementById('campaignFromEmail').value.trim(),
                list_id: document.getElementById('campaignList').value,
                html_body: document.getElementById('campaignHtml').value,
                scheduled_at: schedule ? document.getElementById('campaignScheduledAt').value : null
            };
            if (!body.name || !body.subject || !body.html_body) { alert('املأ اسم الحملة والموضوع والمحتوى'); return; }
            const path = id ? '/campaigns/' + id : '/campaigns';
            const opts = {method: id ? 'PATCH' : 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)};
            const r = await emApi(path, opts);
            if (!r.success) { alert(r.error); return; }
            const cid = r.data.id || id;
            if (schedule) {
                const s = await emApi('/campaigns/' + cid + '/schedule', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({scheduled_at: document.getElementById('campaignScheduledAt').value})});
                if (!s.success) { alert(s.error); return; }
            }
            document.getElementById('campaignModal').classList.remove('open');
            loadCampaigns();
        }
        async function sendNow(id) {
            if (!confirm('بدء إرسال الحملة لكل المشتركين النشطين في الجمهور؟')) return;
            const r = await emApi('/campaigns/' + id + '/send', {method:'POST'});
            alert(r.success ? r.message : (r.error || 'خطأ'));
            if (r.success) loadCampaigns();
        }
        async function cancelC(id) {
            if (!confirm('إلغاء هذه الحملة المجدولة؟')) return;
            const r = await emApi('/campaigns/' + id + '/cancel', {method:'POST'});
            if (r.success) loadCampaigns(); else alert(r.error);
        }
        async function deleteC(id) {
            if (!confirm('حذف هذه الحملة نهائيًا؟')) return;
            const r = await emApi('/campaigns/' + id, {method:'DELETE'});
            if (r.success) loadCampaigns(); else alert(r.error);
        }
        const params = new URLSearchParams(window.location.search);
        if (params.get('new') === '1') { openCampaignModal(); history.replaceState({}, '', '/email-marketing/campaigns'); }
        loadCampaigns();
        JS;
    }

    private function campaignReportJs(int $campaignId): string
    {
        $cid = $campaignId;
        return <<<JS
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        async function loadReport() {
            const r = await emApi('/campaigns/{$cid}/report');
            if (!r.success) return;
            const c = r.data;
            document.getElementById('crName').textContent = c.name + ' — ' + c.subject;
            document.getElementById('crCancelBtn').style.display = c.status === 'scheduled' ? 'inline-block' : 'none';
            const kpis = [
                ['📨', c.sent_count, 'أُرسل'],
                ['👁', c.opened_count, 'فُتح'],
                ['🖱', c.clicked_count, 'كليك'],
                ['🚫', c.unsubscribed_count, 'إلغاء'],
                ['↩️', c.bounced_count, 'ارتداد'],
                ['⏳', (c.total_recipients - c.sent_count), 'معلق']
            ].map(k => `
                <div class="p-cell" style="text-align:center;padding:18px 10px;">
                    <div style="font-size:24px;">${k[0]}</div>
                    <div style="font-size:22px;font-weight:700;margin-top:6px;">${k[1]}</div>
                    <div style="font-size:12px;color:#6b7280;">${k[2]}</div>
                </div>`).join('');
            document.getElementById('crKpis').innerHTML = kpis;

            const rates = [
                ['معدل الفتح', c.open_rate + '%'],
                ['معدل الكليك', c.click_rate + '%'],
                ['كليك/فتح', c.click_to_open_rate + '%'],
                ['معدل الإلغاء', c.unsubscribe_rate + '%']
            ].map(rr => `
                <div style="padding:12px;border:1px solid #e5e7eb;border-radius:10px;text-align:center;">
                    <div style="font-size:20px;font-weight:700;">${rr[1]}</div>
                    <div style="font-size:12px;color:#6b7280;">${rr[0]}</div>
                </div>`).join('');
            document.getElementById('crRates').innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;">' + rates + '</div>';

            const rows = (c.recipients || []).map(rc => `
                <tr>
                    <td>${rc.email}</td>
                    <td>${rc.name || '—'}</td>
                    <td>${rBadge(rc.status)}</td>
                    <td>${rc.open_count || 0} / ${rc.click_count || 0}</td>
                    <td style="font-size:12px;color:#6b7280;">${rc.opened_at || '—'}</td>
                    <td style="font-size:12px;color:#6b7280;">${rc.clicked_at || '—'}</td>
                </tr>`).join('');
            document.getElementById('crRecipients').innerHTML = rows
                ? `<table class="p-table" style="width:100%;"><thead><tr><th>البريد</th><th>الاسم</th><th>الحالة</th><th>فتح/كليك</th><th>أول فتح</th><th>أول كليك</th></tr></thead><tbody>${rows}</tbody></table>`
                : '<p class="p-cell-muted" style="padding:16px;">لا يوجد مستلمون — شغّل إرسال الحملة أولًا.</p>';
        }
        function rBadge(s) {
            const map = {pending:'⏳', sent:'📤', opened:'👁', clicked:'🖱', unsubscribed:'🚫', bounced:'↩️', failed:'❌'};
            return map[s] || s;
        }
        async function refreshCampaignReport() { loadReport(); }
        async function cancelCampaign() {
            if (!confirm('إلغاء جدولة الحملة؟')) return;
            const r = await emApi('/campaigns/{$cid}/cancel', {method:'POST'});
            if (r.success) loadReport(); else alert(r.error);
        }
        loadReport();
        JS;
    }

    private function reportsJs(): string
    {
        return <<<'JS'
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        async function loadStats() {
            const r = await emApi('/stats');
            if (!r.success) return;
            const kpis = r.data.kpis.map(k => `
                <div class="p-cell" style="text-align:center;padding:18px 10px;">
                    <div style="font-size:26px;">${k.icon}</div>
                    <div style="font-size:22px;font-weight:700;margin-top:6px;">${k.value}</div>
                    <div style="font-size:12px;color:#6b7280;">${k.label}</div>
                </div>`).join('');
            document.getElementById('emStatsKpis').innerHTML = kpis;

            const cs = r.data.campaigns || [];
            document.getElementById('emStatsCampaigns').innerHTML = cs.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>الحملة</th><th>الحالة</th><th>المرسل</th><th>الفتح</th><th>نسبة الفتح</th><th>الكليك</th><th>نسبة الكليك</th><th>إلغاء</th><th></th></tr></thead>
                    <tbody>${cs.map(c => {
                        const base = Math.max(1, c.sent_count);
                        const openRate = Math.round(c.opened_count / base * 1000) / 10;
                        const clickRate = Math.round(c.clicked_count / base * 1000) / 10;
                        return `
                        <tr>
                            <td><b>${c.name}</b></td>
                            <td>${c.status}</td>
                            <td>${c.sent_count}</td>
                            <td>${c.opened_count}</td>
                            <td>${openRate}%</td>
                            <td>${c.clicked_count}</td>
                            <td>${clickRate}%</td>
                            <td>${c.unsubscribed_count}</td>
                            <td><a class="p-btn xs" href="/email-marketing/campaigns/${c.id}">تقرير</a></td>
                        </tr>`;}).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">لا توجد حملات مُرسلة بعد.</p>';
        }
        loadStats();
        JS;
    }
}
