<?php

/**
 * Tourfecto - CRM WhatsApp Webhook Controller (بند 16)
 * @version 1.0.0
 *
 * نقطة استقبال عامة (لازم تكون بدون AuthMiddleware - Meta نفسها اللي
 * بتنادي عليها، مش المستخدم المسجّل دخول). الأمان هنا عبر Verify Token
 * الرسمي من Meta (GET) بدل جلسة مستخدم عادية - هذا هو النمط القياسي لكل
 * WhatsApp Business Webhooks.
 *
 * ملاحظة حرجة: كل حساب Tourfecto (Tenant) في المعمارية الحالية له نفس
 * إعدادات WhatsApp العامة على مستوى المنصة (SystemSettingsService - نفس
 * نمط Gemini/Mail الموجودين بالفعل)، وليس رقم WhatsApp Business منفصل لكل
 * عميل. لذلك الرسائل الواردة تُربَط بجهة الاتصال عبر رقم الهاتف المطابق
 * *لأول* حساب يملك جهة اتصال بهذا الرقم - قيد معروف موثّق في CHANGELOG
 * (نظام ربط رقم WhatsApp Business منفصل لكل Tenant يحتاج تصميم OAuth
 * إضافي خارج نطاق هذه المرحلة).
 */
class CrmWhatsAppWebhookController extends Controller
{
    /** GET /webhooks/crm/whatsapp - Verification Handshake الرسمي من Meta */
    public function verify(array $params = []): array
    {
        $mode = $_GET['hub_mode'] ?? null;
        $token = $_GET['hub_verify_token'] ?? null;
        $challenge = $_GET['hub_challenge'] ?? null;

        $result = (new CrmWhatsAppService())->verifyWebhook($mode, $token, $challenge);
        if ($result !== null) {
            header('Content-Type: text/plain');
            echo $result;
            exit;
        }

        http_response_code(403);
        echo 'Verification failed';
        exit;
    }

    /** POST /webhooks/crm/whatsapp - رسائل واردة فعلية */
    public function receive(array $params = []): array
    {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true) ?: [];

        try {
            $messages = (new CrmWhatsAppService())->parseIncomingWebhook($payload);

            foreach ($messages as $msg) {
                $this->handleIncomingMessage($msg);
            }
        } catch (Exception $e) {
            Logger::error('CrmWhatsAppWebhookController: فشل معالجة Webhook', ['message' => $e->getMessage()]);
        }

        // نرجّع 200 دايمًا لـMeta بصرف النظر عن نتيجة المعالجة الداخلية -
        // ده مطلوب رسميًا من توثيق Meta عشان محاولات إعادة الإرسال المتكررة.
        header('Content-Type: application/json');
        echo json_encode(['status' => 'received']);
        exit;
    }

    private function handleIncomingMessage(array $msg): void
    {
        $phone = preg_replace('/[^0-9]/', '', $msg['from'] ?? '');
        if (empty($phone)) {
            return;
        }

        $contactRows = $this->db->query("SELECT * FROM crm_contacts WHERE phone LIKE ? LIMIT 1", ['%' . substr($phone, -9)]);
        if (empty($contactRows)) {
            Logger::info('CrmWhatsAppWebhookController: رسالة واردة من رقم غير مسجّل', ['phone' => $phone]);
            return;
        }
        $contact = $contactRows[0];
        $userId = (int) $contact['user_id'];

        $conversation = (new CrmConversation())->findOrCreate($userId, (int) $contact['id'], 'whatsapp', $phone);

        $message = new CrmMessage([
            'conversation_id' => (int) $conversation->getAttribute('id'),
            'direction' => 'inbound',
            'body' => $msg['text'] ?? '',
            'status' => 'delivered',
            'external_message_id' => $msg['external_id'] ?? null,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
        $message->save();

        $conversation->setAttribute('last_message_at', date('Y-m-d H:i:s'));
        $conversation->setAttribute('unread_count', ((int) $conversation->getAttribute('unread_count')) + 1);
        $conversation->save();

        ActivityLog::record('crm', 'whatsapp.received', [
            'user_id' => $userId, 'subject_type' => 'crm_contacts', 'subject_id' => (int) $contact['id'],
        ]);

        (new CrmAutomationService())->trigger('whatsapp.message_received', $userId, [
            'contact_id' => (int) $contact['id'],
        ]);
    }
}
