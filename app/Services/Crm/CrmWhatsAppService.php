<?php

/**
 * Tourfecto - CRM WhatsApp Business Cloud API Client (بند 16)
 * @version 1.0.0
 *
 * تكامل حقيقي وقابل للتشغيل الفعلي مع WhatsApp Business Cloud API الرسمي
 * (Meta Graph API) - وليس Mock. القراءة من `SystemSettingsService` بنفس
 * نمط `GeminiClient` بالضبط (بند 33: لا تنشئ أنظمة مكررة)، والقيم الحساسة
 * (Access Token) مشفّرة في القاعدة ولا تظهر أبدًا في الـFrontend.
 *
 * لو الـCredentials غير موجودة، الخدمة ترمي Exception واضح بدل ما تدّعي
 * نجاح إرسال لم يحدث فعليًا (بند 16: "ولا تدّعي أنه تم اختباره فعليًا").
 * لم يتم اختبار هذا التكامل على حساب WhatsApp Business حقيقي في بيئة
 * التنفيذ هذه (لا اتصال شبكة متاح) - راجع "Tests Requiring Credentials"
 * في CHANGELOG.
 */
class CrmWhatsAppService
{
    private $settings;
    private $accessToken;
    private $phoneNumberId;
    private $apiVersion = 'v18.0';

    public function __construct()
    {
        $this->settings = new SystemSettingsService();
        $this->accessToken = $this->settings->get('crm_whatsapp_access_token', '');
        $this->phoneNumberId = $this->settings->get('crm_whatsapp_phone_number_id', '');
    }

    public function isConfigured(): bool
    {
        return $this->accessToken !== '' && $this->phoneNumberId !== '';
    }

    /**
     * إرسال رسالة نصية عبر WhatsApp Business Cloud API الرسمي.
     * @param string $toPhoneE164 رقم بصيغة دولية بدون + (مثال: 201234567890)
     */
    public function sendTextMessage(string $toPhoneE164, string $text): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'WhatsApp Business API غير مُفعّل لهذا الحساب بعد - أضف Access Token وPhone Number ID من إعدادات الأدمن أولًا',
            ];
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => preg_replace('/[^0-9]/', '', $toPhoneE164),
            'type' => 'text',
            'text' => ['body' => $text],
        ];

        return $this->post($url, $payload);
    }

    private function post(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Logger::error('CrmWhatsAppService: فشل الاتصال', ['error' => $curlError]);
            return ['success' => false, 'error' => 'تعذر الاتصال بـWhatsApp API: ' . $curlError];
        }

        $data = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($data['messages'][0]['id'])) {
            return ['success' => true, 'external_message_id' => $data['messages'][0]['id'], 'raw' => $data];
        }

        $errorMessage = $data['error']['message'] ?? ('استجابة غير متوقعة من WhatsApp API (HTTP ' . $httpCode . ')');
        Logger::error('CrmWhatsAppService: فشل الإرسال', ['http_code' => $httpCode, 'response' => $response]);
        return ['success' => false, 'error' => $errorMessage];
    }

    /**
     * التحقق من Webhook Verification Handshake الرسمي من Meta
     * (GET request بـhub.mode/hub.verify_token/hub.challenge).
     */
    public function verifyWebhook(?string $mode, ?string $token, ?string $challenge): ?string
    {
        $expectedToken = $this->settings->get('crm_whatsapp_webhook_verify_token', '');
        if ($mode === 'subscribe' && $expectedToken !== '' && $token === $expectedToken) {
            return $challenge;
        }
        return null;
    }

    /** يرسل رسالة نصية لجهة اتصال CRM، ويسجّلها في محادثة (بند 16) */
    public function sendToContact(int $userId, int $contactId, string $text): array
    {
        $contact = (new CrmContact())->find($contactId);
        if (!$contact || (int) $contact->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'جهة الاتصال غير موجودة'];
        }
        $phone = $contact->getAttribute('phone');
        if (empty($phone)) {
            return ['success' => false, 'error' => 'لا يوجد رقم هاتف مسجّل لجهة الاتصال هذه'];
        }

        $normalizedPhone = preg_replace('/[^0-9]/', '', $phone);
        $conversation = (new CrmConversation())->findOrCreate($userId, $contactId, 'whatsapp', $normalizedPhone);

        $result = $this->sendTextMessage($normalizedPhone, $text);

        $message = new CrmMessage([
            'conversation_id' => (int) $conversation->getAttribute('id'),
            'direction' => 'outbound',
            'sender_user_id' => $userId,
            'body' => $text,
            'status' => !empty($result['success']) ? 'sent' : 'failed',
            'external_message_id' => $result['external_message_id'] ?? null,
            'error' => $result['error'] ?? null,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
        $message->save();

        $conversation->setAttribute('last_message_at', date('Y-m-d H:i:s'));
        $conversation->save();

        ActivityLog::record('crm', 'whatsapp.sent', [
            'user_id' => $userId, 'subject_type' => 'crm_contacts', 'subject_id' => $contactId,
            'meta' => ['success' => !empty($result['success'])],
        ]);

        return array_merge($result, ['conversation_id' => (int) $conversation->getAttribute('id')]);
    }

    /**
     * يحلل Payload الوارد من Webhook (POST) ويرجع رسائل نصية واردة فقط
     * (يتجاهل status updates وأنواع الرسائل الأخرى غير المدعومة حاليًا).
     * @return array<int, array{from: string, text: string, external_id: string}>
     */
    public function parseIncomingWebhook(array $payload): array
    {
        $messages = [];
        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                foreach (($change['value']['messages'] ?? []) as $msg) {
                    if (($msg['type'] ?? '') === 'text') {
                        $messages[] = [
                            'from' => (string) ($msg['from'] ?? ''),
                            'text' => (string) ($msg['text']['body'] ?? ''),
                            'external_id' => (string) ($msg['id'] ?? ''),
                        ];
                    }
                }
            }
        }
        return $messages;
    }
}
