<?php
/**
 * Tourfecto - Chat Controller
 * متحكم الشات الذكي مع نظام الموافقات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class ChatController extends Controller {
    /**
     * @var ChatManager $chatManager - مدير الشات
     */
    private $chatManager;
    
    /**
     * @var SubscriptionValidator $subscription - مدقق الاشتراكات
     */
    private $subscription;
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct();
        $this->chatManager = new ChatManager();
        $this->subscription = new SubscriptionValidator();
    }
    
    /**
     * معالجة رسالة واردة (Webhook)
     * POST /api/chat/webhook
     * @param array $params
     * @return array
     */
    public function webhook(array $params = []): array {
        try {
            // التحقق من صحة الـ Webhook
            $verifyToken = $this->get('verify_token');
            if (!$this->validateWebhook($verifyToken)) {
                return $this->error('Invalid webhook token', 401);
            }
            
            // معالجة الرسالة
            $result = $this->chatManager->processIncomingMessage($this->all());
            
            return $result;
            
        } catch (Exception $e) {
            Logger::error('Chat Webhook Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Webhook processing failed', 500);
        }
    }
    
    /**
     * التحقق من Webhook (لـ WhatsApp)
     * GET /api/chat/webhook
     * @param array $params
     * @return array
     */
    public function verifyWebhook(array $params = []): array {
        $mode = $this->get('hub.mode');
        $token = $this->get('hub.verify_token');
        $challenge = $this->get('hub.challenge');
        
        if ($mode === 'subscribe' && $token === WHATSAPP_WEBHOOK_VERIFY_TOKEN) {
            return [
                'success' => true,
                'challenge' => $challenge
            ];
        }
        
        return $this->error('Invalid verification token', 401);
    }
    
    /**
     * الحصول على رسائل المحادثة
     * GET /api/chat/messages
     * @param array $params
     * @return array
     */
    public function getMessages(array $params = []): array {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }
            
            $websiteId = $this->get('website_id');
            $sessionId = $this->get('session_id');
            $status = $this->get('status');
            $page = (int) ($this->get('page', 1));
            $limit = (int) ($this->get('limit', 20));
            $offset = ($page - 1) * $limit;
            
            $sql = "SELECT * FROM chat_messages WHERE user_id = ?";
            $params = [$this->user['id']];
            
            if ($websiteId) {
                $sql .= " AND website_id = ?";
                $params[] = $websiteId;
            }
            
            if ($sessionId) {
                $sql .= " AND session_id = ?";
                $params[] = $sessionId;
            }
            
            if ($status) {
                $sql .= " AND bot_status = ?";
                $params[] = $status;
            }
            
            $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $messages = $this->db->query($sql, $params);
            
            // فك تشفير البيانات الحساسة
            foreach ($messages as &$msg) {
                if (!empty($msg['encrypted_phone'])) {
                    $encryption = new Encryption();
                    $msg['customer_phone'] = $encryption->decryptCustomerData(
                        $msg['encrypted_phone'],
                        $msg['customer_phone'] ?? ''
                    );
                }
                unset($msg['encrypted_phone']);
                unset($msg['encrypted_email']);
            }
            
            // جلب العدد الإجمالي
            $sqlCount = str_replace("SELECT *", "SELECT COUNT(*) as total", $sql);
            $sqlCount = substr($sqlCount, 0, strpos($sqlCount, "ORDER BY"));
            $countResult = $this->db->query($sqlCount, array_slice($params, 0, -2));
            $total = (int) ($countResult[0]['total'] ?? 0);
            
            return $this->success([
                'messages' => $messages,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            
        } catch (Exception $e) {
            Logger::error('Get Messages Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to get messages', 500);
        }
    }
    
    /**
     * الحصول على رسائل في انتظار الموافقة
     * GET /api/chat/pending
     * @param array $params
     * @return array
     */
    public function getPendingApprovals(array $params = []): array {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }
            
            $limit = (int) ($this->get('limit', 50));
            $messages = ChatMessage::getPendingApprovals($this->user['id'], $limit);

            return $this->success([
                // تصحيح: كانت بترجع array من ChatMessage objects مباشرة -
                // json_encode بيحوّل أي object لـ "{}" فاضي لأن كل الخصائص
                // protected، يعني الـ frontend كان بيستلم صفوف فاضية تمامًا
                // (مفيش message_text ولا ai_reply_generated ولا أي حاجة).
                'pending' => array_map(fn($m) => $m->toArray(), $messages),
                'count' => count($messages),
                'total_unread' => ChatMessage::getUnreadCount($this->user['id'])
            ]);
            
        } catch (Exception $e) {
            Logger::error('Get Pending Approvals Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to get pending approvals', 500);
        }
    }
    
    /**
     * الموافقة على رد البوت
     * POST /api/chat/approve
     * @param array $params
     * @return array
     */
    public function approveReply(array $params = []): array {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }
            
            $messageId = $this->get('message_id');
            $action = $this->get('action', 'approve');
            $editedReply = $this->get('edited_reply');

            if (!$messageId) {
                return $this->error('Message ID is required', 400);
            }

            // لو المستخدم عدّل نص الرد المقترح قبل الموافقة، نحفظ التعديل
            // الأول قبل ما نوافق - عشان اللي يتبعت فعليًا يبقى النص المُعدّل
            // مش المسودة الأصلية اللي ولّدها الذكاء الاصطناعي.
            if ($action === 'approve' && $editedReply !== null && trim((string) $editedReply) !== '') {
                $message = (new ChatMessage())->find((int) $messageId);
                if ($message && (int) $message->getAttribute('user_id') === (int) $this->user['id']) {
                    $message->setAttribute('ai_reply_generated', trim((string) $editedReply));
                    $message->save();
                }
            }

            $result = $this->chatManager->approveBotReply(
                $messageId,
                $this->user['id'],
                $action
            );
            
            if (!$result['success']) {
                return $this->error($result['error'], 400);
            }
            
            $this->log('Chat Approval', [
                'message_id' => $messageId,
                'action' => $action
            ]);

            return $this->success($result, $result['message'] ?? ($action === 'approve' ? 'تمت الموافقة' : 'تم الرفض'));
            
        } catch (Exception $e) {
            Logger::error('Approve Reply Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to process approval', 500);
        }
    }
    
    /**
     * إرسال رسالة يدوياً
     * POST /api/chat/send
     * @param array $params
     * @return array
     */
    public function sendMessage(array $params = []): array {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }
            
            $phoneNumber = $this->get('phone_number');
            $message = $this->get('message');
            $websiteId = $this->get('website_id');
            $sessionId = $this->get('session_id');
            $platform = $this->get('platform', 'ultramsg');
            
            if (!$phoneNumber || !$message) {
                return $this->error('Phone number and message are required', 400);
            }
            
            // التحقق من صلاحية الاشتراك
            $subscription = $this->subscription->validateSubscription($this->user['id']);
            if (!$subscription['valid']) {
                return $this->error('No active subscription', 403);
            }

            // لو مفيش website_id صريح، نحاول نستنتجه من جلسة موجودة (نفس
            // session_id) - عشان الإرسال يستخدم اتصال UltraMsg الصح بتاع
            // العميل ده تحديدًا، مش مثيل واحد ثابت للموقع كله.
            if (!$websiteId && $sessionId) {
                $existing = $this->db->query(
                    "SELECT website_id FROM chat_messages WHERE session_id = ? AND user_id = ? LIMIT 1",
                    [$sessionId, $this->user['id']]
                );
                if (!empty($existing)) {
                    $websiteId = $existing[0]['website_id'];
                }
            }
            
            // إرسال الرسالة
            if ($platform === 'ultramsg' && $websiteId) {
                $sent = $this->chatManager->sendMessageForWebsite((int) $websiteId, $phoneNumber, $message);
            } else {
                $sent = $this->chatManager->sendMessage($phoneNumber, $message, $platform);
            }
            
            if (!$sent) {
                return $this->error('Failed to send message', 500);
            }
            
            // حفظ الرسالة الصادرة
            $encryption = new Encryption();
            $encryptedPhone = $encryption->encryptCustomerData($phoneNumber, $phoneNumber);
            
            $sql = "INSERT INTO chat_messages (
                        website_id, user_id, platform, customer_phone, encrypted_phone,
                        message_direction, message_text, bot_status, sent_at, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, 'outgoing', ?, 'sent', NOW(), NOW()
                    )";
            
            $this->db->query($sql, [
                $websiteId ?? 0,
                $this->user['id'],
                $platform,
                $phoneNumber,
                $encryptedPhone,
                $message
            ]);
            
            $this->log('Manual Message Sent', [
                'phone' => $phoneNumber,
                'platform' => $platform
            ]);
            
            return $this->success([
                'phone_number' => $phoneNumber,
                'message' => $message
            ], 'Message sent successfully');
            
        } catch (Exception $e) {
            Logger::error('Send Message Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to send message', 500);
        }
    }
    
    /**
     * الحصول على إعدادات البوت
     * GET /api/chat/settings
     * @param array $params
     * @return array
     */
    public function getSettings(array $params = []): array {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }
            
            $websiteId = $this->get('website_id');
            $platform = $this->get('platform', 'all');
            
            if (!$websiteId) {
                return $this->error('Website ID is required', 400);
            }
            
            $settings = BotSetting::getSettings($this->user['id'], $websiteId, $platform);
            
            return $this->success([
                'settings' => $settings->toArray()
            ]);
            
        } catch (Exception $e) {
            Logger::error('Get Settings Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to get settings', 500);
        }
    }
    
    /**
     * تحديث إعدادات البوت
     * PUT /api/chat/settings
     * @param array $params
     * @return array
     */
    public function updateSettings(array $params = []): array {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }
            
            $websiteId = $this->get('website_id');
            $platform = $this->get('platform', 'all');
            $settings = $this->get('settings');
            
            if (!$websiteId || !$settings) {
                return $this->error('Website ID and settings are required', 400);
            }
            
            $botSettings = BotSetting::getSettings($this->user['id'], $websiteId, $platform);
            $botSettings->updateSettings($settings);
            
            $this->log('Bot Settings Updated', [
                'website_id' => $websiteId,
                'platform' => $platform
            ]);
            
            return $this->success([
                'settings' => $botSettings->toArray()
            ], 'Settings updated successfully');
            
        } catch (Exception $e) {
            Logger::error('Update Settings Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to update settings', 500);
        }
    }
    
    /**
     * التحقق من صحة Webhook
     * @param string|null $token
     * @return bool
     */
    private function validateWebhook(?string $token): bool {
        $verifyToken = WHATSAPP_WEBHOOK_VERIFY_TOKEN;
        return hash_equals($verifyToken, $token ?? '');
    }

    // ============================================
    // صفحات الويب الفعلية (كانت بترجع JSON فاضي بدل صفحة حقيقية)
    // ============================================

    /**
     * GET /chat/
     *
     * ⚠️ ملاحظة توثيق مهمة (AI Chat Platform - توحيد Frontend/Backend):
     * الصفحة دي كانت بتعرض قائمة مسطّحة من `chat_messages` (بيانات قديمة/
     * Legacy) وبتفتح `/chat/conversation/{session_id}`. دلوقتي بقت Unified
     * Inbox حقيقية: بتجيب المحادثات من `/api/ai-chat/websites/{id}/conversations`
     * (بند 1، 15، 16 من AI Chat Backend) بدل الجدول القديم مباشرة، وكل
     * صف بيوَدّي لـ `/chat/conversation/{ai_conversation_id}` (نفس الـRoute
     * الموجود، بس الـID دلوقتي هو معرّف المحادثة الموحّدة مش session_id).
     *
     * التصميم والـCSS classes وطريقة الصفحة (Panel/panel.js) اتحافظ
     * عليها 100% زي ما هي - نفس نظام `.p-toolbar`/`.p-card`/`.p-table`.
     */
    public function index(array $params = []): array {
        $body = <<<'HTML'
        <div class="ch-toolbar">
            <div class="ch-search">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                <input type="text" id="ucSearch" class="form-control" placeholder="ابحث بالاسم أو الهاتف أو الإيميل...">
            </div>
            <select id="ucStatus" class="p-select" title="الحالة">
                <option value="">كل الحالات</option>
                <option value="open">مفتوحة</option>
                <option value="pending">قيد الانتظار</option>
                <option value="resolved">تم الحل</option>
                <option value="closed">مغلقة</option>
            </select>
            <select id="ucAiStatus" class="p-select" title="AI أو موظف">
                <option value="">AI أو موظف</option>
                <option value="ai">الذكاء الاصطناعي</option>
                <option value="human">موظف</option>
                <option value="paused">متوقف</option>
            </select>
            <select id="ucLeadStatus" class="p-select" title="حالة الـLead">
                <option value="">كل حالات Lead</option>
                <option value="new_inquiry">استفسار جديد</option>
                <option value="qualifying">قيد التأهيل</option>
                <option value="qualified">مؤهّل</option>
                <option value="hot_lead">Lead ساخن</option>
                <option value="converted">تم التحويل</option>
                <option value="lost">فاقد</option>
            </select>
            <select id="ucChannel" class="p-select" title="القناة">
                <option value="">كل القنوات</option>
                <option value="whatsapp">واتساب</option>
                <option value="website_chat">شات الموقع</option>
                <option value="messenger">Messenger</option>
                <option value="instagram">Instagram</option>
                <option value="email">إيميل</option>
            </select>
            <select id="ucTag" class="p-select" title="الوسم">
                <option value="">كل الوسوم</option>
                <option value="HOT_LEAD">HOT_LEAD</option>
                <option value="NEW_INQUIRY">NEW_INQUIRY</option>
                <option value="PRICE_REQUEST">PRICE_REQUEST</option>
                <option value="COMPLAINT">COMPLAINT</option>
                <option value="FOLLOW_UP">FOLLOW_UP</option>
                <option value="BOOKING_INTENT">BOOKING_INTENT</option>
                <option value="VIP">VIP</option>
                <option value="HUMAN_REQUIRED">HUMAN_REQUIRED</option>
            </select>
            <button class="p-btn outline xs" onclick="ucApplyFilters()">بحث</button>
            <div class="ch-toolbar-spacer"></div>
            <a href="/chat/pending" class="p-btn outline xs">الرسائل المعلّقة</a>
            <a href="/chat/leads" class="p-btn outline xs">Leads</a>
            <a href="/chat/knowledge-base" class="p-btn outline xs">قاعدة المعرفة</a>
            <a href="/chat/followup-settings" class="p-btn outline xs">المتابعة التلقائية</a>
            <a href="/chat/analytics" class="p-btn outline xs">التحليلات</a>
            <a href="/chat/settings" class="p-btn primary xs">ربط واتساب والإعدادات</a>
        </div>

        <div class="ch-filterbar" id="ucQuickFilters">
            <span class="ch-chip active" data-qf="all" onclick="ucQuickFilter('all')">الكل</span>
            <span class="ch-chip" data-qf="unread" onclick="ucQuickFilter('unread')"><span class="ch-dot"></span>غير مقروءة</span>
            <span class="ch-chip" data-qf="ai" onclick="ucQuickFilter('ai')">AI</span>
            <span class="ch-chip" data-qf="human" onclick="ucQuickFilter('human')">موظف</span>
            <span class="ch-chip" data-qf="hot_leads" onclick="ucQuickFilter('hot_leads')"><span class="ch-dot"></span>Leads ساخنة</span>
            <span class="ch-chip" data-qf="follow_up" onclick="ucQuickFilter('follow_up')">متابعة</span>
            <span class="ch-chip" data-qf="closed" onclick="ucQuickFilter('closed')">مغلقة</span>
            <span class="ch-chip" data-qf="vip" onclick="ucQuickFilter('vip')">VIP</span>
            <span class="ch-chip" data-qf="complaints" onclick="ucQuickFilter('complaints')"><span class="ch-dot"></span>شكاوى</span>
        </div>

        <div id="ucNoWebsite" class="ch-card" style="display:none;">
            <div class="ch-empty"><div class="ch-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg></div><div class="ch-empty-title">اختر موقعًا</div><div class="ch-empty-sub">اختر موقعًا من القائمة أعلى الصفحة أولًا لعرض محادثاته.</div></div>
        </div>

        <div id="ucListWrap" class="ch-card" style="display:none;">
            <div class="ch-inbox" id="ucInboxList"></div>
            <div style="display:flex;justify-content:center;align-items:center;gap:12px;padding:16px;">
                <button class="p-btn outline xs" id="ucPrevBtn" onclick="ucGoPage(-1)" disabled>← السابق</button>
                <span class="p-cell-muted" id="ucPageLabel">صفحة 1</span>
                <button class="p-btn outline xs" id="ucNextBtn" onclick="ucGoPage(1)">التالي →</button>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    const UI = window.ChatUI;

    const STATUS_CHIP = {
        open: ['مفتوحة', 'green'], pending: ['قيد الانتظار', ''],
        resolved: ['تم الحل', 'blue'], closed: ['مغلقة', 'red'],
    };
    const AI_STATUS_CHIP = {
        ai: ['AI', 'green'], human: ['موظف', ''], paused: ['متوقف', 'red'],
    };
    const LEAD_STATUS_CHIP = {
        none: '', new_inquiry: ['استفسار جديد', ''], qualifying: ['قيد التأهيل', ''],
        qualified: ['مؤهّل', 'green'], hot_lead: ['Lead ساخن', 'red'],
        converted: ['تم التحويل', 'green'], lost: ['فاقد', 'red'],
    };

    function ensureWebsiteSelected() {
        const id = P.getCurrentWebsiteId();
        document.getElementById('ucNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('ucListWrap').style.display = id ? 'block' : 'none';
        return id;
    }

    const PAGE_SIZE = 30;
    let currentPage = 1;
    let activeQuickFilter = 'all';

    window.ucApplyFilters = function () { currentPage = 1; activeQuickFilter = null; ucHighlightQuickFilter(); load(); };

    window.ucQuickFilter = function (key) {
        currentPage = 1;
        activeQuickFilter = key;

        document.getElementById('ucStatus').value = '';
        document.getElementById('ucAiStatus').value = '';
        document.getElementById('ucLeadStatus').value = '';
        document.getElementById('ucTag').value = '';

        switch (key) {
            case 'unread': break;
            case 'ai': document.getElementById('ucAiStatus').value = 'ai'; break;
            case 'human': document.getElementById('ucAiStatus').value = 'human'; break;
            case 'hot_leads': document.getElementById('ucLeadStatus').value = 'hot_lead'; break;
            case 'follow_up': document.getElementById('ucTag').value = 'FOLLOW_UP'; break;
            case 'closed': document.getElementById('ucStatus').value = 'closed'; break;
            case 'vip': document.getElementById('ucTag').value = 'VIP'; break;
            case 'complaints': document.getElementById('ucTag').value = 'COMPLAINT'; break;
            case 'all': default: break;
        }

        ucHighlightQuickFilter();
        load();
    };

    function ucHighlightQuickFilter() {
        document.querySelectorAll('#ucQuickFilters [data-qf]').forEach(el => {
            const active = el.dataset.qf === activeQuickFilter;
            el.classList.toggle('active', active);
            el.classList.remove('teal', 'red', 'purple');
        });
    }

    window.ucGoPage = function (delta) {
        currentPage = Math.max(1, currentPage + delta);
        load();
    };

    async function load() {
        const websiteId = ensureWebsiteSelected();
        if (!websiteId) return;

        const qs = new URLSearchParams();
        const search = document.getElementById('ucSearch').value.trim();
        const status = document.getElementById('ucStatus').value;
        const aiStatus = document.getElementById('ucAiStatus').value;
        const leadStatus = document.getElementById('ucLeadStatus').value;
        const channel = document.getElementById('ucChannel').value;
        const tag = document.getElementById('ucTag').value;
        if (search) qs.set('search', search);
        if (status) qs.set('status', status);
        if (aiStatus) qs.set('ai_status', aiStatus);
        if (leadStatus) qs.set('lead_status', leadStatus);
        if (channel) qs.set('channel', channel);
        if (tag) qs.set('tag', tag);
        if (activeQuickFilter === 'unread') qs.set('unread_only', '1');
        qs.set('page', currentPage);

        const list = document.getElementById('ucInboxList');
        list.innerHTML = '<div class="ch-skeleton-row"><div class="ch-skeleton avatar"></div><div class="ch-skeleton line"></div></div><div class="ch-skeleton-row"><div class="ch-skeleton avatar"></div><div class="ch-skeleton line"></div></div><div class="ch-skeleton-row"><div class="ch-skeleton avatar"></div><div class="ch-skeleton line"></div></div>';

        const res = await fetchJSON('/api/ai-chat/websites/' + encodeURIComponent(websiteId) + '/conversations?' + qs.toString());

        document.getElementById('ucPageLabel').textContent = 'صفحة ' + currentPage;
        document.getElementById('ucPrevBtn').disabled = currentPage <= 1;

        if (!res.success) {
            list.innerHTML = '<div class="ch-empty"><div class="ch-empty-icon">' + UI.icon('alert') + '</div><div class="ch-empty-title">تعذر التحميل</div><div class="ch-empty-sub">' + esc(res.error || 'تعذر تحميل المحادثات') + '</div></div>';
            document.getElementById('ucNextBtn').disabled = true;
            return;
        }

        const conversations = (res.data && Array.isArray(res.data.conversations)) ? res.data.conversations : [];
        document.getElementById('ucNextBtn').disabled = conversations.length < PAGE_SIZE;

        if (!conversations.length) {
            list.innerHTML = '<div class="ch-empty"><div class="ch-empty-icon">' + UI.icon('chat') + '</div><div class="ch-empty-title">' + (currentPage > 1 ? 'لا توجد نتائج في هذه الصفحة' : 'لا توجد محادثات بعد') + '</div><div class="ch-empty-sub">المحادثات هتظهر هنا أول ما يرسل العملاء أي رسالة.</div></div>';
            return;
        }

        list.innerHTML = conversations.map(c => {
            const customer = c.customer_name || c.customer_phone || c.customer_email || 'عميل غير معروف';
            const channelMeta = UI.channelMeta[c.channel] || { icon: 'chat', avatar: '' };
            const aiChip = AI_STATUS_CHIP[c.ai_status];
            const statusChip = STATUS_CHIP[c.status];
            const leadChip = LEAD_STATUS_CHIP[c.lead_status];
            const unread = c.unread_count > 0 ? '<span class="ch-unread-badge">' + c.unread_count + '</span>' : '';
            const priorityBar = c.priority === 'urgent' ? '<span class="ch-priority-bar urgent"><i></i></span>' : c.priority === 'high' ? '<span class="ch-priority-bar high"><i></i></span>' : '';
            const preview = c.customer_phone || c.customer_email || '';
            return `
                <div class="ch-conv ${c.unread_count > 0 ? 'unread' : ''}" onclick="window.location.href='/chat/conversation/${c.id}'">
                    <div class="ch-avatar ${channelMeta.avatar || ''}">${UI.initials(customer)}
                        <span class="ch-chan-badge">${UI.icon(channelMeta.icon || 'chat', 10)}</span>
                    </div>
                    <div class="ch-conv-body">
                        <div class="ch-conv-top">
                            <span class="ch-conv-name">${esc(customer)}</span>
                            ${priorityBar}
                            <span class="ch-conv-time">${P.timeAgo(c.last_message_at)}</span>
                        </div>
                        <div class="ch-conv-preview">${esc(preview)}</div>
                    </div>
                    <div class="ch-conv-meta">
                        ${aiChip ? UI.pill(aiChip[0], aiChip[1]) : ''}
                        ${statusChip ? UI.pill(statusChip[0], statusChip[1]) : ''}
                        ${leadChip ? UI.pill(leadChip[0], leadChip[1]) : ''}
                        ${unread}
                    </div>
                </div>`;
        }).join('');
    }

    document.getElementById('ucSearch').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') load();
    });
    window.addEventListener('tourfecto:website-changed', function () { currentPage = 1; load(); });

    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('chat', $this->tr('sidebar.chat'), $this->tr('chat.page_subtitle'), $body, $script);
        exit;
    }

    /**
     * GET /chat/conversation/{id}
     *
     * ⚠️ ملاحظة توثيق مهمة (AI Chat Platform - توحيد Frontend/Backend):
     * قبل كده الـ{id} في الرابط كان `chat_messages.session_id` (نص قديم)
     * والبيانات جايه من `/api/chat/conversation/{session_id}`. دلوقتي
     * الـ{id} هو معرّف المحادثة الموحّدة (`ai_conversations.id`) والبيانات
     * جايه من `/api/ai-chat/websites/{website}/conversations/{id}` (بند 1،
     * 2، 3، 8، 9، 10، 11 من AI Chat Backend) - نفس الـRoute بالظبط، لكن
     * معنى الـID اتغيّر ليواكب Unified Inbox الحقيقي. أي رابط قديم فيه
     * session_id نصّي مش رقمي هيوصل لصفحة "المحادثة غير موجودة" بدل ما
     * يفشل بصمت.
     *
     * حقول واضح إنها غير متاحة حاليًا في AI Chat Backend (Notes، Related
     * Deal، وقائمة موظفين للتعيين) اتوثّقت بمكانها بدل ما تُختلق - راجع
     * CHANGELOG.
     */
    public function showConversation(array $params): array {
        $conversationId = (int) ($params['id'] ?? $params['session_id'] ?? 0);
        $currentUserId = (int) ($this->user['id'] ?? 0);

        $body = <<<HTML
        <div id="loadingConv" class="ch-empty"><div class="ch-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div>جاري تحميل المحادثة...</div>
        <div id="convNotFound" class="ch-empty" style="display:none;"><div class="ch-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l10 18H2L12 3z"/><path d="M12 10v5"/><path d="M12 18h.01"/></svg></div>المحادثة غير موجودة أو مش تابعة للموقع الحالي.</div>

        <div id="convBody" style="display:none;">
            <div class="ch-card" id="convHeader" style="margin-bottom:14px;"></div>

            <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:2 1 420px;min-width:280px;">
                    <div class="ch-thread" id="convThread"></div>

                    <div class="ch-card ch-composer" style="margin-top:14px;">
                        <div id="aiSuggestions" style="display:none;margin-bottom:10px;"></div>
                        <div class="form-group">
                            <textarea id="manualMessage" class="form-control" rows="3" placeholder="اكتب ردك هنا..."></textarea>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button class="p-btn primary" id="sendManualBtn" onclick="sendManual()"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg> إرسال</button>
                            <button class="p-btn outline" id="suggestBtn" onclick="loadSuggestions()"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3z"/><path d="M19 15l.9 2.4L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.6L19 15z"/></svg> اقتراح رد AI</button>
                        </div>
                    </div>
                </div>

                <div style="flex:1 1 260px;min-width:240px;">
                    <div class="ch-card" id="leadPanel" style="margin-bottom:14px;"></div>
                    <div class="ch-card">
                        <div class="ch-card-head"><h3 class="ch-card-title"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-5z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></svg> ملاحظات وصفقات</h3></div>
                        <div class="ch-card-body">
                            <div class="ch-empty" style="padding:16px 0;">
                                <div class="ch-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/></svg></div>
                                ميزة الملاحظات والصفقات المرتبطة غير متاحة حاليًا في AI Chat Backend.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    const UI = window.ChatUI;
    const conversationId = __CONVERSATION_ID__;
    const currentUserId = __CURRENT_USER_ID__;
    let websiteId = null;
    let currentConversation = null;

    const STATUS_OPTIONS = [
        ['open', 'مفتوحة'], ['pending', 'قيد الانتظار'], ['resolved', 'تم الحل'], ['closed', 'مغلقة'],
    ];
    const PRIORITY_OPTIONS = [
        ['low', 'منخفضة'], ['normal', 'عادية'], ['high', 'عالية'], ['urgent', 'عاجلة'],
    ];
    const STANDARD_TAGS = ['HOT_LEAD', 'NEW_INQUIRY', 'PRICE_REQUEST', 'COMPLAINT', 'FOLLOW_UP', 'BOOKING_INTENT', 'VIP', 'HUMAN_REQUIRED'];

    if (!conversationId) {
        document.getElementById('loadingConv').style.display = 'none';
        document.getElementById('convNotFound').style.display = 'block';
        return;
    }

    window.toggleHandoff = async function () {
        const isAi = currentConversation.ai_status === 'ai';
        const url = '/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId + (isAi ? '/handoff' : '/resume-ai');
        const res = await fetchJSON(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: isAi ? JSON.stringify({ reason: 'manual_takeover' }) : null,
        });
        if (res.success) { toast(isAi ? 'تم تحويل المحادثة لك' : 'تم استرجاع الرد الآلي', 'success'); load(); }
        else { toast(res.error || 'فشلت العملية', 'error'); }
    };

    window.assignToggle = async function () {
        const isMine = currentConversation.assigned_agent_id == currentUserId;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ assigned_agent_id: isMine ? null : currentUserId }),
        });
        if (res.success) { toast(isMine ? 'تم إلغاء التعيين' : 'تم تعيين المحادثة لك', 'success'); load(); }
        else { toast(res.error || 'فشلت العملية', 'error'); }
    };

    window.updateField = async function (field, value) {
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ [field]: value }),
        });
        if (res.success) { toast('تم التحديث', 'success'); load(); }
        else { toast(res.error || 'فشل التحديث', 'error'); }
    };

    window.toggleTag = async function (tag) {
        const tags = currentConversation.tags || [];
        const has = tags.includes(tag);
        const newTags = has ? tags.filter(t => t !== tag) : tags.concat([tag]);
        await updateField('tags', newTags);
    };

    window.promptAddCustomTag = async function () {
        const name = prompt('اكتب اسم الوسم الجديد (مثال: URGENT_QUOTE)');
        if (!name || !name.trim()) return;

        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/custom-tags', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: name.trim() }),
        });

        if (res.success) {
            toast('تم إنشاء الوسم', 'success');
            await loadCustomTags();
            renderHeader(currentConversation);
        } else {
            toast(res.error || 'فشل إنشاء الوسم', 'error');
        }
    };

    window.deleteCustomTag = async function (tagId) {
        if (!confirm('حذف هذا الوسم المخصص من كل الشركة، مش بس من هذه المحادثة؟')) return;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/custom-tags/' + tagId, { method: 'DELETE' });
        if (res.success) {
            toast('تم حذف الوسم', 'success');
            await loadCustomTags();
            renderHeader(currentConversation);
        } else {
            toast(res.error || 'فشل الحذف', 'error');
        }
    };

    async function loadCustomTags() {
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/custom-tags');
        customTags = (res.success && Array.isArray(res.data.tags)) ? res.data.tags : [];
    }

    window.sendManual = async function () {
        const message = document.getElementById('manualMessage').value.trim();
        if (!message) { toast('اكتب رسالة أولاً', 'error'); return; }
        const btn = document.getElementById('sendManualBtn');
        btn.disabled = true;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId + '/reply', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: message }),
        });
        btn.disabled = false;
        if (res.success) {
            toast(res.data && res.data.sent === false ? 'اتحفظت الرسالة لكن فشل الإرسال الفعلي للعميل' : 'تم الإرسال', res.data && res.data.sent === false ? 'error' : 'success');
            document.getElementById('manualMessage').value = '';
            load();
        } else {
            toast(res.error || 'فشل الإرسال', 'error');
        }
    };

    window.loadSuggestions = async function () {
        const box = document.getElementById('aiSuggestions');
        const btn = document.getElementById('suggestBtn');
        btn.disabled = true;
        box.style.display = 'block';
        box.innerHTML = '<div class="ch-suggest"><div class="ch-suggest-card"><div class="ch-suggest-icon">' + UI.icon('sparkles') + '</div><div class="ch-suggest-text">جاري توليد اقتراحات...</div></div></div>';

        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId + '/reply-suggestions');
        btn.disabled = false;

        if (!res.success || !res.data || !Array.isArray(res.data.suggestions) || !res.data.suggestions.length) {
            box.innerHTML = '<div class="ch-empty" style="padding:18px;"><div class="ch-empty-sub">' + esc((res.data && res.data.error) || res.error || 'لا توجد اقتراحات متاحة الآن') + '</div></div>';
            return;
        }

        box.innerHTML = '<div class="ch-suggest">' + res.data.suggestions.map((s, i) => `
            <div class="ch-suggest-card" data-text="${esc(s).replace(/"/g, '&quot;')}" onclick="document.getElementById('manualMessage').value = this.dataset.text;this.querySelector('.ch-suggest-use').textContent='تم التحديد ✓';">
                <div class="ch-suggest-icon">${UI.icon('sparkles')}</div>
                <div class="ch-suggest-text">
                    <div>${esc(s)}</div>
                    <div class="ch-suggest-use">اضغط لاستخدام هذا الاقتراح</div>
                </div>
            </div>`).join('') + '</div>';
    };

    let customTags = [];

    function renderHeader(c) {
        const customer = c.customer_name || c.customer_phone || c.customer_email || 'عميل غير معروف';
        const isAi = c.ai_status === 'ai';
        const isMine = c.assigned_agent_id == currentUserId;
        const channelMeta = UI.channelMeta[c.channel] || { icon: 'chat', avatar: '' };

        const standardTagsHtml = STANDARD_TAGS.map(t => {
            const active = (c.tags || []).includes(t);
            return `<span class="ch-pill ${active ? 'blue' : ''}" style="cursor:pointer;" onclick="toggleTag('${t}')">${active ? UI.icon('check', 11) : ''}${t}</span>`;
        }).join(' ');

        const customTagsHtml = customTags.map(t => {
            const active = (c.tags || []).includes(t.name);
            return `<span class="ch-pill ${active ? 'blue' : ''}" style="cursor:pointer;" onclick="toggleTag('${esc(t.name)}')">${active ? UI.icon('check', 11) : ''}${esc(t.name)}
                <a href="javascript:void(0)" onclick="event.stopPropagation();deleteCustomTag(${t.id})" style="margin-inline-start:4px;opacity:.7;">${UI.icon('x', 10)}</a></span>`;
        }).join(' ');

        const addTagHtml = `<span class="ch-pill" style="cursor:pointer;border:1px dashed rgba(255,255,255,.2);" onclick="promptAddCustomTag()">${UI.icon('plus', 11)} وسم مخصص</span>`;

        const tagsHtml = standardTagsHtml + ' ' + customTagsHtml + ' ' + addTagHtml;

        const statusSelect = '<select class="p-select" onchange="updateField(\'status\', this.value)">' +
            STATUS_OPTIONS.map(([v, l]) => `<option value="${v}" ${c.status === v ? 'selected' : ''}>${l}</option>`).join('') + '</select>';
        const prioritySelect = '<select class="p-select" onchange="updateField(\'priority\', this.value)">' +
            PRIORITY_OPTIONS.map(([v, l]) => `<option value="${v}" ${c.priority === v ? 'selected' : ''}>${l}</option>`).join('') + '</select>';

        document.getElementById('convHeader').innerHTML = `
            <div class="ch-card-head" style="padding:16px 18px;">
                <div class="ch-avatar lg ${channelMeta.avatar || ''}">${UI.initials(customer)}
                    <span class="ch-chan-badge">${UI.icon(channelMeta.icon || 'chat', 10)}</span>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <span style="font-size:17px;font-weight:800;color:var(--panel-text);">${esc(customer)}</span>
                        ${isAi ? UI.pill('يرد الآن: AI', 'green', 'robot') : UI.pill('يرد الآن: موظف', '', 'user')}
                    </div>
                    <div class="p-cell-muted" style="font-size:12.5px;margin-top:2px;">${esc(c.customer_phone || c.customer_email || '')}</div>
                </div>
                <div class="ch-conv-meta">
                    <button class="p-btn ${isAi ? 'outline' : 'primary'} xs" onclick="toggleHandoff()">${isAi ? 'تحويل لموظف' : 'استرجاع الرد الآلي'}</button>
                    <button class="p-btn outline xs" onclick="assignToggle()">${isMine ? 'إلغاء تعييني' : 'تعيين لي'}</button>
                    ${statusSelect}
                    ${prioritySelect}
                    ${c.ai_confidence_score !== null && c.ai_confidence_score !== undefined ? UI.pill('ثقة AI: ' + Math.round(c.ai_confidence_score * 100) + '%', 'purple', 'zap') : ''}
                </div>
            </div>
            <div class="ch-card-body" style="padding-top:12px;">
                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:${c.ai_summary || c.next_recommended_action ? '10px' : '0'};">${tagsHtml}</div>
                ${c.ai_summary ? '<div class="ch-hint" style="margin-bottom:8px;"><span class="ch-hint-title">' + UI.icon('sparkles') + ' ملخص AI</span>' + esc(c.ai_summary) + '</div>' : ''}
                ${c.next_recommended_action ? '<div class="ch-hint" style="background:var(--panel-success-light);border-color:rgba(78,205,196,.3);"><span class="ch-hint-title" style="color:var(--panel-teal);">' + UI.icon('trend-up') + ' الخطوة التالية المقترحة</span>' + esc(c.next_recommended_action) + '</div>' : ''}
            </div>
        `;
    }

    function renderThread(messages) {
        const thread = document.getElementById('convThread');
        if (!messages.length) {
            thread.innerHTML = '<div class="ch-empty"><div class="ch-empty-icon">' + UI.icon('chat') + '</div><div class="ch-empty-title">لا توجد رسائل بعد</div><div class="ch-empty-sub">ابدأ المحادثة أو انتظر أول رسالة من العميل.</div></div>';
            return;
        }
        thread.innerHTML = messages.map(m => {
            const incoming = m.message_direction === 'incoming';
            const auto = Number(m.is_auto_pilot) === 1;
            const who = incoming ? 'in' : (auto ? 'ai' : 'out');
            const label = incoming ? 'العميل' : (auto ? 'AI' : 'أنت');
            const labelIcon = incoming ? 'user' : (auto ? 'robot' : 'user');
            return `
                <div class="ch-msg ${who}">
                    <div class="ch-msg-avatar">${UI.avatar(label, 'sm', incoming ? 'purple' : auto ? '' : 'gold')}</div>
                    <div>
                        <div class="ch-msg-tag">${UI.icon(labelIcon, 11)}${label}</div>
                        <div class="ch-msg-bubble">${esc(m.message_text || m.ai_reply_generated || '')}</div>
                        <div class="ch-msg-time">${formatDate(m.sent_at || m.created_at)}</div>
                    </div>
                </div>`;
        }).join('');
        thread.scrollTop = thread.scrollHeight;
    }

    function renderLeadPanel(leads) {
        const panel = document.getElementById('leadPanel');
        const lead = (leads && leads.length) ? leads[0] : null;
        if (!lead) {
            panel.innerHTML = '<div class="ch-card-head"><span class="ch-card-title">معلومات Lead</span></div><div class="ch-empty" style="padding:22px 16px;"><div class="ch-empty-icon">' + UI.icon('target') + '</div><div class="ch-empty-sub">لا يوجد Lead مرتبط بهذه المحادثة بعد.</div></div>';
            return;
        }
        const score = lead.lead_score ?? '-';
        const intent = lead.intent_score ?? '-';
        panel.innerHTML = `
            <div class="ch-card-head"><span class="ch-card-title">معلومات Lead</span></div>
            <div class="ch-card-body">
                <div class="ch-lead-hero">
                    <div class="ch-avatar ${score >= 70 ? 'red' : score >= 40 ? 'gold' : ''}">${UI.icon(score >= 70 ? 'flame' : 'target', 20)}</div>
                    <div class="ch-lead-score">${score}<small>/ 100 درجة</small></div>
                </div>
                <div class="ch-kv-grid">
                    <div class="p-kv"><span class="k">نية الشراء</span><span class="v">${intent} / 100</span></div>
                    <div class="p-kv"><span class="k">الوجهة</span><span class="v">${esc(lead.destination || '-')}</span></div>
                    <div class="p-kv"><span class="k">الاهتمام</span><span class="v">${esc(lead.interest || '-')}</span></div>
                    <div class="p-kv"><span class="k">الحالة</span><span class="v">${esc(lead.status || '-')}</span></div>
                </div>
                <div style="margin-top:12px;">${UI.scoreBar(score)}</div>
                ${lead.next_recommended_action ? '<div class="ch-hint" style="margin-top:12px;"><span class="ch-hint-title">' + UI.icon('trend-up') + ' الخطوة التالية المقترحة</span>' + esc(lead.next_recommended_action) + '</div>' : ''}
                <a href="/chat/leads" class="p-btn outline xs" style="margin-top:14px;display:inline-block;">عرض كل الـLeads</a>
            </div>
        `;
    }

    async function load() {
        websiteId = P.getCurrentWebsiteId();
        if (!websiteId) {
            document.getElementById('loadingConv').style.display = 'none';
            document.getElementById('convNotFound').style.display = 'block';
            document.getElementById('convNotFound').innerHTML = '<div class="ch-empty-icon">' + UI.icon('globe') + '</div>اختر موقعًا من القائمة أعلى الصفحة أولًا.';
            return;
        }

        if (!customTags.length) {
            await loadCustomTags();
        }

        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId);
        if (!res.success || !res.data || !res.data.conversation) {
            document.getElementById('loadingConv').style.display = 'none';
            document.getElementById('convNotFound').style.display = 'block';
            return;
        }

        currentConversation = res.data.conversation;
        document.getElementById('loadingConv').style.display = 'none';
        document.getElementById('convNotFound').style.display = 'none';
        document.getElementById('convBody').style.display = 'block';

        renderHeader(currentConversation);
        renderThread(res.data.messages || []);

        fetchJSON('/api/ai-chat/websites/' + websiteId + '/leads?conversation_id=' + conversationId).then(leadRes => {
            renderLeadPanel(leadRes.success ? leadRes.data.leads : []);
        });
    }

    load();
    setInterval(load, 20000);
})();
JS;
        $script = str_replace(
            ['__CONVERSATION_ID__', '__CURRENT_USER_ID__'],
            [(string) $conversationId, (string) $currentUserId],
            $script
        );

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('chat', $this->tr('chat.conv.title'), $this->tr('chat.conv.subtitle'), $body, $script);
        exit;
    }

    public function getConversation(array $params): array {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $sessionId = $params['session_id'] ?? $params['id'] ?? null;
        if (!$sessionId) {
            return $this->error('معرف المحادثة مطلوب', 422);
        }

        try {
            $sql = "SELECT * FROM chat_messages WHERE user_id = ? AND session_id = ? ORDER BY created_at ASC";
            $messages = $this->db->query($sql, [$this->user['id'], $sessionId]);
            return $this->success(['messages' => $messages]);
        } catch (Exception $e) {
            Logger::error('Get Conversation Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب المحادثة', 500);
        }
    }

    /** GET /chat/pending */
    public function showPending(array $params = []): array {
        $body = <<<HTML
        <div class="ch-toolbar">
            <a href="/chat" class="p-btn outline xs"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-inline-end:4px;"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>صندوق الوارد</a>
            <div class="ch-toolbar-spacer"></div>
            <span class="ch-pill orange"><span class="ch-pulse"></span> في انتظار موافقتك</span>
        </div>
        <div id="pendingList" class="ch-pending"></div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    const UI = window.ChatUI;

    window.regenerateReply = async function (id) {
        const box = document.getElementById('reply-' + id);
        const btn = document.getElementById('regenBtn-' + id);
        btn.disabled = true;
        const original = btn.textContent;
        btn.innerHTML = 'جاري التوليد...';

        const res = await fetchJSON('/api/chat/generate-reply', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message_id: id }),
        });

        btn.disabled = false;
        btn.textContent = original;

        if (res.success) {
            box.value = res.data.reply;
            toast(__NEW_REPLY_GENERATED__, 'success');
        } else {
            toast(res.error || __GENERATE_FAILED__, 'error');
        }
    };

    window.approveMsg = async function (id, action) {
        const editedReply = action === 'approve' ? document.getElementById('reply-' + id).value.trim() : '';
        if (action === 'approve' && !editedReply) {
            toast(__WRITE_OR_GENERATE_FIRST__, 'error');
            return;
        }
        const res = await fetchJSON('/api/chat/approve', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message_id: id, action: action, edited_reply: editedReply }),
        });
        if (res.success) {
            if (action === 'approve' && res.data && res.data.sent === false) {
                toast(res.data.message || __APPROVED_SEND_FAILED__, 'error');
            } else {
                toast(res.data && res.data.message ? res.data.message : (action === 'approve' ? __APPROVED_SENT__ : __REJECTED__), 'success');
            }
            load();
        }
        else { toast(res.error || __ACTION_FAILED__, 'error'); }
    };

    async function load() {
        const res = await fetchJSON('/api/chat/pending');
        const container = document.getElementById('pendingList');
        if (res.success && Array.isArray(res.data.pending) && res.data.pending.length) {
            container.innerHTML = res.data.pending.map(m => `
                <div class="ch-pending-card">
                    <div class="ch-pending-head">
                        <div class="ch-avatar">${UI.initials(m.customer_name || m.customer_phone || __CUSTOMER__)}</div>
                        <div style="flex:1;min-width:0;">
                            <div class="ch-pending-name">${esc(m.customer_name || m.customer_phone || __CUSTOMER__)}</div>
                            <div class="p-cell-muted" style="font-size:12px;">${esc(m.platform || '-')} · ${formatDate(m.created_at)}</div>
                        </div>
                        <div class="ch-conv-meta">
                            <button class="p-btn success xs" onclick="approveMsg(${m.id}, 'approve')">موافقة وإرسال</button>
                            <button class="p-btn outline xs" id="regenBtn-${m.id}" onclick="regenerateReply(${m.id})">توليد رد جديد</button>
                            <button class="p-btn danger xs" onclick="approveMsg(${m.id}, 'reject')">رفض</button>
                        </div>
                    </div>
                    <div class="ch-pending-quote">
                        <div class="ch-quote-box in">
                            <div class="ch-quote-label">${UI.icon('user', 12)}__CUSTOMER_MESSAGE__</div>
                            <div>${esc(m.message_text || '-')}</div>
                        </div>
                        <div class="ch-quote-box out">
                            <div class="ch-quote-label">${UI.icon('robot', 12)}__SUGGESTED_REPLY__</div>
                            <textarea id="reply-${m.id}" class="form-control">${esc(m.ai_reply_generated || '')}</textarea>
                        </div>
                    </div>
                </div>`).join('');
        } else {
            container.innerHTML = '<div class="ch-empty"><div class="ch-empty-icon">' + UI.icon('check-circle') + '</div><div class="ch-empty-title">__NO_PENDING__</div><div class="ch-empty-sub">كل الرسائل تمت معالجتها.</div></div>';
        }
    }
    load();
})();
JS;
        $script = str_replace(
            [
                '__NEW_REPLY_GENERATED__', '__GENERATE_FAILED__', '__WRITE_OR_GENERATE_FIRST__',
                '__APPROVED_SEND_FAILED__', '__APPROVED_SENT__', '__REJECTED__', '__ACTION_FAILED__', '__CUSTOMER__',
                '__CUSTOMER_MESSAGE__', '__SUGGESTED_REPLY__', '__NO_PENDING__',
            ],
            [
                $this->trJs('chat.pending.new_reply_generated'),
                $this->trJs('chat.pending.generate_failed'),
                $this->trJs('chat.pending.write_or_generate_first'),
                $this->trJs('chat.pending.approved_send_failed'),
                $this->trJs('chat.pending.approved_sent'),
                $this->trJs('chat.pending.rejected'),
                $this->trJs('chat.pending.action_failed'),
                $this->tr('chat.pending.customer'),
                $this->tr('chat.pending.customer_message'),
                $this->tr('chat.pending.suggested_reply'),
                $this->tr('chat.pending.none'),
            ],
            $script
        );

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('chat', $this->tr('chat.pending.title'), $this->tr('chat.pending.subtitle'), $body, $script);
        exit;
    }

    /** GET /chat/settings */
    public function showSettings(array $params = []): array {
        $tSelectWebsite = $this->tr('chat.settings.select_website');
        $tLoadingWebsites = $this->tr('chat.settings.loading_websites');
        $tWhatsappTitle = $this->tr('chat.settings.whatsapp_title');
        $tWhatsappSub = $this->tr('chat.settings.whatsapp_sub');
        $tConnectedInstance = $this->tr('chat.settings.connected_instance');
        $tWebhookUrlHint = $this->tr('chat.settings.webhook_url_hint');
        $tDisconnectLink = $this->tr('chat.settings.disconnect_link');
        $tFreeAccountHint = $this->tr('chat.settings.free_account_hint');
        $tInstanceIdPlaceholder = $this->tr('chat.settings.instance_id_placeholder');
        $tConnectAccount = $this->tr('chat.settings.connect_account');
        $tBotSettings = $this->tr('chat.settings.bot_settings');
        $tEnableBot = $this->tr('chat.settings.enable_bot');
        $tAutoPilot = $this->tr('chat.settings.auto_pilot');
        $tRequiresApproval = $this->tr('chat.settings.requires_approval');
        $tGreetingMsg = $this->tr('chat.settings.greeting_msg');
        $tFallbackMsg = $this->tr('chat.settings.fallback_msg');
        $tReplyLanguage = $this->tr('chat.settings.reply_language');
        $tSaveSettings = $this->tr('chat.settings.save_settings');
        $tNoWebsitesMsg = $this->tr('chat.settings.no_websites_msg');

        $body = <<<HTML
        <div class="ch-toolbar">
            <a href="/chat" class="p-btn outline xs">صندوق الوارد</a>
            <a href="/chat/knowledge-base" class="p-btn outline xs">قاعدة المعرفة</a>
        </div>
        <div class="ch-card">
            <div class="ch-card-head"><span class="ch-card-title">اختر الموقع</span><span class="ch-card-sub">اربط قنوات التواصل وإعدادات البوت لكل موقع على حدة</span></div>
            <div class="ch-card-body ch-form">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" for="websiteSelect">{$tSelectWebsite}</label>
                    <select id="websiteSelect" class="form-control" onchange="loadSettings()">
                        <option value="">{$tLoadingWebsites}</option>
                    </select>
                </div>
            </div>
        </div>
        <div id="ultramsgCard" class="ch-conn whatsapp" style="display:none;">
            <div class="ch-conn-icon"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3z"/><path d="M8.8 8.5c-.3 2.5 4 7.5 6.7 6.7l.9-1.7-2-1.1-1.1.9c-1-.5-2-1.5-2.5-2.5l.9-1.1-1.1-2-1.8.8z"/></svg></div>
            <div class="ch-conn-body">
                <div class="ch-conn-title">{$tWhatsappTitle}</div>
                <div class="ch-conn-desc">{$tWhatsappSub}</div>
                <div id="ultramsgConnected" style="display:none;margin-top:10px;">
                    <div class="ch-hint" style="margin-bottom:10px;">✔ {$tConnectedInstance} <strong id="umInstanceId"></strong></div>
                    <p class="p-cell-muted">{$tWebhookUrlHint}</p>
                    <code id="umWebhookUrl" style="display:block;background:#0B1220;color:#7ee2a8;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
                    <button class="p-btn outline xs" style="margin-top:10px;" onclick="disconnectUltraMsg()">{$tDisconnectLink}</button>
                </div>
                <div id="ultramsgDisconnected" style="display:none;margin-top:10px;">
                    <p class="p-cell-muted">{$tFreeAccountHint} <a href="https://ultramsg.com" target="_blank" rel="noopener">ultramsg.com</a></p>
                    <div class="form-group" style="margin-top:8px;">
                        <input type="text" id="umInstanceInput" class="form-control" placeholder="{$tInstanceIdPlaceholder}" style="margin-bottom:8px;">
                    </div>
                    <div class="form-group">
                        <input type="text" id="umTokenInput" class="form-control" placeholder="API Token">
                    </div>
                    <div id="ultramsgAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
                    <button class="p-btn primary" style="margin-top:10px;" onclick="connectUltraMsg()">{$tConnectAccount}</button>
                </div>
            </div>
        </div>
        <div id="settingsFormCard" class="ch-card" style="display:none;">
            <div class="ch-card-head"><span class="ch-card-title">{$tBotSettings}</span></div>
            <div class="ch-card-body ch-form">
                <div class="form-group">
                    <label class="ch-toggle">
                        <input type="checkbox" id="isEnabled">
                        <span class="ch-toggle-track"></span>
                        <span class="form-label" style="margin:0;">{$tEnableBot}</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="ch-toggle">
                        <input type="checkbox" id="autoPilot">
                        <span class="ch-toggle-track"></span>
                        <span class="form-label" style="margin:0;">{$tAutoPilot}</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="ch-toggle">
                        <input type="checkbox" id="requiresApproval">
                        <span class="ch-toggle-track"></span>
                        <span class="form-label" style="margin:0;">{$tRequiresApproval}</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="form-label" for="greetingMsg">{$tGreetingMsg}</label>
                    <textarea id="greetingMsg" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="fallbackMsg">{$tFallbackMsg}</label>
                    <textarea id="fallbackMsg" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="aiLanguage">{$tReplyLanguage}</label>
                    <select id="aiLanguage" class="form-control">
                        <option value="ar">العربية</option>
                        <option value="en">English</option>
                    </select>
                </div>
                <div id="settingsAlert" class="alert alert-danger" style="display:none;"></div>
                <button class="p-btn primary" onclick="saveSettings()">{$tSaveSettings}</button>
            </div>
        </div>
        <div id="messengerCard" class="ch-conn messenger" style="display:none;">
            <div class="ch-conn-icon"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 9h3l-.5 3H14v9h-3.5v-9H8V9h2.5V7.3c0-2.1 1.3-3.3 3.6-3.3H17V7h-2c-.9 0-1 .4-1 1v1z"/></svg></div>
            <div class="ch-conn-body">
                <div class="ch-conn-title">Messenger</div>
                <div class="ch-conn-desc">اربط صفحة فيسبوك الخاصة بالشركة</div>
                <div id="messengerConnected" style="display:none;margin-top:10px;">
                    <div class="ch-hint" style="margin-bottom:10px;">✔ Messenger مربوط بالفعل</div>
                    <p class="p-cell-muted">Webhook URL:</p>
                    <code id="msgWebhookUrl" style="display:block;background:#0B1220;color:#7ee2a8;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
                    <p class="p-cell-muted" style="margin-top:8px;">Verify Token:</p>
                    <code id="msgVerifyToken" style="display:block;background:#0B1220;color:#7ee2a8;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
                </div>
                <div id="messengerForm" style="margin-top:10px;">
                    <p class="p-cell-muted">أدخل Page Access Token من <a href="https://developers.facebook.com" target="_blank" rel="noopener">Meta for Developers</a>، ثم استخدم الرابط والـVerify Token اللي هيظهرلك لتسجيل الـWebhook هناك.</p>
                    <div class="form-group">
                        <input type="text" id="msgPageId" class="form-control" placeholder="Page ID" style="margin-bottom:8px;">
                    </div>
                    <div class="form-group">
                        <input type="text" id="msgAccessToken" class="form-control" placeholder="Page Access Token">
                    </div>
                    <button class="p-btn primary" style="margin-top:10px;" onclick="connectMessenger()">ربط Messenger</button>
                </div>
            </div>
        </div>
        <div id="instagramCard" class="ch-conn instagram" style="display:none;">
            <div class="ch-conn-icon"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17" cy="7" r="1.2"/></svg></div>
            <div class="ch-conn-body">
                <div class="ch-conn-title">Instagram</div>
                <div class="ch-conn-desc">اربط حساب انستجرام التجاري الخاص بالشركة</div>
                <div id="instagramConnected" style="display:none;margin-top:10px;">
                    <div class="ch-hint" style="margin-bottom:10px;">✔ Instagram مربوط بالفعل</div>
                    <p class="p-cell-muted">Webhook URL:</p>
                    <code id="igWebhookUrl" style="display:block;background:#0B1220;color:#7ee2a8;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
                    <p class="p-cell-muted" style="margin-top:8px;">Verify Token:</p>
                    <code id="igVerifyToken" style="display:block;background:#0B1220;color:#7ee2a8;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
                </div>
                <div id="instagramForm" style="margin-top:10px;">
                    <p class="p-cell-muted">أدخل IG Business Account Access Token من <a href="https://developers.facebook.com" target="_blank" rel="noopener">Meta for Developers</a>.</p>
                    <div class="form-group">
                        <input type="text" id="igAccountId" class="form-control" placeholder="Instagram Business Account ID" style="margin-bottom:8px;">
                    </div>
                    <div class="form-group">
                        <input type="text" id="igAccessToken" class="form-control" placeholder="Access Token">
                    </div>
                    <button class="p-btn primary" style="margin-top:10px;" onclick="connectInstagram()">ربط Instagram</button>
                </div>
            </div>
        </div>
        <div id="emailCard" class="ch-conn webchat" style="display:none;">
            <div class="ch-conn-icon"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3 7l9 6 9-6"/></svg></div>
            <div class="ch-conn-body">
                <div class="ch-conn-title">الإيميل</div>
                <div class="ch-conn-desc">استقبال استفسارات العملاء عبر البريد الإلكتروني</div>
                <p class="p-cell-muted" style="margin-top:10px;">قناة الإيميل بترسل الردود عبر إعدادات البريد العامة للمنصة (مفيش Access Token منفصل لكل موقع). لاستقبال الرسائل، وجّه مزود البريد الوارد (SendGrid Inbound Parse، Mailgun Routes، أو ما يعادلهم) للرابط تحت:</p>
                <p class="p-cell-muted" style="margin-top:8px;">Webhook URL:</p>
                <code id="emailWebhookUrl" style="display:block;background:#0B1220;color:#7ee2a8;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;">جاري التحميل...</code>
                <div id="emailMailerWarning" class="alert alert-danger" style="display:none;margin-top:10px;">⚠️ إعدادات إرسال البريد (Mailer) للمنصة غير مُفعّلة حاليًا - الاستقبال هيشتغل لكن الردود لن تُرسَل للعميل حتى تُضبَط.</div>
            </div>
        </div>
        <div class="ch-card" id="noWebsitesCard" style="display:none;">
            <div class="ch-empty"><div class="ch-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg></div>{$tNoWebsitesMsg}</div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    let websites = [];

    window.loadSettings = async function () {
        const websiteId = document.getElementById('websiteSelect').value;
        if (!websiteId) {
            document.getElementById('settingsFormCard').style.display = 'none';
            document.getElementById('ultramsgCard').style.display = 'none';
            document.getElementById('messengerCard').style.display = 'none';
            document.getElementById('instagramCard').style.display = 'none';
            document.getElementById('emailCard').style.display = 'none';
            return;
        }

        document.getElementById('ultramsgCard').style.display = 'block';
        await loadUltraMsgStatus(websiteId);
        document.getElementById('messengerCard').style.display = 'block';
        document.getElementById('messengerForm').style.display = 'block';
        document.getElementById('messengerConnected').style.display = 'none';
        document.getElementById('instagramCard').style.display = 'block';
        document.getElementById('instagramForm').style.display = 'block';
        document.getElementById('instagramConnected').style.display = 'none';
        loadChannelStatus(websiteId, 'messenger');
        loadChannelStatus(websiteId, 'instagram');
        document.getElementById('emailCard').style.display = 'block';
        loadEmailChannelInfo(websiteId);

        const res = await fetchJSON('/api/chat/settings?website_id=' + websiteId + '&platform=all');
        document.getElementById('settingsFormCard').style.display = 'block';
        if (!res.success) { toast(res.error || __LOAD_SETTINGS_FAILED__, 'error'); return; }

        const s = res.data.settings || {};
        document.getElementById('isEnabled').checked = !!(s.is_enabled == 1);
        document.getElementById('autoPilot').checked = !!(s.auto_pilot == 1);
        document.getElementById('requiresApproval').checked = !!(s.requires_approval == 1);
        document.getElementById('greetingMsg').value = s.greeting_message || '';
        document.getElementById('fallbackMsg').value = s.fallback_message || '';
        document.getElementById('aiLanguage').value = s.ai_language || 'ar';
    };

    async function loadUltraMsgStatus(websiteId) {
        const res = await fetchJSON('/api/chat/ultramsg/status?website_id=' + websiteId);
        const connectedBox = document.getElementById('ultramsgConnected');
        const disconnectedBox = document.getElementById('ultramsgDisconnected');

        if (res.success && res.data.connected) {
            connectedBox.style.display = 'block';
            disconnectedBox.style.display = 'none';
            document.getElementById('umInstanceId').textContent = res.data.instance_id || '';
            document.getElementById('umWebhookUrl').textContent = res.data.webhook_url || '';
        } else {
            connectedBox.style.display = 'none';
            disconnectedBox.style.display = 'block';
        }
    }

    window.connectUltraMsg = async function () {
        const websiteId = document.getElementById('websiteSelect').value;
        const instanceId = document.getElementById('umInstanceInput').value.trim();
        const apiKey = document.getElementById('umTokenInput').value.trim();
        const alertBox = document.getElementById('ultramsgAlert');
        alertBox.style.display = 'none';

        if (!instanceId || !apiKey) { toast(__WRITE_INSTANCE_TOKEN__, 'error'); return; }

        const res = await fetchJSON('/api/chat/connect/ultramsg', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: websiteId, instance_id: instanceId, api_key: apiKey }),
        });

        if (res.success) {
            toast(__CONNECTED_SUCCESS__, 'success');
            loadUltraMsgStatus(websiteId);
        } else {
            alertBox.textContent = res.error || __CONNECT_FAILED__;
            alertBox.style.display = 'block';
        }
    };

    window.disconnectUltraMsg = async function () {
        if (!confirm(__DISCONNECT_CONFIRM__)) return;
        const websiteId = document.getElementById('websiteSelect').value;
        const res = await fetchJSON('/api/chat/disconnect/ultramsg/' + websiteId, { method: 'POST' });
        if (res.success) { toast(__DISCONNECTED__, 'success'); loadUltraMsgStatus(websiteId); }
        else { toast(res.error || __DISCONNECT_FAILED__, 'error'); }
    };

    window.connectMessenger = async function () {
        const websiteId = document.getElementById('websiteSelect').value;
        const pageId = document.getElementById('msgPageId').value.trim();
        const accessToken = document.getElementById('msgAccessToken').value.trim();
        if (!accessToken) { toast('اكتب Access Token أولاً', 'error'); return; }

        const res = await fetchJSON('/api/chat/connect/messenger', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: websiteId, access_token: accessToken, page_id: pageId }),
        });

        if (res.success) {
            toast('تم ربط Messenger - سجّل الـWebhook تحت في Meta for Developers', 'success');
            document.getElementById('messengerForm').style.display = 'none';
            document.getElementById('messengerConnected').style.display = 'block';
            document.getElementById('msgWebhookUrl').textContent = res.data.webhook_url || '';
            document.getElementById('msgVerifyToken').textContent = res.data.verify_token || '';
        } else {
            toast(res.error || 'فشل الربط', 'error');
        }
    };

    window.connectInstagram = async function () {
        const websiteId = document.getElementById('websiteSelect').value;
        const accountId = document.getElementById('igAccountId').value.trim();
        const accessToken = document.getElementById('igAccessToken').value.trim();
        if (!accessToken) { toast('اكتب Access Token أولاً', 'error'); return; }

        const res = await fetchJSON('/api/chat/connect/instagram', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: websiteId, access_token: accessToken, page_id: accountId }),
        });

        if (res.success) {
            toast('تم ربط Instagram - سجّل الـWebhook تحت في Meta for Developers', 'success');
            document.getElementById('instagramForm').style.display = 'none';
            document.getElementById('instagramConnected').style.display = 'block';
            document.getElementById('igWebhookUrl').textContent = res.data.webhook_url || '';
            document.getElementById('igVerifyToken').textContent = res.data.verify_token || '';
        } else {
            toast(res.error || 'فشل الربط', 'error');
        }
    };

    async function loadEmailChannelInfo(websiteId) {
        const res = await fetchJSON('/api/chat/email-channel-info?website_id=' + websiteId);
        if (!res.success) {
            document.getElementById('emailWebhookUrl').textContent = (res.error || 'تعذر التحميل');
            return;
        }
        document.getElementById('emailWebhookUrl').textContent = res.data.webhook_url || '';
        document.getElementById('emailMailerWarning').style.display = res.data.mailer_configured ? 'none' : 'block';
    }

    async function loadChannelStatus(websiteId, platform) {
        const res = await fetchJSON('/api/chat/channel-status?website_id=' + websiteId + '&platform=' + platform);
        if (!res.success || !res.data.connected) return;

        const prefix = platform === 'messenger' ? 'msg' : 'ig';
        document.getElementById(platform + 'Form').style.display = 'none';
        document.getElementById(platform + 'Connected').style.display = 'block';
        document.getElementById(prefix + 'WebhookUrl').textContent = res.data.webhook_url || '';
        document.getElementById(prefix + 'VerifyToken').textContent = res.data.verify_token || '';
    }

    window.saveSettings = async function () {
        const websiteId = document.getElementById('websiteSelect').value;
        const alertBox = document.getElementById('settingsAlert');
        alertBox.style.display = 'none';

        const settings = {
            is_enabled: document.getElementById('isEnabled').checked ? 1 : 0,
            auto_pilot: document.getElementById('autoPilot').checked ? 1 : 0,
            requires_approval: document.getElementById('requiresApproval').checked ? 1 : 0,
            greeting_message: document.getElementById('greetingMsg').value,
            fallback_message: document.getElementById('fallbackMsg').value,
            ai_language: document.getElementById('aiLanguage').value,
        };

        const res = await fetchJSON('/api/chat/settings', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: websiteId, platform: 'all', settings: settings }),
        });

        if (res.success) {
            toast(__SETTINGS_SAVED__, 'success');
        } else {
            alertBox.textContent = res.error || __SAVE_SETTINGS_FAILED__;
            alertBox.style.display = 'block';
        }
    };

    async function init() {
        const res = await fetchJSON('/api/websites');
        const select = document.getElementById('websiteSelect');
        websites = (res.success && Array.isArray(res.data.websites)) ? res.data.websites : [];

        if (!websites.length) {
            document.getElementById('noWebsitesCard').style.display = 'block';
            select.innerHTML = '<option value="">__NO_WEBSITES__</option>';
            return;
        }

        select.innerHTML = '<option value="">__CHOOSE_WEBSITE__</option>' + websites.map(w => `<option value="${w.id}">${esc(w.main_url || w.company_name || (__WEBSITE_HASH__ + w.id))}</option>`).join('');
        window.Panel.syncWebsiteSelect('websiteSelect');
        if (select.value) loadSettings();
    }
    init();
})();
JS;
        $script = str_replace(
            [
                '__LOAD_SETTINGS_FAILED__', '__WRITE_INSTANCE_TOKEN__', '__CONNECTED_SUCCESS__', '__CONNECT_FAILED__',
                '__DISCONNECT_CONFIRM__', '__DISCONNECTED__', '__DISCONNECT_FAILED__', '__SETTINGS_SAVED__',
                '__SAVE_SETTINGS_FAILED__', '__NO_WEBSITES__', '__CHOOSE_WEBSITE__', '__WEBSITE_HASH__',
            ],
            [
                $this->trJs('chat.settings.load_failed'),
                $this->trJs('chat.settings.write_instance_token'),
                $this->trJs('chat.settings.connected_success'),
                $this->trJs('chat.settings.connect_failed'),
                $this->trJs('chat.settings.disconnect_confirm'),
                $this->trJs('common.disconnected'),
                $this->trJs('common.disconnect_failed'),
                $this->trJs('common.saved'),
                $this->trJs('chat.settings.save_settings_failed'),
                $this->trJs('chat.settings.no_websites'),
                $this->trJs('chat.settings.choose_website'),
                $this->trJs('chat.settings.website_hash'),
            ],
            $script
        );

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('chat', $this->tr('chat.settings.title'), $this->tr('chat.settings.subtitle'), $body, $script);
        exit;
    }

    /**
     * GET /chat/knowledge-base
     *
     * واجهة إدارة قاعدة معرفة الشركة (بند 4 + 13 Brand Voice) - أول
     * واجهة إدارية حقيقية لـAI Chat Backend. من غيرها، صاحب الشركة
     * (غير التقني) معندوش أي طريقة يدخّل بيها معلومات شركته/خدماته/
     * أسعاره غير عن طريق استدعاء الـAPI مباشرة.
     *
     * تستخدم الـEndpoints الموجودة بالفعل من المرحلة 1 حرفيًا
     * (AiKnowledgeBaseController: index/store/update/destroy/preview) -
     * صفر Endpoint جديد.
     */
    public function showKnowledgeBase(array $params = []): array {
        $body = <<<'HTML'
        <div class="ch-toolbar">
            <a href="/chat" class="p-btn outline xs">صندوق الوارد</a>
            <div class="ch-toolbar-spacer"></div>
            <button class="p-btn outline xs" onclick="kbPreview()">معاينة السياق المُرسَل للـAI</button>
        </div>

        <div id="kbNoWebsite" class="ch-card" style="display:none;">
            <div class="ch-empty"><div class="ch-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg></div><div class="ch-empty-title">اختر موقعًا</div><div class="ch-empty-sub">اختر موقعًا من القائمة أعلى الصفحة أولًا.</div></div>
        </div>

        <div id="kbBody" style="display:none;">
            <div class="ch-card">
                <div class="ch-card-head"><span class="ch-card-title">نبرة الشركة</span><span class="ch-card-sub">تُستخدم في كل ردود الذكاء الاصطناعي</span></div>
                <div class="ch-card-body ch-form">
                    <div class="form-group">
                        <label class="form-label">النبرة</label>
                        <select id="bvTone" class="form-control">
                            <option value="professional">احترافية</option>
                            <option value="friendly">ودّية</option>
                            <option value="luxury">فاخرة</option>
                            <option value="casual">غير رسمية</option>
                            <option value="formal">رسمية جدًا</option>
                            <option value="sales_focused">مُركّزة على البيع</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">تعليمات إضافية (اختياري)</label>
                        <textarea id="bvInstructions" class="form-control" rows="2" placeholder="مثال: دائمًا اذكر سياسة الإلغاء المجاني قبل 48 ساعة"></textarea>
                    </div>
                    <button class="p-btn primary xs" onclick="kbSaveBrandVoice()">حفظ نبرة الشركة</button>
                </div>
            </div>

            <div class="ch-card">
                <div class="ch-card-head"><span class="ch-card-title" id="kbFormTitle">إضافة معلومة جديدة</span><span class="ch-card-spacer"></span><span class="ch-card-sub">تضيفها هنا مرة واحدة، والـAI هيرجع لها في كل محادثة</span></div>
                <div class="ch-card-body ch-form">
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1;min-width:180px;">
                            <label class="form-label">القسم</label>
                            <select id="kbSection" class="form-control">
                                <option value="company_info">معلومات الشركة</option>
                                <option value="service">خدمة</option>
                                <option value="tour">رحلة/جولة</option>
                                <option value="destination">وجهة</option>
                                <option value="pricing">سعر</option>
                                <option value="faq">سؤال شائع</option>
                                <option value="policy">سياسة</option>
                                <option value="cancellation_policy">سياسة الإلغاء</option>
                                <option value="contact_info">بيانات التواصل</option>
                                <option value="business_hours">ساعات العمل</option>
                                <option value="custom_instructions">تعليمات مخصصة</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1;min-width:180px;">
                            <label class="form-label">اللغة</label>
                            <select id="kbLanguage" class="form-control">
                                <option value="ar">العربية</option>
                                <option value="en">English</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">العنوان (اختياري، مثال: اسم الرحلة أو نص السؤال)</label>
                        <input type="text" id="kbTitle" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">المحتوى</label>
                        <textarea id="kbContent" class="form-control" rows="3" placeholder="اكتب المعلومة كاملة وواضحة - الذكاء الاصطناعي هيعتمد على النص ده حرفيًا"></textarea>
                    </div>
                    <button class="p-btn primary" id="kbAddBtn" onclick="kbAddEntry()">إضافة</button>
                    <button class="p-btn outline" id="kbCancelBtn" style="display:none;" onclick="kbCancelEdit()">إلغاء التعديل</button>
                </div>
            </div>

            <div id="kbSectionsContainer"></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    const UI = window.ChatUI;

    const SECTION_LABELS = {
        company_info: 'معلومات الشركة', service: 'الخدمات', tour: 'الرحلات/الجولات',
        destination: 'الوجهات', pricing: 'الأسعار', faq: 'الأسئلة الشائعة',
        policy: 'السياسات', cancellation_policy: 'سياسة الإلغاء',
        contact_info: 'بيانات التواصل', business_hours: 'ساعات العمل',
        custom_instructions: 'تعليمات مخصصة',
    };

    function websiteId() { return P.getCurrentWebsiteId(); }

    function ensureWebsite() {
        const id = websiteId();
        document.getElementById('kbNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('kbBody').style.display = id ? 'block' : 'none';
        return id;
    }

    let editingEntryId = null;

    window.kbAddEntry = async function () {
        const id = ensureWebsite();
        if (!id) return;
        const section = document.getElementById('kbSection').value;
        const language = document.getElementById('kbLanguage').value;
        const title = document.getElementById('kbTitle').value.trim();
        const content = document.getElementById('kbContent').value.trim();
        if (!content) { toast('اكتب المحتوى أولاً', 'error'); return; }

        const isEdit = editingEntryId !== null;
        const url = '/api/ai-chat/websites/' + id + '/knowledge-base' + (isEdit ? '/' + editingEntryId : '');
        const res = await fetchJSON(url, {
            method: isEdit ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(isEdit
                ? { title: title || null, content: content, language: language }
                : { section: section, language: language, title: title || null, content: content }),
        });

        if (res.success) {
            toast(isEdit ? 'تم التحديث' : 'تمت الإضافة', 'success');
            kbCancelEdit();
            load();
        } else {
            toast(res.error || (isEdit ? 'فشل التحديث' : 'فشلت الإضافة'), 'error');
        }
    };

    window.kbEditFromMap = function (entryId) {
        const e = window.kbEntriesById[entryId];
        if (!e) return;
        kbEditEntry(entryId, e.section, e.language, e.title, e.content);
    };

    window.kbEditEntry = function (entryId, section, language, title, content) {
        editingEntryId = entryId;
        document.getElementById('kbSection').value = section;
        document.getElementById('kbSection').disabled = true;
        document.getElementById('kbLanguage').value = language;
        document.getElementById('kbTitle').value = title || '';
        document.getElementById('kbContent').value = content || '';
        document.getElementById('kbFormTitle').textContent = 'تعديل معلومة';
        document.getElementById('kbAddBtn').textContent = 'حفظ التعديل';
        document.getElementById('kbCancelBtn').style.display = 'inline-block';
        document.getElementById('kbContent').scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    window.kbCancelEdit = function () {
        editingEntryId = null;
        document.getElementById('kbSection').disabled = false;
        document.getElementById('kbTitle').value = '';
        document.getElementById('kbContent').value = '';
        document.getElementById('kbFormTitle').textContent = 'إضافة معلومة جديدة';
        document.getElementById('kbAddBtn').textContent = 'إضافة';
        document.getElementById('kbCancelBtn').style.display = 'none';
    };

    window.kbDeleteEntry = async function (entryId) {
        const id = websiteId();
        if (!confirm('تأكيد حذف هذه المعلومة؟')) return;
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base/' + entryId, { method: 'DELETE' });
        if (res.success) { toast('تم الحذف', 'success'); load(); }
        else { toast(res.error || 'فشل الحذف', 'error'); }
    };

    window.kbSaveBrandVoice = async function () {
        const id = ensureWebsite();
        if (!id) return;
        const tone = document.getElementById('bvTone').value;
        const instructions = document.getElementById('bvInstructions').value.trim();

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ section: 'brand_voice', tone: tone, content: instructions || null }),
        });

        if (res.success) { toast('تم حفظ نبرة الشركة', 'success'); }
        else { toast(res.error || 'فشل الحفظ', 'error'); }
    };

    window.kbPreview = async function () {
        const id = websiteId();
        if (!id) { toast('اختر موقعًا أولاً', 'error'); return; }
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base/preview');
        if (!res.success) { toast(res.error || 'فشلت المعاينة', 'error'); return; }
        alert(res.data.context_preview || 'لا يوجد محتوى بعد');
    };

    async function load() {
        const id = ensureWebsite();
        if (!id) return;

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base');
        const container = document.getElementById('kbSectionsContainer');

        if (!res.success) {
            container.innerHTML = '<div class="ch-card"><div class="ch-empty">' + UI.icon('alert') + ' ' + esc(res.error || 'تعذر التحميل') + '</div></div>';
            return;
        }

        if (res.data.brand_voice) {
            document.getElementById('bvTone').value = res.data.brand_voice.tone || 'professional';
            document.getElementById('bvInstructions').value = res.data.brand_voice.custom_instructions || '';
        }

        const sections = res.data.sections || {};
        const sectionKeys = Object.keys(sections);
        if (!sectionKeys.length) {
            container.innerHTML = '<div class="ch-card"><div class="ch-empty"><div class="ch-empty-icon">' + UI.icon('book') + '</div><div class="ch-empty-title">قاعدة المعرفة فاضية</div><div class="ch-empty-sub">أضف أول معلومة من الفورم أعلاه - من غيرها الذكاء الاصطناعي مش هيقدر يجاوب على أي سؤال محدد عن شركتك.</div></div></div>';
            return;
        }

        window.kbEntriesById = window.kbEntriesById || {};
        container.innerHTML = sectionKeys.map(section => {
            const entries = sections[section];
            const rows = entries.map(e => {
                window.kbEntriesById[e.id] = { section: section, language: e.language, title: e.title, content: e.content };
                return `
                <div class="p-kv" style="align-items:flex-start;padding:12px 0;">
                    <span class="k" style="max-width:70%;">
                        ${e.title ? '<strong>' + esc(e.title) + '</strong><br>' : ''}
                        ${esc(e.content || '')}
                        <span class="p-cell-muted"> · ${e.language === 'en' ? 'EN' : 'AR'}</span>
                    </span>
                    <span style="white-space:nowrap;">
                        <button class="p-btn outline xs" onclick="kbEditFromMap(${e.id})">تعديل</button>
                        <button class="p-btn danger xs" onclick="kbDeleteEntry(${e.id})">حذف</button>
                    </span>
                </div>`;
            }).join('');
            return `
                <div class="ch-card">
                    <div class="ch-card-head"><span class="ch-card-title">${SECTION_LABELS[section] || esc(section)}</span><span class="ch-card-sub">${entries.length} عنصر</span></div>
                    <div class="ch-card-body no-pad" style="padding:0 18px;">${rows}</div>
                </div>`;
        }).join('');
    }

    window.addEventListener('tourfecto:website-changed', load);
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('chat', $this->tr('chat.kb.title'), $this->tr('chat.kb.subtitle'), $body, $script);
        exit;
    }

    /**
     * GET /chat/followup-settings
     * واجهة إعدادات المتابعة التلقائية (بند 7) - تستخدم Endpoint موجود
     * من المرحلة 3 (AiFollowupSettingsController) - صفر Endpoint جديد.
     */
    public function showFollowupSettings(array $params = []): array {
        $body = <<<'HTML'
        <div class="ch-toolbar">
            <a href="/chat" class="p-btn outline xs">صندوق الوارد</a>
        </div>
        <div id="fuNoWebsite" class="ch-card" style="display:none;">
            <div class="ch-empty"><div class="ch-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg></div><div class="ch-empty-title">اختر موقعًا</div><div class="ch-empty-sub">اختر موقعًا من القائمة أعلى الصفحة أولًا.</div></div>
        </div>
        <div id="fuBody" style="display:none;">
            <div class="ch-card">
                <div class="ch-card-head"><span class="ch-card-title">المتابعة التلقائية</span><span class="ch-card-sub">لو العميل سأل ثم اختفى، النظام يقدر يبعتله متابعة تلقائية حسب الخطوات تحت</span></div>
                <div class="ch-card-body ch-form">
                    <div class="form-group">
                        <label class="ch-toggle">
                            <input type="checkbox" id="fuEnabled">
                            <span class="ch-toggle-track"></span>
                            <span class="form-label" style="margin:0;">تفعيل المتابعة التلقائية لهذا الموقع</span>
                        </label>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">الحد الأقصى لعدد المتابعات لكل عميل</label>
                        <input type="number" id="fuMax" class="form-control" min="1" max="10" style="max-width:120px;">
                    </div>
                </div>
            </div>
            <div class="ch-card">
                <div class="ch-card-head"><span class="ch-card-title">خطوات المتابعة</span><span class="ch-card-spacer"></span><button class="p-btn outline xs" onclick="fuAddStep()">إضافة خطوة</button></div>
                <div class="ch-card-body">
                    <div id="fuSteps"></div>
                </div>
            </div>
            <button class="p-btn primary" onclick="fuSave()">حفظ الإعدادات</button>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    const UI = window.ChatUI;
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
            container.innerHTML = '<div class="ch-empty" style="padding:28px 16px;"><div class="ch-empty-icon">' + UI.icon('clock') + '</div><div class="ch-empty-sub">مفيش خطوات لسه - أضف خطوة عشان المتابعة التلقائية تشتغل.</div></div>';
            return;
        }
        container.innerHTML = '<div class="ch-steps">' + steps.map((s, i) => `
            <div class="ch-step">
                <div class="ch-step-num">${i + 1}</div>
                <div class="ch-step-body">
                    <div class="ch-step-row">
                        <strong>بعد</strong>
                        <input type="number" class="form-control" style="max-width:90px;" value="${s.after_hours}" onchange="fuUpdateStep(${i}, 'after_hours', this.value)">
                        <span class="p-cell-muted">ساعة من آخر رسالة للعميل</span>
                        <div style="flex:1;"></div>
                        <button class="p-btn danger xs" onclick="fuRemoveStep(${i})">حذف</button>
                    </div>
                    <div class="form-group" style="margin-top:10px;margin-bottom:0;">
                        <label class="form-label">نص الرسالة (استخدم {name} لاسم العميل)</label>
                        <textarea class="form-control" rows="2" onchange="fuUpdateStep(${i}, 'template', this.value)">${esc(s.template || '')}</textarea>
                    </div>
                </div>
            </div>`).join('') + '</div>';
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
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('chat', $this->tr('chat.followup.title'), $this->tr('chat.followup.subtitle'), $body, $script);
        exit;
    }

    /**
     * GET /chat/analytics
     * لوحة تحليلات AI Chat (بند 18) - تستخدم Endpoint موجود من المرحلة 4
     * (AiAnalyticsController) - صفر Endpoint جديد.
     */
    public function showAnalytics(array $params = []): array {
        $body = <<<'HTML'
        <div class="ch-toolbar">
            <a href="/chat" class="p-btn outline xs">صندوق الوارد</a>
            <div class="ch-toolbar-spacer"></div>
            <select id="anSince" class="p-select" onchange="load()">
                <option value="7">آخر 7 أيام</option>
                <option value="30" selected>آخر 30 يوم</option>
                <option value="90">آخر 90 يوم</option>
            </select>
        </div>
        <div id="anNoWebsite" class="ch-card" style="display:none;">
            <div class="ch-empty"><div class="ch-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg></div><div class="ch-empty-title">اختر موقعًا</div><div class="ch-empty-sub">اختر موقعًا من القائمة أعلى الصفحة أولًا.</div></div>
        </div>
        <div id="anBody" style="display:none;">
            <div id="anStats" class="ch-stats"></div>
            <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px;">
                <div class="ch-card" style="flex:1;min-width:260px;margin-bottom:0;">
                    <div class="ch-card-head"><span class="ch-card-title">أكثر الوسوم تكرارًا</span></div>
                    <div class="ch-card-body" id="anTags"></div>
                </div>
                <div class="ch-card" style="flex:1;min-width:260px;margin-bottom:0;">
                    <div class="ch-card-head"><span class="ch-card-title">أكثر الخدمات طلبًا</span></div>
                    <div class="ch-card-body" id="anServices"></div>
                </div>
            </div>
            <div class="ch-card">
                <div class="ch-card-head"><span class="ch-card-title">استخدام مزودي الذكاء الاصطناعي</span></div>
                <div class="ch-card-body no-pad" id="anProvidersWrap">
                    <div class="p-table-scroll"><table class="p-table" id="anProviders">
                        <thead><tr><th>المزود</th><th>عدد الطلبات</th><th>عدد الرموز (Tokens)</th><th>التكلفة التقديرية</th><th>طلبات فاشلة</th></tr></thead>
                        <tbody></tbody>
                    </table></div>
                </div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON;
    const UI = window.ChatUI;

    function websiteId() { return P.getCurrentWebsiteId(); }

    function ensureWebsite() {
        const id = websiteId();
        document.getElementById('anNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('anBody').style.display = id ? 'block' : 'none';
        return id;
    }

    function statTile(label, value, icon, variant) {
        return `<div class="ch-stat ${variant || ''}">
            <span class="ch-stat-icon">${UI.icon(icon || 'bar-chart', 16)}</span>
            <div class="ch-stat-value">${value}</div>
            <div class="ch-stat-label">${label}</div>
        </div>`;
    }

    window.load = async function () {
        const id = ensureWebsite();
        if (!id) return;
        const days = document.getElementById('anSince').value;
        const since = new Date(Date.now() - days * 86400000).toISOString().slice(0, 10);

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/analytics?since=' + since);
        if (!res.success) {
            document.getElementById('anStats').innerHTML = '<div class="ch-card" style="grid-column:1/-1;"><div class="ch-empty">' + UI.icon('alert') + ' ' + esc(res.error || 'تعذر التحميل') + '</div></div>';
            return;
        }

        const d = res.data.dashboard;
        document.getElementById('anStats').innerHTML = [
            statTile('إجمالي المحادثات', d.total_conversations, 'chat', ''),
            statTile('ردّ الذكاء الاصطناعي', d.ai_conversations, 'robot', 'teal'),
            statTile('تحويل لموظف', d.human_conversations, 'user', 'purple'),
            statTile('Leads جديدة', d.leads_generated, 'target', ''),
            statTile('Leads ساخنة', d.hot_leads, 'flame', 'red'),
            statTile('نسبة التحويل', d.conversion_rate_percent + '%', 'trend-up', 'teal'),
            statTile('معدّل حل الذكاء الاصطناعي', d.ai_resolution_rate_percent + '%', 'shield', ''),
            statTile('تحويل لموظف', d.human_handoff_rate_percent + '%', 'users', 'purple'),
            statTile('نجاح المتابعات', d.followup_success_rate_percent + '%', 'check-circle', 'teal'),
        ].join('');

        const tags = d.top_tags || {};
        const tagKeys = Object.keys(tags);
        const tagMax = tagKeys.length ? Math.max.apply(null, tagKeys.map(k => tags[k])) : 1;
        document.getElementById('anTags').innerHTML = tagKeys.length
            ? '<div class="ch-rank">' + tagKeys.map(t => `<div class="ch-rank-item">
                <div class="ch-rank-label"><span>${esc(t)}</span><span>${tags[t]}</span></div>
                ${UI.rankBar(tags[t], tagMax)}
            </div>`).join('') + '</div>'
            : '<div class="ch-empty" style="padding:20px;"><div class="ch-empty-sub">لا توجد بيانات كافية بعد</div></div>';

        const services = d.most_popular_services || {};
        const serviceKeys = Object.keys(services);
        const svcMax = serviceKeys.length ? Math.max.apply(null, serviceKeys.map(s => services[s])) : 1;
        document.getElementById('anServices').innerHTML = serviceKeys.length
            ? '<div class="ch-rank">' + serviceKeys.map(s => `<div class="ch-rank-item">
                <div class="ch-rank-label"><span>${esc(s)}</span><span>${services[s]}</span></div>
                ${UI.rankBar(services[s], svcMax)}
            </div>`).join('') + '</div>'
            : '<div class="ch-empty" style="padding:20px;"><div class="ch-empty-sub">لا توجد بيانات كافية بعد</div></div>';

        const providers = d.ai_usage_by_provider || [];
        const tbody = document.querySelector('#anProviders tbody');
        tbody.innerHTML = providers.length
            ? providers.map(p => `<tr>
                <td>${esc(p.provider)}</td><td>${p.total_requests}</td><td>${p.total_tokens || 0}</td>
                <td>$${parseFloat(p.total_cost_usd || 0).toFixed(4)}</td><td>${p.failed_requests || 0}</td>
            </tr>`).join('')
            : '<tr><td colspan="5" class="p-cell-muted text-center">لا يوجد استخدام مسجَّل بعد</td></tr>';
    };

    window.addEventListener('tourfecto:website-changed', load);
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('chat', $this->tr('chat.analytics.title'), $this->tr('chat.analytics.subtitle'), $body, $script);
        exit;
    }

    /**
     * GET /chat/leads
     * قائمة Leads مستقلة (بند 5، 6) - مرتّبة حسب Lead Score، قابلة
     * للفلترة بالحالة. تستخدم Endpoint موجود من المرحلة 3
     * (AiLeadController) - صفر Endpoint جديد.
     */
    public function showLeads(array $params = []): array {
        $body = <<<'HTML'
        <div class="ch-toolbar">
            <a href="/chat" class="p-btn outline xs">صندوق الوارد</a>
            <div class="ch-toolbar-spacer"></div>
            <select id="ldStatus" class="p-select" onchange="load()">
                <option value="">كل الحالات</option>
                <option value="new">جديد</option>
                <option value="contacted">تم التواصل</option>
                <option value="qualified">مؤهّل</option>
                <option value="proposal_sent">تم إرسال عرض سعر</option>
                <option value="won">تم الفوز به</option>
                <option value="lost">فاقد</option>
            </select>
        </div>
        <div id="ldNoWebsite" class="ch-card" style="display:none;">
            <div class="ch-empty"><div class="ch-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg></div><div class="ch-empty-title">اختر موقعًا</div><div class="ch-empty-sub">اختر موقعًا من القائمة أعلى الصفحة أولًا.</div></div>
        </div>
        <div class="ch-card" id="ldListWrap" style="display:none;">
            <div class="ch-inbox" id="leadsList"></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    const UI = window.ChatUI;

    const STATUS_OPTIONS = [
        ['new', 'جديد'], ['contacted', 'تم التواصل'], ['qualified', 'مؤهّل'],
        ['proposal_sent', 'تم إرسال عرض سعر'], ['won', 'تم الفوز به'], ['lost', 'فاقد'],
    ];
    const STATUS_CHIP = {
        new: ['جديد', 'blue'], contacted: ['تم التواصل', ''],
        qualified: ['مؤهّل', 'green'], proposal_sent: ['عرض سعر', 'orange'],
        won: ['فوز', 'green'], lost: ['فاقد', 'red'],
    };

    function websiteId() { return P.getCurrentWebsiteId(); }

    function ensureWebsite() {
        const id = websiteId();
        document.getElementById('ldNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('ldListWrap').style.display = id ? 'block' : 'none';
        return id;
    }

    window.ldUpdateStatus = async function (leadId, status) {
        const id = websiteId();
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/leads/' + leadId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: status }),
        });
        if (res.success) toast('تم تحديث الحالة', 'success');
        else { toast(res.error || 'فشل التحديث', 'error'); load(); }
    };

    window.load = async function () {
        const id = ensureWebsite();
        if (!id) return;

        const status = document.getElementById('ldStatus').value;
        const qs = status ? ('?status=' + encodeURIComponent(status)) : '';

        const list = document.getElementById('leadsList');
        list.innerHTML = '<div class="ch-skeleton-row"><div class="ch-skeleton avatar"></div><div class="ch-skeleton line"></div></div><div class="ch-skeleton-row"><div class="ch-skeleton avatar"></div><div class="ch-skeleton line"></div></div><div class="ch-skeleton-row"><div class="ch-skeleton avatar"></div><div class="ch-skeleton line"></div></div>';

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/leads' + qs);
        if (!res.success) {
            list.innerHTML = '<div class="ch-empty"><div class="ch-empty-icon">' + UI.icon('alert') + '</div><div class="ch-empty-title">تعذر التحميل</div><div class="ch-empty-sub">' + esc(res.error || 'تعذر التحميل') + '</div></div>';
            return;
        }

        const leads = res.data.leads || [];
        if (!leads.length) {
            list.innerHTML = '<div class="ch-empty"><div class="ch-empty-icon">' + UI.icon('target') + '</div><div class="ch-empty-title">لا توجد Leads بعد</div><div class="ch-empty-sub">الـLeads هتظهر هنا أول ما يطلب العملاء استفسارات.</div></div>';
            return;
        }

        list.innerHTML = leads.map(l => {
            const statusSelect = '<select class="p-select" style="min-width:120px;font-size:12.5px;" onchange="ldUpdateStatus(' + l.id + ', this.value)">' +
                STATUS_OPTIONS.map(([v, label]) => `<option value="${v}" ${l.status === v ? 'selected' : ''}>${label}</option>`).join('') + '</select>';
            const channelMeta = UI.channelMeta[l.channel] || { icon: 'chat', avatar: '' };
            const statusChip = STATUS_CHIP[l.status];
            const score = Number(l.lead_score || 0);
            const scoreLabel = score >= 70 ? 'red' : score >= 40 ? 'gold' : '';
            return `
                <div class="ch-conv" onclick="window.location.href='/chat/conversation/${l.conversation_id}'">
                    <div class="ch-avatar ${scoreLabel}">${UI.icon(score >= 70 ? 'flame' : 'target', 18)}</div>
                    <div class="ch-conv-body">
                        <div class="ch-conv-top">
                            <span class="ch-conv-name">${esc(l.name || l.phone || 'غير معروف')}</span>
                            ${statusChip ? UI.pill(statusChip[0], statusChip[1]) : ''}
                        </div>
                        <div class="ch-conv-preview">${esc(l.interest || '-')}${l.destination ? ' · ' + esc(l.destination) : ''} · ${P.timeAgo(l.last_interaction_at)}</div>
                    </div>
                    <div class="ch-conv-meta" style="flex-direction:column;align-items:flex-end;gap:8px;">
                        <div style="width:120px;">${UI.scoreBar(score)}</div>
                        ${statusSelect}
                    </div>
                </div>`;
        }).join('');
    };

    window.addEventListener('tourfecto:website-changed', load);
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('chat', $this->tr('chat.leads.title'), $this->tr('chat.leads.subtitle'), $body, $script);
        exit;
    }

    /** DELETE /api/chat/message/{id} */
    public function deleteMessage(array $params): array {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $sql = "DELETE FROM chat_messages WHERE id = ? AND user_id = ?";
            $this->db->exec($sql, [(int) ($params['id'] ?? 0), $this->user['id']]);
            return $this->success([], 'تم حذف الرسالة');
        } catch (Exception $e) {
            Logger::error('Delete Message Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حذف الرسالة', 500);
        }
    }

    /**
     * توليد/إعادة توليد رد بالذكاء الاصطناعي لرسالة معلّقة معيّنة.
     * بيستخدم AutoReplyEngine الحقيقي (اللي بيتكلم مع Gemini فعليًا) بدل
     * ما يرجّع 501. مفيد لما العميل عايز "يجرّب رد تاني" قبل ما يوافق.
     * POST /api/chat/generate-reply  { message_id: number }
     */
    public function generateReply(array $params = []): array {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $messageId = (int) $this->get('message_id', 0);
        if (!$messageId) {
            return $this->error('معرف الرسالة مطلوب', 422);
        }

        try {
            $message = (new ChatMessage())->find($messageId);
            if (!$message || (int) $message->getAttribute('user_id') !== (int) $this->user['id']) {
                return $this->error('الرسالة غير موجودة', 404);
            }

            $engine = new AutoReplyEngine();
            $reply = $engine->generateReply(
                (string) $message->getAttribute('message_text'),
                (int) $this->user['id'],
                ['customer_name' => $message->getAttribute('customer_name')],
                ['auto_pilot' => false]
            );

            if (!$reply) {
                return $this->error('تعذر توليد رد - جرّب تاني بعد شوية', 502);
            }

            $message->setAttribute('ai_reply_generated', $reply);
            $message->save();

            return $this->success(['reply' => $reply], 'تم توليد رد جديد');
        } catch (Exception $e) {
            Logger::error('Generate Chat Reply Error', ['message_id' => $messageId, 'message' => $e->getMessage()]);
            return $this->error('تعذر توليد الرد', 500);
        }
    }

    // ============================================
    // ربط UltraMsg (واتساب) - كل عميل بحسابه الخاص
    // ============================================

    /**
     * توكن سري لكل موقع خاص برابط الـ webhook، عشان محدش يقدر يبعت
     * رسائل واردة مزيّفة لموقع مش بتاعه لو عرف رقم الـ website_id بس.
     */
    private function ultraMsgWebhookSecret(int $websiteId): string {
        $secretKey = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) ? ENCRYPTION_KEY : 'tourfecto-fallback-secret';
        return substr(hash_hmac('sha256', 'ultramsg-webhook:' . $websiteId, $secretKey), 0, 24);
    }

    /** GET /api/chat/ultramsg/status?website_id= */
    public function getUltraMsgStatus(array $params = []): array {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $connections = (new PlatformConnection())->where([
            'website_id' => $websiteId,
            'platform' => 'ultramsg',
            'status' => 'connected',
        ], [], 1);

        $webhookUrl = rtrim(defined('APP_URL') ? APP_URL : '', '/')
            . '/api/chat/webhook/ultramsg/' . $websiteId
            . '?secret=' . $this->ultraMsgWebhookSecret($websiteId);

        return $this->success([
            'connected' => !empty($connections),
            'instance_id' => !empty($connections) ? $connections[0]->getAttribute('external_account_id') : null,
            'webhook_url' => $webhookUrl,
        ]);
    }

    /**
     * GET /api/chat/channel-status?website_id=X&platform=messenger|instagram
     * نفس نمط getUltraMsgStatus() بالضبط لكن عام لأي منصة PlatformConnection -
     * يقفل الفجوة اللي اتوثّقت في المرحلة 9 (مفيش status endpoint لـ
     * Messenger/Instagram).
     */
    public function getChannelStatus(array $params = []): array {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $platform = (string) $this->get('platform', '');
        if (!$websiteId || !in_array($platform, ['messenger', 'instagram'], true)) {
            return $this->error('website_id وplatform (messenger أو instagram) مطلوبين', 422);
        }

        $connections = (new PlatformConnection())->where([
            'website_id' => $websiteId,
            'platform' => $platform,
            'status' => 'connected',
        ], [], 1);

        return $this->success([
            'connected' => !empty($connections),
            'external_account_id' => !empty($connections) ? $connections[0]->getAttribute('external_account_id') : null,
            'webhook_url' => rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/api/chat/webhook/' . $platform . '/' . $websiteId
                . '?secret=' . $this->channelWebhookSecret($websiteId, $platform),
            'verify_token' => $this->channelWebhookSecret($websiteId, $platform),
        ]);
    }

    /** POST /api/chat/connect/ultramsg */
    public function connectUltraMsg(array $params = []): array {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $instanceId = trim((string) $this->get('instance_id', ''));
        $apiKey = trim((string) $this->get('api_key', ''));

        if (!$websiteId || !$instanceId || !$apiKey) {
            return $this->error('الموقع ومعرف المثيل والتوكن مطلوبين', 422);
        }

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('الموقع غير موجود', 404);
        }

        // تحقق فعلي إن البيانات صحيحة قبل الحفظ (بدل ما نحفظ ونكتشف بعدين إنها غلط)
        $api = new UltraMsgAPI($instanceId, $apiKey);
        $statusResult = $api->getInstanceStatus();
        if (!$statusResult['success']) {
            return $this->error('تعذر التحقق من بيانات UltraMsg: ' . ($statusResult['error'] ?? ''), 422);
        }

        try {
            $encryption = new Encryption();
            $existing = (new PlatformConnection())->where([
                'website_id' => $websiteId,
                'platform' => 'ultramsg',
            ], [], 1);

            $connection = new PlatformConnection([
                'website_id' => $websiteId,
                'user_id' => $this->user['id'],
                'platform' => 'ultramsg',
                'access_token' => $encryption->encrypt($apiKey),
                'external_account_id' => $instanceId,
                'external_location_name' => 'UltraMsg Instance ' . $instanceId,
                'status' => 'connected',
                'last_error' => null,
            ]);

            if (!empty($existing)) {
                $connection->setAttribute('id', $existing[0]->getAttribute('id'));
            }
            $connection->save();

            $this->log('UltraMsg Connected', ['website_id' => $websiteId, 'instance_id' => $instanceId]);

            return $this->success([
                'webhook_url' => rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/api/chat/webhook/ultramsg/' . $websiteId . '?secret=' . $this->ultraMsgWebhookSecret($websiteId),
            ], 'تم ربط UltraMsg بنجاح');
        } catch (Exception $e) {
            Logger::error('Connect UltraMsg Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حفظ الربط', 500);
        }
    }

    /** POST /api/chat/disconnect/ultramsg/{website_id} */
    public function disconnectUltraMsg(array $params = []): array {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $websiteId = (int) ($params['website_id'] ?? 0);

        try {
            $connections = (new PlatformConnection())->where(['website_id' => $websiteId, 'platform' => 'ultramsg']);
            foreach ($connections as $conn) {
                if ((int) $conn->getAttribute('user_id') === (int) $this->user['id']) {
                    $conn->delete();
                }
            }
            return $this->success([], 'تم فصل الربط');
        } catch (Exception $e) {
            Logger::error('Disconnect UltraMsg Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر فصل الربط', 500);
        }
    }

    /**
     * POST /api/chat/webhook/ultramsg/{website_id}?secret=...
     * مسار مخصص لكل موقع (مش endpoint عام واحد) عشان نعرف نوجّه الرسالة
     * الواردة لصاحبها الصح فورًا، ونتحقق بـ secret موقّع بدل ما نصدّق أي
     * حد يعرف رقم website_id.
     *
     * ملحوظة: شكل بيانات webhook الحقيقي من UltraMsg اتبنى على أفضل فهم
     * متاح من توثيقهم العام - يُفضّل التأكد منه بإرسال رسالة تجريبية
     * حقيقية بعد الربط والتأكد من اللوج، لأنه معنديش بيئة أختبره فيها فعليًا.
     */
    public function ultraMsgWebhook(array $params = []): array {
        $websiteId = (int) ($params['website_id'] ?? 0);
        $secret = $this->get('secret');

        if (!$websiteId || !$secret || !hash_equals($this->ultraMsgWebhookSecret($websiteId), $secret)) {
            return $this->error('Unauthorized webhook', 401);
        }

        try {
            $website = (new Website())->find($websiteId);
            if (!$website) {
                return $this->error('Website not found', 404);
            }

            $payload = $this->all();
            $data = $payload['data'] ?? $payload;

            // تجاهل رسائلنا إحنا نفسنا (fromMe) عشان منردش على أنفسنا في حلقة
            if (!empty($data['fromMe'])) {
                return $this->success([], 'تم التجاهل (رسالة صادرة)');
            }

            $phone = $data['from'] ?? null;
            $text = $data['body'] ?? null;

            if (!$phone || !$text) {
                return $this->error('بيانات الرسالة غير مكتملة', 422);
            }

            // تنظيف صيغة الرقم القادمة من UltraMsg (بتيجي عادة كـ 201234567890@c.us)
            $phone = preg_replace('/@c\.us$|@g\.us$/', '', $phone);

            $result = $this->chatManager->processIncomingMessage([
                'user_id' => (int) $website->getAttribute('user_id'),
                'website_id' => $websiteId,
                'platform' => 'ultramsg',
                'phone_number' => $phone,
                'message' => $text,
            ]);

            return $result;
        } catch (Exception $e) {
            Logger::error('UltraMsg Webhook Error', ['website_id' => $websiteId, 'message' => $e->getMessage()]);
            return $this->error('Webhook processing failed', 500);
        }
    }

    // ============================================================
    // AI Chat Platform - قنوات إضافية (بند 1): Messenger، Instagram، Email
    //
    // نفس نمط ultraMsgWebhook()/connectUltraMsg() بالضبط: مسار مخصص لكل
    // موقع (secret موقَّع بدل endpoint عام)، website_id/user_id صريحان،
    // وفحص تكرار Webhook (بند 23) عبر جدول ai_webhook_events.
    // ============================================================

    /**
     * @param int $websiteId
     * @param string $platform
     * @return string
     */
    private function channelWebhookSecret(int $websiteId, string $platform): string {
        $secretKey = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) ? ENCRYPTION_KEY : 'tourfecto-fallback-secret';
        return substr(hash_hmac('sha256', $platform . '-webhook:' . $websiteId, $secretKey), 0, 24);
    }

    /**
     * فحص وتسجيل حدث Webhook لمنع معالجته مرتين (بند 23). يرجع true لو
     * الحدث جديد (كمّل المعالجة)، أو false لو كان مُعالَجًا بالفعل (تجاهله).
     * @param string $channel
     * @param string $externalEventId
     * @param int|null $websiteId
     * @return bool
     */
    private function isNewWebhookEvent(string $channel, string $externalEventId, ?int $websiteId): bool {
        if ($externalEventId === '') {
            return true; // لا معرف فريد نتحقق منه - نكمل المعالجة بدل ما نرفضها بالخطأ
        }

        try {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO ai_webhook_events (website_id, channel, external_event_id, status, received_at)
                 VALUES (?, ?, ?, 'received', NOW())",
                [$websiteId, $channel, $externalEventId]
            );
            return true;
        } catch (Exception $e) {
            // فشل الإدراج غالبًا بسبب UNIQUE KEY (channel, external_event_id) - يعني الحدث ده اتسجل قبل كده
            Logger::info('Duplicate webhook event ignored', ['channel' => $channel, 'external_event_id' => $externalEventId]);
            return false;
        }
    }

    /** POST /api/chat/connect/messenger */
    public function connectMessenger(array $params = []): array {
        return $this->connectMetaChannel('messenger');
    }

    /** POST /api/chat/connect/instagram */
    public function connectInstagram(array $params = []): array {
        return $this->connectMetaChannel('instagram');
    }

    /**
     * GET /api/chat/email-channel-info?website_id=X
     *
     * قناة الإيميل (بخلاف Messenger/Instagram) مالهاش Access Token يُربَط
     * لكل موقع - الإرسال بيمر عبر Mailer العام للمنصة (`app/Services/Mailer.php`)
     * زي ما هو. اللي محتاج الشركة تعرفه بس هو رابط الـWebhook والـsecret
     * الخاصين بموقعها عشان يوجّهوا مزود البريد الوارد (SendGrid/Mailgun)
     * ليهم - Endpoint معلوماتي بسيط جديد، مش تكرار لأي حاجة موجودة.
     */
    public function emailChannelInfo(array $params = []): array {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('الموقع غير موجود', 404);
        }

        return $this->success([
            'webhook_url' => rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/api/chat/webhook/email/' . $websiteId
                . '?secret=' . $this->channelWebhookSecret($websiteId, 'email'),
            'secret' => $this->channelWebhookSecret($websiteId, 'email'),
            'mailer_configured' => (new Mailer())->isConfigured(),
        ]);
    }

    /**
     * منطق مشترك لربط Messenger/Instagram - كلاهما يخزّن Page/IG Access
     * Token واحد في PlatformConnection بنفس أسلوب connectUltraMsg().
     * @param string $platform messenger|instagram
     * @return array
     */
    private function connectMetaChannel(string $platform): array {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $accessToken = trim((string) $this->get('access_token', ''));
        $externalAccountId = trim((string) $this->get('page_id', ''));

        if (!$websiteId || !$accessToken) {
            return $this->error('الموقع والـaccess_token مطلوبين', 422);
        }

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('الموقع غير موجود', 404);
        }

        try {
            $encryption = new Encryption();
            $existing = (new PlatformConnection())->where([
                'website_id' => $websiteId,
                'platform' => $platform,
            ], [], 1);

            $connection = new PlatformConnection([
                'website_id' => $websiteId,
                'user_id' => $this->user['id'],
                'platform' => $platform,
                'access_token' => $encryption->encrypt($accessToken),
                'external_account_id' => $externalAccountId ?: null,
                'external_location_name' => ucfirst($platform) . ' Page',
                'status' => 'connected',
                'last_error' => null,
            ]);

            if (!empty($existing)) {
                $connection->setAttribute('id', $existing[0]->getAttribute('id'));
            }
            $connection->save();

            $this->log(ucfirst($platform) . ' Connected', ['website_id' => $websiteId]);

            return $this->success([
                'webhook_url' => rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/api/chat/webhook/' . $platform . '/' . $websiteId
                    . '?secret=' . $this->channelWebhookSecret($websiteId, $platform),
                'verify_token' => $this->channelWebhookSecret($websiteId, $platform),
            ], 'تم ربط ' . $platform . ' بنجاح - استخدم webhook_url وverify_token عند إعداد الـWebhook في Meta for Developers');
        } catch (Exception $e) {
            Logger::error('Connect ' . $platform . ' Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حفظ الربط', 500);
        }
    }

    /**
     * تحقّق Meta القياسي لأي Webhook جديد (hub.challenge handshake)، مشترك
     * بين Messenger وInstagram لأن كليهما يستخدمان نفس آلية Meta for Developers.
     * GET /api/chat/webhook/messenger/{website_id}?hub.mode=subscribe&hub.verify_token=...&hub.challenge=...
     * GET /api/chat/webhook/instagram/{website_id}?hub.mode=subscribe&hub.verify_token=...&hub.challenge=...
     */
    public function verifyMetaChannelWebhook(array $params = [], string $platform = 'messenger'): array {
        $websiteId = (int) ($params['website_id'] ?? 0);
        $mode = $this->get('hub_mode') ?: $this->get('hub.mode');
        $token = $this->get('hub_verify_token') ?: $this->get('hub.verify_token');
        $challenge = $this->get('hub_challenge') ?: $this->get('hub.challenge');

        if ($mode === 'subscribe' && $websiteId && hash_equals($this->channelWebhookSecret($websiteId, $platform), (string) $token)) {
            return ['_raw_text' => (string) $challenge];
        }

        return $this->error('Verification failed', 403);
    }

    /** GET /api/chat/webhook/messenger/{website_id} (Meta verification handshake) */
    public function verifyMessengerWebhook(array $params = []): array {
        return $this->verifyMetaChannelWebhook($params, 'messenger');
    }

    /** GET /api/chat/webhook/instagram/{website_id} (Meta verification handshake) */
    public function verifyInstagramWebhook(array $params = []): array {
        return $this->verifyMetaChannelWebhook($params, 'instagram');
    }

    /**
     * POST /api/chat/webhook/messenger/{website_id}?secret=...
     * هيكل بيانات Messenger مبني على توثيق Meta Send/Receive API العام -
     * يُفضّل التأكد منه بربط صفحة تجريبية حقيقية بعد الرفع، لأنه لا توجد
     * بيئة اختبار حقيقية متاحة هنا لمزود Meta.
     */
    public function messengerWebhook(array $params = []): array {
        return $this->handleMetaChannelWebhook($params, 'messenger');
    }

    /**
     * POST /api/chat/webhook/instagram/{website_id}?secret=...
     * هيكل بيانات Instagram Messaging مطابق تقريبًا لـMessenger عبر نفس
     * Graph API - يُفضّل التأكد منه بربط حساب تجريبي حقيقي بعد الرفع.
     */
    public function instagramWebhook(array $params = []): array {
        return $this->handleMetaChannelWebhook($params, 'instagram');
    }

    /**
     * منطق مشترك لاستقبال رسائل Messenger/Instagram (نفس بنية الـPayload
     * تقريبًا في الاثنين لأنهما يمران عبر نفس Meta Graph API).
     * @param array $params
     * @param string $platform messenger|instagram
     * @return array
     */
    private function handleMetaChannelWebhook(array $params, string $platform): array {
        $websiteId = (int) ($params['website_id'] ?? 0);
        $secret = $this->get('secret');

        if (!$websiteId || !$secret || !hash_equals($this->channelWebhookSecret($websiteId, $platform), (string) $secret)) {
            return $this->error('Unauthorized webhook', 401);
        }

        try {
            $website = (new Website())->find($websiteId);
            if (!$website) {
                return $this->error('Website not found', 404);
            }

            $data = $this->all();
            $entry = $data['entry'][0] ?? [];
            $messaging = $entry['messaging'][0] ?? [];
            $message = $messaging['message'] ?? [];
            $sender = $messaging['sender'] ?? [];

            // تجاهل رسائلنا إحنا نفسنا (echo) عشان منردش على أنفسنا في حلقة
            if (!empty($message['is_echo'])) {
                return $this->success([], 'تم التجاهل (رسالة صادرة)');
            }

            $senderId = $sender['id'] ?? null;
            $text = $message['text'] ?? null;
            $externalEventId = $message['mid'] ?? ($messaging['timestamp'] ?? '');

            if (!$senderId || !$text) {
                return $this->success([], 'No text message to process');
            }

            if (!$this->isNewWebhookEvent($platform, (string) $externalEventId, $websiteId)) {
                return $this->success([], 'Duplicate webhook ignored');
            }

            $result = $this->chatManager->processIncomingMessage([
                'user_id' => (int) $website->getAttribute('user_id'),
                'website_id' => $websiteId,
                'platform' => $platform,
                'phone_number' => $senderId, // معرّف العميل على المنصة (PSID/IGSID) - نفس الحقل يُستخدَم كمعرّف عام لكل القنوات
                'message' => $text,
            ]);

            return $result;
        } catch (Exception $e) {
            Logger::error(ucfirst($platform) . ' Webhook Error', ['website_id' => $websiteId, 'message' => $e->getMessage()]);
            return $this->error('Webhook processing failed', 500);
        }
    }

    /**
     * POST /api/chat/webhook/email/{website_id}?secret=...
     *
     * بنية Payload عامة موحّدة (from_email, from_name, subject, text,
     * message_id) بدل الالتزام بصيغة مزود بريد وارد معيّن (SendGrid
     * Inbound Parse، Mailgun Routes، إلخ) - لأن الشركة لم تحدد مزودًا
     * بعد. عند اختيار مزود حقيقي، يلزم فقط تعديل استخراج الحقول من
     * $this->all() في بداية الدالة لتطابق صيغته - باقي التدفق
     * (idempotency + processIncomingMessage) يبقى كما هو (بند 1: 
     * Integration Architecture كاملة بدون افتراض مزود بعينه).
     */
    public function emailWebhook(array $params = []): array {
        $websiteId = (int) ($params['website_id'] ?? 0);
        $secret = $this->get('secret');

        if (!$websiteId || !$secret || !hash_equals($this->channelWebhookSecret($websiteId, 'email'), (string) $secret)) {
            return $this->error('Unauthorized webhook', 401);
        }

        try {
            $website = (new Website())->find($websiteId);
            if (!$website) {
                return $this->error('Website not found', 404);
            }

            $data = $this->all();
            $fromEmail = $data['from_email'] ?? $data['from'] ?? null;
            $fromName = $data['from_name'] ?? null;
            $text = $data['text'] ?? $data['body_text'] ?? $data['body'] ?? null;
            $externalEventId = $data['message_id'] ?? '';

            if (!$fromEmail || !$text) {
                return $this->error('بيانات الرسالة غير مكتملة', 422);
            }

            if (!$this->isNewWebhookEvent('email', (string) $externalEventId, $websiteId)) {
                return $this->success([], 'Duplicate webhook ignored');
            }

            $result = $this->chatManager->processIncomingMessage([
                'user_id' => (int) $website->getAttribute('user_id'),
                'website_id' => $websiteId,
                'platform' => 'email',
                'phone_number' => $fromEmail, // نفس الحقل العام لمعرّف العميل - هنا هو الإيميل
                'email' => $fromEmail,
                'sender_name' => $fromName,
                'message' => $text,
            ]);

            return $result;
        } catch (Exception $e) {
            Logger::error('Email Webhook Error', ['website_id' => $websiteId, 'message' => $e->getMessage()]);
            return $this->error('Webhook processing failed', 500);
        }
    }
}