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
     * مجموعة أيقونات SVG موحّدة (Lucide-style) تُستخدم في كل صفحات موديول
     * الشات بدل الإيموجي. بنعرضها كـ sprite مخفي جوه الصفحة، وأي `<svg>`
     * بيشاور عليها بـ `<use href="#i-{name}">`. كده الصفحة كلها (PHP + JS)
     * بتيجي من مصدر واحد، والأيقونات vector => شاربحة على أي DPI.
     *
     * ملاحظة تصميم: الإيموجي كأيقونات عامل "غير احترافي" في مراجعات UI،
     * واستبدالها بـ SVG icons (Heroicons/Lucide) هو أول نقطة إصلاح.
     */
    private function chatIcons(): string
    {
        return '<svg style="display:none" xmlns="http://www.w3.org/2000/svg">'
            . '<symbol id="i-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></symbol>'
            . '<symbol id="i-inbox" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></symbol>'
            . '<symbol id="i-chart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/><path d="M2 20h20"/></symbol>'
            . '<symbol id="i-book" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></symbol>'
            . '<symbol id="i-sparkles" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3z"/></symbol>'
            . '<symbol id="i-target" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></symbol>'
            . '<symbol id="i-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></symbol>'
            . '<symbol id="i-gear" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></symbol>'
            . '<symbol id="i-send" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></symbol>'
            . '<symbol id="i-handoff" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3 4 7l4 4"/><path d="M4 7h16"/><path d="m16 21 4-4-4-4"/><path d="M20 17H4"/></symbol>'
            . '<symbol id="i-pause" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/></symbol>'
            . '<symbol id="i-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></symbol>'
            . '<symbol id="i-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></symbol>'
            . '<symbol id="i-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></symbol>'
            . '<symbol id="i-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></symbol>'
            . '<symbol id="i-edit" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></symbol>'
            . '<symbol id="i-refresh" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></symbol>'
            . '<symbol id="i-alert" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></symbol>'
            . '<symbol id="i-user" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></symbol>'
            . '<symbol id="i-user-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></symbol>'
            . '<symbol id="i-phone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></symbol>'
            . '<symbol id="i-mail" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></symbol>'
            . '<symbol id="i-globe" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><line x1="2" y1="12" x2="22" y2="12"/></symbol>'
            . '<symbol id="i-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></symbol>'
            . '<symbol id="i-tag" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2H2v10l9.29 9.29a1 1 0 0 0 1.42 0l8.58-8.58a1 1 0 0 0 0-1.42Z"/><line x1="7" y1="7" x2="7.01" y2="7"/></symbol>'
            . '<symbol id="i-flag" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></symbol>'
            . '<symbol id="i-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></symbol>'
            . '<symbol id="i-external" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></symbol>'
            . '<symbol id="i-wallet" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></symbol>'
            . '<symbol id="i-fire" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></symbol>'
            . '<symbol id="i-dollar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></symbol>'
            . '<symbol id="i-phone-call" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.05 5A5 5 0 0 1 19 8.95"/><path d="M15.05 1A9 9 0 0 1 23 8.94"/><path d="m22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></symbol>'
            . '</svg>';
    }

    /**
     * دالة تولّد `<svg><use href="#i-{name}">` من السبريت أعلاه.
     * @param string $name اسم الأيقونة
     * @param string $extraClass كلاسات إضافية
     * @return string
     */
    private function ic(string $name, string $extraClass = ''): string
    {
        $cls = 'ic ' . $extraClass;
        return '<svg class="' . trim($cls) . '" aria-hidden="true"><use href="#i-' . $name . '"/></svg>';
    }

    /**
     * استبدال كل الـ placeholders ({IC_*}، {ICON_SPRITE}) في الـ body
     * النهائي قبل ما يتعرض. كده الصفحة تقدر تكتب الأيقونات بنص رمزي
     * readable في الـ View بدل لزق الـ SVG في كل حتة.
     * @param string $html
     * @return string
     */
    private function applyChatUi(string $html): string
    {
        $html = str_replace('{ICON_SPRITE}', $this->chatIcons(), $html);

        $map = [
            'IC_SEARCH' => $this->ic('search'),
            'IC_INBOX' => $this->ic('inbox'),
            'IC_CHART' => $this->ic('chart'),
            'IC_BOOK' => $this->ic('book'),
            'IC_SPARKLES' => $this->ic('sparkles'),
            'IC_TARGET' => $this->ic('target'),
            'IC_CLOCK' => $this->ic('clock'),
            'IC_GEAR' => $this->ic('gear'),
            'IC_SEND' => $this->ic('send'),
            'IC_HANDOFF' => $this->ic('handoff'),
            'IC_PAUSE' => $this->ic('pause'),
            'IC_CHECK' => $this->ic('check'),
            'IC_X' => $this->ic('x'),
            'IC_PLUS' => $this->ic('plus'),
            'IC_TRASH' => $this->ic('trash'),
            'IC_EDIT' => $this->ic('edit'),
            'IC_REFRESH' => $this->ic('refresh'),
            'IC_ALERT' => $this->ic('alert'),
            'IC_USER' => $this->ic('user'),
            'IC_USER_PLUS' => $this->ic('user-plus'),
            'IC_PHONE' => $this->ic('phone'),
            'IC_MAIL' => $this->ic('mail'),
            'IC_GLOBE' => $this->ic('globe'),
            'IC_CHAT' => $this->ic('chat'),
            'IC_TAG' => $this->ic('tag'),
            'IC_FLAG' => $this->ic('flag'),
            'IC_EXTERNAL' => $this->ic('external'),
            'IC_WALLET' => $this->ic('wallet'),
            'IC_FIRE' => $this->ic('fire'),
            'IC_DOLLAR' => $this->ic('dollar'),
            'IC_CALL' => $this->ic('phone-call'),
        ];
        foreach ($map as $ph => $svg) {
            $html = str_replace('{' . $ph . '}', $svg, $html);
        }
        return $html;
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

        $verifyToken = WHATSAPP_WEBHOOK_VERIFY_TOKEN;
        if ($mode === 'subscribe' && $verifyToken !== '' && hash_equals($verifyToken, $token ?? '')) {
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

            // تصحيح (2026-08-25): كان بيتحقق من الاشتراك بس بدون استهلاك
            // الرصيد - يعني حد رسائل الشات المعروض في الباقات (100/500/2000)
            // مكنش بيتفرض خالص. دلوقتي بنستهلك رصيدًا بعد نجاح الإرسال،
            // مع الرجوع للمحفظة لو الاستخدام "ادفع حسب الاستخدام".
            $creditsCheck = $this->subscription->checkChatCredits((int) $this->user['id'], 1);
            $viaWallet = $creditsCheck['source'] === 'wallet';
            $this->subscription->consumeChatCredits((int) $this->user['id'], 1, $viaWallet);

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
        if ($verifyToken === '') {
            return false;
        }
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
        $currentUserId = (int) ($this->user['id'] ?? 0);
        $body = $this->renderView('chat/index', ['currentUserId' => $currentUserId]);

        $script = '<script src="' . asset_v('/assets/js/chat/index.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_inbox', $this->tr('sidebar.chat'), $this->tr('chat.page_subtitle'), $this->applyChatUi($body), $script);
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

        $body = $this->renderView('chat/conversation', [
            'conversationId' => $conversationId,
            'currentUserId' => $currentUserId,
        ]);

        $script = '<script src="' . asset_v('/assets/js/chat/conversation.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_inbox', $this->tr('chat.conv.title'), $this->tr('chat.conv.subtitle'), $this->applyChatUi($body), $script);
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
        $body = $this->renderView('chat/pending');

        $script = '<script src="' . asset_v('/assets/js/chat/pending.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_inbox', $this->tr('chat.pending.title'), $this->tr('chat.pending.subtitle'), $this->applyChatUi($body), $script);
        exit;
    }

    /** GET /chat/settings */
    public function showSettings(array $params = []): array
    {
        $body = $this->renderView('chat/settings');

        $script = '<script src="' . asset_v('/assets/js/chat/settings.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_inbox', $this->tr('chat.settings.title'), $this->tr('chat.settings.subtitle'), $this->applyChatUi($body), $script);
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
        $body = $this->renderView('chat/knowledge_base');

        $script = '<script src="' . asset_v('/assets/js/chat/knowledge_base.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_knowledge', 'قاعدة المعرفة', 'المعلومات التي يعتمد عليها الذكاء الاصطناعي في الرد على عملائك', $this->applyChatUi($body), $script);
        exit;
    }

    /**
     * GET /chat/followup-settings
     * واجهة إعدادات المتابعة التلقائية (بند 7) - تستخدم Endpoint موجود
     * من المرحلة 3 (AiFollowupSettingsController) - صفر Endpoint جديد.
     */
    public function showFollowupSettings(array $params = []): array
    {
        $body = $this->renderView('chat/followup_settings');

        $script = '<script src="' . asset_v('/assets/js/chat/followup_settings.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_followup', 'المتابعة التلقائية', 'إعدادات الرسائل التلقائية للعملاء الذين لم يردّوا', $this->applyChatUi($body), $script);
        exit;
    }

    /**
     * GET /chat/analytics
     * لوحة تحليلات AI Chat (بند 18) - تستخدم Endpoint موجود من المرحلة 4
     * (AiAnalyticsController) - صفر Endpoint جديد.
     */
    public function showAnalytics(array $params = []): array
    {
        $body = $this->renderView('chat/analytics');

        $script = '<script src="' . asset_v('/assets/js/chat/analytics.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_analytics', 'تحليلات AI Chat', 'أداء الذكاء الاصطناعي، صحة المزودين، وحلقة التعلّم', $this->applyChatUi($body), $script);
        exit;
    }
    /**
     * GET /chat/learning
     * حلقة التعلّم (Learning Loop): مراجعة فجوات المعرفة اللي الـAI
     * مش قادر يرد عليها، إعادة مسح المحادثات المحوّلة، وتحديث حالة
     * كل فجوة (تمت الملاحظة / أُضيفت لقاعدة المعرفة / تجاهل).
     * تستخدم Endpoints موجودة (AiLearningController) - صفر Endpoint جديد.
     */
    public function showLearning(array $params = []): array
    {
        $body = $this->renderView('chat/learning');

        $script = '<script src="' . asset_v('/assets/js/chat/learning.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_learning', 'التعلّم وفجوات المعرفة', 'حلقة تعلّم الذكاء الاصطناعي: لاحظ الفجوات وعلّم النظام ليردّ أفضل في المرة القادمة', $this->applyChatUi($body), $script);
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
        $body = $this->renderView('chat/leads');

        $script = '<script src="' . asset_v('/assets/js/chat/leads.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_leads', 'Leads', 'كل العملاء المحتملين مرتّبين حسب الأولوية', $this->applyChatUi($body), $script);
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

        if ($rateError = $this->rateLimitGuard('user', 'ai', 20, 60)) {
            return $rateError;
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
        $secretKey = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) ? ENCRYPTION_KEY : '';
        if ($secretKey === '') {
            return '';
        }
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
