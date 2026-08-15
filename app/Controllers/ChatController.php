<?php

/**
 * Tourfecto - Chat Controller
 * متحكم الشات الذكي مع نظام الموافقات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class ChatController extends Controller
{
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
    public function __construct()
    {
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
    public function webhook(array $params = []): array
    {
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
    public function verifyWebhook(array $params = []): array
    {
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
    public function getMessages(array $params = []): array
    {
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
    public function getPendingApprovals(array $params = []): array
    {
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
                'pending' => array_map(fn ($m) => $m->toArray(), $messages),
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
    public function approveReply(array $params = []): array
    {
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
    public function sendMessage(array $params = []): array
    {
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
    public function getSettings(array $params = []): array
    {
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
    public function updateSettings(array $params = []): array
    {
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
    private function validateWebhook(?string $token): bool
    {
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
    public function index(array $params = []): array
    {
        $body = <<<'HTML'
        <div class="p-toolbar" style="flex-wrap:wrap;gap:8px;align-items:center;">
            <input type="text" id="ucSearch" class="form-control" placeholder="ابحث بالاسم أو الهاتف أو الإيميل..." style="max-width:240px;flex:1;min-width:180px;">
            <select id="ucStatus" class="p-select">
                <option value="">كل الحالات</option>
                <option value="open">مفتوحة</option>
                <option value="pending">قيد الانتظار</option>
                <option value="resolved">تم الحل</option>
                <option value="closed">مغلقة</option>
            </select>
            <select id="ucAiStatus" class="p-select">
                <option value="">AI أو موظف</option>
                <option value="ai">🤖 الذكاء الاصطناعي</option>
                <option value="human">👤 موظف</option>
                <option value="paused">⏸ متوقف</option>
            </select>
            <select id="ucLeadStatus" class="p-select">
                <option value="">كل حالات Lead</option>
                <option value="new_inquiry">استفسار جديد</option>
                <option value="qualifying">قيد التأهيل</option>
                <option value="qualified">مؤهّل</option>
                <option value="hot_lead">🔥 Lead ساخن</option>
                <option value="converted">تم التحويل</option>
                <option value="lost">فاقد</option>
            </select>
            <select id="ucChannel" class="p-select">
                <option value="">كل القنوات</option>
                <option value="whatsapp">واتساب</option>
                <option value="website_chat">شات الموقع</option>
                <option value="messenger">Messenger</option>
                <option value="instagram">Instagram</option>
                <option value="email">إيميل</option>
            </select>
            <select id="ucTag" class="p-select">
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
            <button class="p-btn outline xs" onclick="ucApplyFilters()">🔍 بحث</button>
            <div style="flex:1 1 0;min-width:8px;"></div>
            <a href="/chat/pending" class="p-btn outline xs">⏳ الرسائل المعلّقة</a>
            <a href="/chat/leads" class="p-btn outline xs">🎯 Leads</a>
            <a href="/chat/knowledge-base" class="p-btn outline xs">📚 قاعدة المعرفة</a>
            <a href="/chat/followup-settings" class="p-btn outline xs">⏰ المتابعة التلقائية</a>
            <a href="/chat/analytics" class="p-btn outline xs">📊 التحليلات</a>
            <a href="/chat/settings" class="p-btn primary xs">⚙️ ربط واتساب والإعدادات</a>
        </div>
        <div id="ucNoWebsite" class="p-card" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">🌐</div>اختر موقعًا من القائمة أعلى الصفحة أولًا لعرض محادثاته.</div>
        </div>
        <div class="p-card no-pad" id="ucTableWrap">
            <div class="p-table-scroll"><table class="p-table" id="conversationsTable">
                <thead><tr>
                    <th>القناة</th><th>العميل</th><th>الحالة</th><th>AI/موظف</th>
                    <th>Lead</th><th>الأولوية</th><th>الوسوم</th><th>آخر رسالة</th><th></th>
                </tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="9">جاري التحميل...</td></tr></tbody>
            </table></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;

    const CHANNEL_LABEL = {
        whatsapp: '📱 واتساب', website_chat: '🌐 شات الموقع', webchat: '🌐 شات الموقع',
        messenger: '📘 Messenger', instagram: '📷 Instagram', email: '✉️ إيميل',
    };
    const STATUS_PILL = {
        open: '<span class="pill green">مفتوحة</span>',
        pending: '<span class="pill">قيد الانتظار</span>',
        resolved: '<span class="pill">تم الحل</span>',
        closed: '<span class="pill red">مغلقة</span>',
    };
    const AI_STATUS_PILL = {
        ai: '<span class="pill green">🤖 AI</span>',
        human: '<span class="pill">👤 موظف</span>',
        paused: '<span class="pill red">⏸ متوقف</span>',
    };
    const LEAD_STATUS_PILL = {
        none: '', new_inquiry: '<span class="pill">استفسار جديد</span>',
        qualifying: '<span class="pill">قيد التأهيل</span>',
        qualified: '<span class="pill green">مؤهّل</span>',
        hot_lead: '<span class="pill red">🔥 ساخن</span>',
        converted: '<span class="pill green">تم التحويل</span>',
        lost: '<span class="pill red">فاقد</span>',
    };
    const PRIORITY_PILL = {
        low: '<span class="pill">منخفضة</span>', normal: '', high: '<span class="pill">🔺 عالية</span>',
        urgent: '<span class="pill red">🚨 عاجلة</span>',
    };

    function ensureWebsiteSelected() {
        const id = P.getCurrentWebsiteId();
        document.getElementById('ucNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('ucTableWrap').style.display = id ? 'block' : 'none';
        return id;
    }

    window.ucApplyFilters = function () { load(); };

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

        const tbody = document.querySelector('#conversationsTable tbody');
        tbody.innerHTML = '<tr class="p-loading-row"><td colspan="9">جاري التحميل...</td></tr>';

        const res = await fetchJSON('/api/ai-chat/websites/' + encodeURIComponent(websiteId) + '/conversations?' + qs.toString());

        if (!res.success) {
            tbody.innerHTML = '<tr><td colspan="9" class="p-cell-muted text-center">⚠️ ' + esc(res.error || 'تعذر تحميل المحادثات') + '</td></tr>';
            return;
        }

        const list = (res.data && Array.isArray(res.data.conversations)) ? res.data.conversations : [];
        if (!list.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="p-cell-muted text-center">لا توجد محادثات بعد</td></tr>';
            return;
        }

        tbody.innerHTML = list.map(c => {
            const customer = c.customer_name || c.customer_phone || c.customer_email || 'عميل غير معروف';
            const tags = (c.tags || []).map(t => '<span class="pill gray">' + esc(t) + '</span>').join(' ');
            const unread = c.unread_count > 0 ? ' <span class="pill red">' + c.unread_count + '</span>' : '';
            const rowStyle = c.unread_count > 0 ? 'font-weight:600;' : '';
            return `
                <tr style="${rowStyle}cursor:pointer;" onclick="window.location.href='/chat/conversation/${c.id}'">
                    <td>${CHANNEL_LABEL[c.channel] || esc(c.channel || '-')}</td>
                    <td>${esc(customer)}${unread}</td>
                    <td>${STATUS_PILL[c.status] || esc(c.status || '-')}</td>
                    <td>${AI_STATUS_PILL[c.ai_status] || esc(c.ai_status || '-')}</td>
                    <td>${LEAD_STATUS_PILL[c.lead_status] || ''}</td>
                    <td>${PRIORITY_PILL[c.priority] || ''}</td>
                    <td>${tags}</td>
                    <td class="p-cell-muted">${P.timeAgo(c.last_message_at)}</td>
                    <td><a href="/chat/conversation/${c.id}" class="p-btn outline xs" onclick="event.stopPropagation();">فتح</a></td>
                </tr>`;
        }).join('');
    }

    document.getElementById('ucSearch').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') load();
    });
    window.addEventListener('tourfecto:website-changed', load);

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
    public function showConversation(array $params): array
    {
        $conversationId = (int) ($params['id'] ?? $params['session_id'] ?? 0);
        $currentUserId = (int) ($this->user['id'] ?? 0);

        $body = <<<HTML
        <div id="loadingConv" class="p-empty"><div class="p-empty-icon">⏳</div>جاري تحميل المحادثة...</div>
        <div id="convNotFound" class="p-empty" style="display:none;"><div class="p-empty-icon">⚠️</div>المحادثة غير موجودة أو مش تابعة للموقع الحالي.</div>

        <div id="convBody" style="display:none;">
            <div class="p-card" id="convHeader" style="margin-bottom:14px;"></div>

            <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:2 1 420px;min-width:280px;">
                    <div class="p-card" id="convThread" style="max-height:480px;overflow-y:auto;"></div>

                    <div class="p-card" style="margin-top:14px;">
                        <div class="p-card-head"><h3>الرد</h3></div>
                        <div id="aiSuggestions" style="display:none;margin-bottom:10px;"></div>
                        <div class="form-group">
                            <textarea id="manualMessage" class="form-control" rows="3" placeholder="اكتب ردك هنا..."></textarea>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button class="p-btn primary" id="sendManualBtn" onclick="sendManual()">➤ إرسال</button>
                            <button class="p-btn outline" id="suggestBtn" onclick="loadSuggestions()">💡 اقتراح رد AI</button>
                        </div>
                    </div>
                </div>

                <div style="flex:1 1 260px;min-width:240px;">
                    <div class="p-card" id="leadPanel" style="margin-bottom:14px;"></div>
                    <div class="p-card">
                        <div class="p-card-head"><h3>ملاحظات وصفقات</h3></div>
                        <div class="p-empty" style="padding:16px 0;">
                            <div class="p-empty-icon">🧩</div>
                            ميزة الملاحظات والصفقات المرتبطة غير متاحة حاليًا في AI Chat Backend.
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
    const CHANNEL_LABEL = {
        whatsapp: '📱 واتساب', website_chat: '🌐 شات الموقع', webchat: '🌐 شات الموقع',
        messenger: '📘 Messenger', instagram: '📷 Instagram', email: '✉️ إيميل',
    };

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
        box.innerHTML = '<div class="p-cell-muted">🤖 جاري توليد اقتراحات...</div>';

        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId + '/reply-suggestions');
        btn.disabled = false;

        if (!res.success || !res.data || !Array.isArray(res.data.suggestions) || !res.data.suggestions.length) {
            box.innerHTML = '<div class="p-cell-muted">⚠️ ' + esc((res.data && res.data.error) || res.error || 'لا توجد اقتراحات متاحة الآن') + '</div>';
            return;
        }

        box.innerHTML = res.data.suggestions.map((s, i) => `
            <div class="p-card" style="padding:10px;margin-bottom:6px;cursor:pointer;" onclick="document.getElementById('manualMessage').value = this.dataset.text;">
                <span class="pill blue">اقتراح ${i + 1}</span>
                <span data-text="${esc(s).replace(/"/g, '&quot;')}" style="display:block;margin-top:6px;">${esc(s)}</span>
            </div>`).join('');
    };

    function renderHeader(c) {
        const customer = c.customer_name || c.customer_phone || c.customer_email || 'عميل غير معروف';
        const isAi = c.ai_status === 'ai';
        const isMine = c.assigned_agent_id == currentUserId;

        const tagsHtml = STANDARD_TAGS.map(t => {
            const active = (c.tags || []).includes(t);
            return `<span class="pill ${active ? 'blue' : 'gray'}" style="cursor:pointer;" onclick="toggleTag('${t}')">${active ? '✓ ' : ''}${t}</span>`;
        }).join(' ');

        const statusSelect = '<select class="p-select" onchange="updateField(\'status\', this.value)">' +
            STATUS_OPTIONS.map(([v, l]) => `<option value="${v}" ${c.status === v ? 'selected' : ''}>${l}</option>`).join('') + '</select>';
        const prioritySelect = '<select class="p-select" onchange="updateField(\'priority\', this.value)">' +
            PRIORITY_OPTIONS.map(([v, l]) => `<option value="${v}" ${c.priority === v ? 'selected' : ''}>${l}</option>`).join('') + '</select>';

        document.getElementById('convHeader').innerHTML = `
            <div class="p-card-head">
                <h3>${esc(customer)} ${CHANNEL_LABEL[c.channel] || esc(c.channel || '')}</h3>
                <span class="p-cell-muted">${esc(c.customer_phone || c.customer_email || '')}</span>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">
                ${isAi ? '<span class="pill green">🤖 يرد الآن: الذكاء الاصطناعي</span>' : '<span class="pill">👤 يرد الآن: موظف</span>'}
                <button class="p-btn ${isAi ? 'outline' : 'primary'} xs" onclick="toggleHandoff()">${isAi ? '⇄ تحويل لموظف' : '⇄ استرجاع الرد الآلي'}</button>
                <button class="p-btn outline xs" onclick="assignToggle()">${isMine ? '✖ إلغاء التعيين مني' : '👤 تعيين لي'}</button>
                ${statusSelect}
                ${prioritySelect}
                ${c.ai_confidence_score !== null && c.ai_confidence_score !== undefined ? '<span class="pill">ثقة AI: ' + Math.round(c.ai_confidence_score * 100) + '%</span>' : ''}
            </div>
            <div style="margin-bottom:8px;">${tagsHtml}</div>
            ${c.ai_summary ? '<div class="p-card" style="background:var(--panel-bg,#f7f8fa);padding:10px 14px;"><strong>ملخص AI:</strong> ' + esc(c.ai_summary) + '</div>' : ''}
        `;
    }

    function renderThread(messages) {
        const thread = document.getElementById('convThread');
        if (!messages.length) {
            thread.innerHTML = '<div class="p-empty"><div class="p-empty-icon">💬</div>لا توجد رسائل في هذه المحادثة بعد</div>';
            return;
        }
        thread.innerHTML = messages.map(m => {
            const mine = m.message_direction === 'outgoing';
            return `
                <div style="max-width:70%;margin:${mine ? '8px 0 8px auto' : '8px auto 8px 0'};padding:10px 14px;border-radius:12px;background:${mine ? 'var(--panel-accent)' : 'var(--panel-card-bg,#f1f2f4)'};color:${mine ? '#fff' : 'inherit'};">
                    <div>${esc(m.message_text || m.ai_reply_generated || '')}</div>
                    <div style="font-size:11px;opacity:.7;margin-top:4px;">${formatDate(m.sent_at || m.created_at)}</div>
                </div>`;
        }).join('');
        thread.scrollTop = thread.scrollHeight;
    }

    function renderLeadPanel(leads) {
        const panel = document.getElementById('leadPanel');
        const lead = (leads && leads.length) ? leads[0] : null;
        if (!lead) {
            panel.innerHTML = '<div class="p-card-head"><h3>معلومات Lead</h3></div><div class="p-empty" style="padding:16px 0;"><div class="p-empty-icon">📋</div>لا يوجد Lead مرتبط بهذه المحادثة بعد.</div>';
            return;
        }
        panel.innerHTML = `
            <div class="p-card-head"><h3>معلومات Lead</h3></div>
            <div class="p-kv"><span class="k">الدرجة</span><span class="v">${lead.lead_score ?? '-'} / 100</span></div>
            <div class="p-kv"><span class="k">نية الشراء</span><span class="v">${lead.intent_score ?? '-'} / 100</span></div>
            <div class="p-kv"><span class="k">الوجهة</span><span class="v">${esc(lead.destination || '-')}</span></div>
            <div class="p-kv"><span class="k">الاهتمام</span><span class="v">${esc(lead.interest || '-')}</span></div>
            <div class="p-kv"><span class="k">الحالة</span><span class="v">${esc(lead.status || '-')}</span></div>
            ${lead.next_recommended_action ? '<div style="margin-top:10px;padding:10px;background:var(--panel-bg,#f7f8fa);border-radius:8px;"><strong>الخطوة التالية المقترحة:</strong><br>' + esc(lead.next_recommended_action) + '</div>' : ''}
            <a href="/chat/leads" class="p-btn outline xs" style="margin-top:10px;display:inline-block;">عرض كل الـLeads</a>
        `;
    }

    async function load() {
        websiteId = P.getCurrentWebsiteId();
        if (!websiteId) {
            document.getElementById('loadingConv').style.display = 'none';
            document.getElementById('convNotFound').style.display = 'block';
            document.getElementById('convNotFound').innerHTML = '<div class="p-empty-icon">🌐</div>اختر موقعًا من القائمة أعلى الصفحة أولًا.';
            return;
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

    public function getConversation(array $params): array
    {
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
    public function showPending(array $params = []): array
    {
        $tLoading = $this->tr('common.loading');

        $body = <<<HTML
        <div id="pendingList" class="p-empty">{$tLoading}</div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;

    window.regenerateReply = async function (id) {
        const box = document.getElementById('reply-' + id);
        const btn = document.getElementById('regenBtn-' + id);
        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = '🤖 __GENERATING__';

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
                <div class="p-card" style="margin-bottom:14px;">
                    <div class="p-card-head">
                        <h3>${esc(m.customer_name || m.customer_phone || __CUSTOMER__)} <span class="pill">${esc(m.platform || '-')}</span></h3>
                        <span class="p-cell-muted">${formatDate(m.created_at)}</span>
                    </div>
                    <div class="p-kv"><span class="k">__CUSTOMER_MESSAGE__</span></div>
                    <p style="background:var(--panel-bg,#f7f8fa);padding:12px 14px;border-radius:8px;margin:6px 0 14px;">${esc(m.message_text || '-')}</p>
                    <label class="form-label">__SUGGESTED_REPLY__</label>
                    <textarea id="reply-${m.id}" class="form-control" style="min-height:90px;margin-bottom:10px;">${esc(m.ai_reply_generated || '')}</textarea>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button class="p-btn success xs" onclick="approveMsg(${m.id}, 'approve')">✔ __APPROVE_SEND__</button>
                        <button class="p-btn outline xs" id="regenBtn-${m.id}" onclick="regenerateReply(${m.id})">🔄 __GENERATE_NEW__</button>
                        <button class="p-btn danger xs" onclick="approveMsg(${m.id}, 'reject')">✖ __REJECT__</button>
                    </div>
                </div>`).join('');
        } else {
            container.innerHTML = '<div class="p-empty"><div class="p-empty-icon">🎉</div>__NO_PENDING__</div>';
        }
    }
    load();
})();
JS;
        $script = str_replace(
            [
                '__GENERATING__', '__NEW_REPLY_GENERATED__', '__GENERATE_FAILED__', '__WRITE_OR_GENERATE_FIRST__',
                '__APPROVED_SEND_FAILED__', '__APPROVED_SENT__', '__REJECTED__', '__ACTION_FAILED__', '__CUSTOMER__',
                '__CUSTOMER_MESSAGE__', '__SUGGESTED_REPLY__', '__APPROVE_SEND__', '__GENERATE_NEW__', '__REJECT__', '__NO_PENDING__',
            ],
            [
                $this->trJs('chat.pending.generating'),
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
                $this->tr('chat.pending.approve_send'),
                $this->tr('chat.pending.generate_new'),
                $this->tr('chat.pending.reject'),
                $this->tr('chat.pending.none'),
            ],
            $script
        );

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('chat', $this->tr('chat.pending.title'), $this->tr('chat.pending.subtitle'), $body, $script);
        exit;
    }

    /** GET /chat/settings */
    public function showSettings(array $params = []): array
    {
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
        <div class="p-toolbar">
            <a href="/chat" class="p-btn outline xs">← صندوق الوارد</a>
            <a href="/chat/knowledge-base" class="p-btn outline xs">📚 قاعدة المعرفة</a>
        </div>
        <div class="p-card">
            <div class="form-group">
                <label class="form-label" for="websiteSelect">{$tSelectWebsite}</label>
                <select id="websiteSelect" class="form-control" onchange="loadSettings()">
                    <option value="">{$tLoadingWebsites}</option>
                </select>
            </div>
        </div>
        <div id="ultramsgCard" class="p-card" style="display:none;margin-top:14px;">
            <div class="p-card-head"><h3>📱 {$tWhatsappTitle}</h3><span class="p-card-sub">{$tWhatsappSub}</span></div>
            <div id="ultramsgConnected" style="display:none;">
                <div class="alert alert-success">✔ {$tConnectedInstance} <span id="umInstanceId"></span></div>
                <p class="p-cell-muted">{$tWebhookUrlHint}</p>
                <code id="umWebhookUrl" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
                <button class="p-btn outline xs" style="margin-top:10px;" onclick="disconnectUltraMsg()">{$tDisconnectLink}</button>
            </div>
            <div id="ultramsgDisconnected" style="display:none;">
                <p class="p-cell-muted">{$tFreeAccountHint} <a href="https://ultramsg.com" target="_blank" rel="noopener">ultramsg.com</a></p>
                <div class="form-group">
                    <input type="text" id="umInstanceInput" class="form-control" placeholder="{$tInstanceIdPlaceholder}" style="margin-bottom:8px;">
                </div>
                <div class="form-group">
                    <input type="text" id="umTokenInput" class="form-control" placeholder="API Token">
                </div>
                <div id="ultramsgAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
                <button class="p-btn primary" style="margin-top:10px;" onclick="connectUltraMsg()">{$tConnectAccount}</button>
            </div>
        </div>
        <div id="settingsFormCard" class="p-card" style="display:none;margin-top:14px;">
            <div class="p-card-head"><h3>{$tBotSettings}</h3></div>
            <div class="form-group">
                <label class="form-label"><input type="checkbox" id="isEnabled"> {$tEnableBot}</label>
            </div>
            <div class="form-group">
                <label class="form-label"><input type="checkbox" id="autoPilot"> {$tAutoPilot}</label>
            </div>
            <div class="form-group">
                <label class="form-label"><input type="checkbox" id="requiresApproval"> {$tRequiresApproval}</label>
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
        <div id="messengerCard" class="p-card" style="display:none;margin-top:14px;">
            <div class="p-card-head"><h3>📘 Messenger</h3><span class="p-card-sub">اربط صفحة فيسبوك الخاصة بالشركة</span></div>
            <div id="messengerConnected" style="display:none;">
                <div class="alert alert-success">✔ Messenger مربوط بالفعل</div>
                <p class="p-cell-muted">Webhook URL:</p>
                <code id="msgWebhookUrl" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
                <p class="p-cell-muted" style="margin-top:8px;">Verify Token:</p>
                <code id="msgVerifyToken" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
            </div>
            <div id="messengerForm">
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
        <div id="instagramCard" class="p-card" style="display:none;margin-top:14px;">
            <div class="p-card-head"><h3>📷 Instagram</h3><span class="p-card-sub">اربط حساب انستجرام التجاري الخاص بالشركة</span></div>
            <div id="instagramConnected" style="display:none;">
                <div class="alert alert-success">✔ Instagram مربوط بالفعل</div>
                <p class="p-cell-muted">Webhook URL:</p>
                <code id="igWebhookUrl" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
                <p class="p-cell-muted" style="margin-top:8px;">Verify Token:</p>
                <code id="igVerifyToken" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
            </div>
            <div id="instagramForm">
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
        <div class="p-card" id="noWebsitesCard" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">🌐</div>{$tNoWebsitesMsg}</div>
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
            return;
        }

        document.getElementById('ultramsgCard').style.display = 'block';
        await loadUltraMsgStatus(websiteId);
        document.getElementById('messengerCard').style.display = 'block';
        document.getElementById('instagramCard').style.display = 'block';

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
    public function showKnowledgeBase(array $params = []): array
    {
        $body = <<<'HTML'
        <div class="p-toolbar">
            <a href="/chat" class="p-btn outline xs">← الرجوع لصندوق الوارد</a>
            <div style="flex:1;"></div>
            <button class="p-btn outline xs" onclick="kbPreview()">👁 معاينة السياق المُرسَل للـAI</button>
        </div>

        <div id="kbNoWebsite" class="p-card" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">🌐</div>اختر موقعًا من القائمة أعلى الصفحة أولًا.</div>
        </div>

        <div id="kbBody" style="display:none;">
            <div class="p-card" style="margin-bottom:14px;">
                <div class="p-card-head"><h3>🎙 نبرة الشركة (Brand Voice)</h3><span class="p-card-sub">تُستخدم في كل ردود الذكاء الاصطناعي</span></div>
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

            <div class="p-card" style="margin-bottom:14px;">
                <div class="p-card-head"><h3>➕ إضافة معلومة جديدة</h3></div>
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
                <button class="p-btn primary" onclick="kbAddEntry()">➕ إضافة</button>
            </div>

            <div id="kbSectionsContainer"></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;

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

    window.kbAddEntry = async function () {
        const id = ensureWebsite();
        if (!id) return;
        const section = document.getElementById('kbSection').value;
        const language = document.getElementById('kbLanguage').value;
        const title = document.getElementById('kbTitle').value.trim();
        const content = document.getElementById('kbContent').value.trim();
        if (!content) { toast('اكتب المحتوى أولاً', 'error'); return; }

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ section: section, language: language, title: title || null, content: content }),
        });

        if (res.success) {
            toast('تمت الإضافة', 'success');
            document.getElementById('kbTitle').value = '';
            document.getElementById('kbContent').value = '';
            load();
        } else {
            toast(res.error || 'فشلت الإضافة', 'error');
        }
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
            container.innerHTML = '<div class="p-card"><div class="p-empty">⚠️ ' + esc(res.error || 'تعذر التحميل') + '</div></div>';
            return;
        }

        if (res.data.brand_voice) {
            document.getElementById('bvTone').value = res.data.brand_voice.tone || 'professional';
            document.getElementById('bvInstructions').value = res.data.brand_voice.custom_instructions || '';
        }

        const sections = res.data.sections || {};
        const sectionKeys = Object.keys(sections);
        if (!sectionKeys.length) {
            container.innerHTML = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">📚</div>مفيش أي معلومات مضافة لقاعدة المعرفة بعد. أضف أول معلومة من الفورم أعلاه - من غيرها الذكاء الاصطناعي مش هيقدر يجاوب على أي سؤال محدد عن شركتك.</div></div>';
            return;
        }

        container.innerHTML = sectionKeys.map(section => {
            const entries = sections[section];
            const rows = entries.map(e => `
                <div class="p-kv" style="align-items:flex-start;">
                    <span class="k" style="max-width:70%;">
                        ${e.title ? '<strong>' + esc(e.title) + '</strong><br>' : ''}
                        ${esc(e.content || '')}
                        <span class="p-cell-muted"> · ${e.language === 'en' ? 'EN' : 'AR'}</span>
                    </span>
                    <button class="p-btn danger xs" onclick="kbDeleteEntry(${e.id})">حذف</button>
                </div>`).join('');
            return `
                <div class="p-card" style="margin-bottom:14px;">
                    <div class="p-card-head"><h3>${SECTION_LABELS[section] || esc(section)}</h3><span class="p-card-sub">${entries.length} عنصر</span></div>
                    ${rows}
                </div>`;
        }).join('');
    }

    window.addEventListener('tourfecto:website-changed', load);
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('chat', 'قاعدة المعرفة', 'المعلومات التي يعتمد عليها الذكاء الاصطناعي في الرد على عملائك', $body, $script);
        exit;
    }

    /**
     * GET /chat/followup-settings
     * واجهة إعدادات المتابعة التلقائية (بند 7) - تستخدم Endpoint موجود
     * من المرحلة 3 (AiFollowupSettingsController) - صفر Endpoint جديد.
     */
    public function showFollowupSettings(array $params = []): array
    {
        $body = <<<'HTML'
        <div class="p-toolbar">
            <a href="/chat" class="p-btn outline xs">← صندوق الوارد</a>
        </div>
        <div id="fuNoWebsite" class="p-card" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">🌐</div>اختر موقعًا من القائمة أعلى الصفحة أولًا.</div>
        </div>
        <div id="fuBody" style="display:none;">
            <div class="p-card" style="margin-bottom:14px;">
                <div class="p-card-head"><h3>⏰ المتابعة التلقائية</h3><span class="p-card-sub">لو العميل سأل ثم اختفى، النظام يقدر يبعتله متابعة تلقائية حسب الخطوات تحت</span></div>
                <div class="form-group">
                    <label class="form-label"><input type="checkbox" id="fuEnabled"> تفعيل المتابعة التلقائية لهذا الموقع</label>
                </div>
                <div class="form-group">
                    <label class="form-label">الحد الأقصى لعدد المتابعات لكل عميل</label>
                    <input type="number" id="fuMax" class="form-control" min="1" max="10" style="max-width:120px;">
                </div>
            </div>
            <div class="p-card" style="margin-bottom:14px;">
                <div class="p-card-head"><h3>خطوات المتابعة</h3></div>
                <div id="fuSteps"></div>
                <button class="p-btn outline xs" style="margin-top:10px;" onclick="fuAddStep()">➕ إضافة خطوة</button>
            </div>
            <button class="p-btn primary" onclick="fuSave()">💾 حفظ الإعدادات</button>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
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
            container.innerHTML = '<div class="p-cell-muted">مفيش خطوات لسه - أضف خطوة عشان المتابعة التلقائية تشتغل.</div>';
            return;
        }
        container.innerHTML = steps.map((s, i) => `
            <div class="p-card" style="background:var(--panel-bg,#f7f8fa);padding:12px;margin-bottom:10px;">
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <strong>الخطوة ${i + 1}</strong>
                    <label class="form-label" style="margin:0;">بعد</label>
                    <input type="number" class="form-control" style="max-width:90px;" value="${s.after_hours}" onchange="fuUpdateStep(${i}, 'after_hours', this.value)">
                    <span class="p-cell-muted">ساعة من آخر رسالة للعميل</span>
                    <div style="flex:1;"></div>
                    <button class="p-btn danger xs" onclick="fuRemoveStep(${i})">حذف</button>
                </div>
                <div class="form-group" style="margin-top:8px;margin-bottom:0;">
                    <label class="form-label">نص الرسالة (استخدم {name} لاسم العميل)</label>
                    <textarea class="form-control" rows="2" onchange="fuUpdateStep(${i}, 'template', this.value)">${esc(s.template || '')}</textarea>
                </div>
            </div>`).join('');
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
        echo $this->renderPanelPage('chat', 'المتابعة التلقائية', 'إعدادات الرسائل التلقائية للعملاء الذين لم يردّوا', $body, $script);
        exit;
    }

    /**
     * GET /chat/analytics
     * لوحة تحليلات AI Chat (بند 18) - تستخدم Endpoint موجود من المرحلة 4
     * (AiAnalyticsController) - صفر Endpoint جديد.
     */
    public function showAnalytics(array $params = []): array
    {
        $body = <<<'HTML'
        <div class="p-toolbar">
            <a href="/chat" class="p-btn outline xs">← صندوق الوارد</a>
            <div style="flex:1;"></div>
            <select id="anSince" class="p-select" onchange="load()">
                <option value="7">آخر 7 أيام</option>
                <option value="30" selected>آخر 30 يوم</option>
                <option value="90">آخر 90 يوم</option>
            </select>
        </div>
        <div id="anNoWebsite" class="p-card" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">🌐</div>اختر موقعًا من القائمة أعلى الصفحة أولًا.</div>
        </div>
        <div id="anBody" style="display:none;">
            <div id="anStats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:14px;"></div>
            <div style="display:flex;gap:14px;flex-wrap:wrap;">
                <div class="p-card" style="flex:1;min-width:260px;">
                    <div class="p-card-head"><h3>🏷 أكثر الوسوم تكرارًا</h3></div>
                    <div id="anTags"></div>
                </div>
                <div class="p-card" style="flex:1;min-width:260px;">
                    <div class="p-card-head"><h3>🎯 أكثر الخدمات طلبًا</h3></div>
                    <div id="anServices"></div>
                </div>
            </div>
            <div class="p-card" style="margin-top:14px;">
                <div class="p-card-head"><h3>🤖 استخدام مزودي الذكاء الاصطناعي</h3></div>
                <div class="p-table-scroll"><table class="p-table" id="anProviders">
                    <thead><tr><th>المزود</th><th>عدد الطلبات</th><th>عدد الرموز (Tokens)</th><th>التكلفة التقديرية</th><th>طلبات فاشلة</th></tr></thead>
                    <tbody></tbody>
                </table></div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON;

    function websiteId() { return P.getCurrentWebsiteId(); }

    function ensureWebsite() {
        const id = websiteId();
        document.getElementById('anNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('anBody').style.display = id ? 'block' : 'none';
        return id;
    }

    function statTile(label, value) {
        return `<div class="p-card" style="text-align:center;padding:16px;">
            <div style="font-size:24px;font-weight:800;">${value}</div>
            <div class="p-cell-muted">${label}</div>
        </div>`;
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
            statTile('معدّل حل الذكاء الاصطناعي', d.ai_resolution_rate_percent + '%'),
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
        echo $this->renderPanelPage('chat', 'تحليلات AI Chat', 'أداء الذكاء الاصطناعي والمحادثات', $body, $script);
        exit;
    }

    /**
     * GET /chat/leads
     * قائمة Leads مستقلة (بند 5، 6) - مرتّبة حسب Lead Score، قابلة
     * للفلترة بالحالة. تستخدم Endpoint موجود من المرحلة 3
     * (AiLeadController) - صفر Endpoint جديد.
     */
    public function showLeads(array $params = []): array
    {
        $body = <<<'HTML'
        <div class="p-toolbar">
            <a href="/chat" class="p-btn outline xs">← صندوق الوارد</a>
            <div style="flex:1;"></div>
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
        <div id="ldNoWebsite" class="p-card" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">🌐</div>اختر موقعًا من القائمة أعلى الصفحة أولًا.</div>
        </div>
        <div class="p-card no-pad" id="ldTableWrap">
            <div class="p-table-scroll"><table class="p-table" id="leadsTable">
                <thead><tr>
                    <th>العميل</th><th>القناة</th><th>الاهتمام</th><th>الوجهة</th>
                    <th>Lead Score</th><th>النية</th><th>الحالة</th><th>آخر تفاعل</th><th></th>
                </tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="9">جاري التحميل...</td></tr></tbody>
            </table></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;

    const STATUS_OPTIONS = [
        ['new', 'جديد'], ['contacted', 'تم التواصل'], ['qualified', 'مؤهّل'],
        ['proposal_sent', 'تم إرسال عرض سعر'], ['won', 'تم الفوز به'], ['lost', 'فاقد'],
    ];

    function websiteId() { return P.getCurrentWebsiteId(); }

    function ensureWebsite() {
        const id = websiteId();
        document.getElementById('ldNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('ldTableWrap').style.display = id ? 'block' : 'none';
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

        const tbody = document.querySelector('#leadsTable tbody');
        tbody.innerHTML = '<tr class="p-loading-row"><td colspan="9">جاري التحميل...</td></tr>';

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/leads' + qs);
        if (!res.success) {
            tbody.innerHTML = '<tr><td colspan="9" class="p-cell-muted text-center">⚠️ ' + esc(res.error || 'تعذر التحميل') + '</td></tr>';
            return;
        }

        const leads = res.data.leads || [];
        if (!leads.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="p-cell-muted text-center">لا توجد Leads بعد</td></tr>';
            return;
        }

        tbody.innerHTML = leads.map(l => {
            const statusSelect = '<select class="p-select" style="min-width:130px;" onchange="ldUpdateStatus(' + l.id + ', this.value)">' +
                STATUS_OPTIONS.map(([v, label]) => `<option value="${v}" ${l.status === v ? 'selected' : ''}>${label}</option>`).join('') + '</select>';
            return `
                <tr>
                    <td>${esc(l.name || l.phone || 'غير معروف')}</td>
                    <td>${esc(l.channel || '-')}</td>
                    <td>${esc(l.interest || '-')}</td>
                    <td>${esc(l.destination || '-')}</td>
                    <td><span class="pill ${l.lead_score >= 70 ? 'red' : l.lead_score >= 40 ? 'blue' : 'gray'}">${l.lead_score ?? '-'}</span></td>
                    <td>${l.intent_score ?? '-'}</td>
                    <td>${statusSelect}</td>
                    <td class="p-cell-muted">${P.timeAgo(l.last_interaction_at)}</td>
                    <td><a href="/chat/conversation/${l.conversation_id}" class="p-btn outline xs">فتح المحادثة</a></td>
                </tr>`;
        }).join('');
    };

    window.addEventListener('tourfecto:website-changed', load);
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('chat', 'Leads', 'كل العملاء المحتملين مرتّبين حسب الأولوية', $body, $script);
        exit;
    }

    /** DELETE /api/chat/message/{id} */
    public function deleteMessage(array $params): array
    {
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
    public function generateReply(array $params = []): array
    {
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
    private function ultraMsgWebhookSecret(int $websiteId): string
    {
        $secretKey = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) ? ENCRYPTION_KEY : 'tourfecto-fallback-secret';
        return substr(hash_hmac('sha256', 'ultramsg-webhook:' . $websiteId, $secretKey), 0, 24);
    }

    /** GET /api/chat/ultramsg/status?website_id= */
    public function getUltraMsgStatus(array $params = []): array
    {
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

    /** POST /api/chat/connect/ultramsg */
    public function connectUltraMsg(array $params = []): array
    {
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
    public function disconnectUltraMsg(array $params = []): array
    {
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
    public function ultraMsgWebhook(array $params = []): array
    {
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
    private function channelWebhookSecret(int $websiteId, string $platform): string
    {
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
    private function isNewWebhookEvent(string $channel, string $externalEventId, ?int $websiteId): bool
    {
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
    public function connectMessenger(array $params = []): array
    {
        return $this->connectMetaChannel('messenger');
    }

    /** POST /api/chat/connect/instagram */
    public function connectInstagram(array $params = []): array
    {
        return $this->connectMetaChannel('instagram');
    }

    /**
     * منطق مشترك لربط Messenger/Instagram - كلاهما يخزّن Page/IG Access
     * Token واحد في PlatformConnection بنفس أسلوب connectUltraMsg().
     * @param string $platform messenger|instagram
     * @return array
     */
    private function connectMetaChannel(string $platform): array
    {
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
    public function verifyMetaChannelWebhook(array $params = [], string $platform = 'messenger'): array
    {
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
    public function verifyMessengerWebhook(array $params = []): array
    {
        return $this->verifyMetaChannelWebhook($params, 'messenger');
    }

    /** GET /api/chat/webhook/instagram/{website_id} (Meta verification handshake) */
    public function verifyInstagramWebhook(array $params = []): array
    {
        return $this->verifyMetaChannelWebhook($params, 'instagram');
    }

    /**
     * POST /api/chat/webhook/messenger/{website_id}?secret=...
     * هيكل بيانات Messenger مبني على توثيق Meta Send/Receive API العام -
     * يُفضّل التأكد منه بربط صفحة تجريبية حقيقية بعد الرفع، لأنه لا توجد
     * بيئة اختبار حقيقية متاحة هنا لمزود Meta.
     */
    public function messengerWebhook(array $params = []): array
    {
        return $this->handleMetaChannelWebhook($params, 'messenger');
    }

    /**
     * POST /api/chat/webhook/instagram/{website_id}?secret=...
     * هيكل بيانات Instagram Messaging مطابق تقريبًا لـMessenger عبر نفس
     * Graph API - يُفضّل التأكد منه بربط حساب تجريبي حقيقي بعد الرفع.
     */
    public function instagramWebhook(array $params = []): array
    {
        return $this->handleMetaChannelWebhook($params, 'instagram');
    }

    /**
     * منطق مشترك لاستقبال رسائل Messenger/Instagram (نفس بنية الـPayload
     * تقريبًا في الاثنين لأنهما يمران عبر نفس Meta Graph API).
     * @param array $params
     * @param string $platform messenger|instagram
     * @return array
     */
    private function handleMetaChannelWebhook(array $params, string $platform): array
    {
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
    public function emailWebhook(array $params = []): array
    {
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
