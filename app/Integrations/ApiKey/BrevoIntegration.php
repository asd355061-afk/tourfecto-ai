<?php

/**
 * Tourfecto - Brevo (Sendinblue) Integration
 * @version 1.0.0
 *
 * بوابة إرسال احترافية اختيارية لموديول تسويق البريد. لما المفتاح يكون
 * متاح في .env (BREVO_API_KEY) و BREVO_ENABLED=true، حملات تسويق البريد
 * بتتبعت عبر Brevo API بدل SMTP العادي - معدلات تسليم أعلى (deliverability)
 * وسمعة مرسل (sender reputation) أفضل، زي ما Brevo/Mailchimp بيعتمدوا على
 * بنية إرسال مخصصة.
 *
 * مميزات كسبها النظام كله من غير ما يتقيد بـ Brevo:
 *   - نظامنا بيشتغل كامل بـ SMTP لو المفتاح مش متاح (من غير أي اعتماد).
 *   - تتبع الفتح/الكليك/الإلغاء بيتم على بنيتنا التحتية (جداولنا)، مش
 *     على إحصائيات Brevo، عشان تفضل البيانات ملك المستخدم في لوحة Tourfecto.
 *   - الاشتراك/إلغاء الاشتراك بيتم في جدولنا (email_subscribers) - تحكم
 *     كامل، من غير استضافة أسماء مكررة في Brevo.
 *
 * @see app/Config/integrations.php - تسجيل المزوّد (brevo)
 * @see EmailCampaignService::resolveProvider() - اختيار البوابة وقت الإرسال
 */
class BrevoIntegration extends BaseApiKeyIntegration
{
    public function key(): string
    {
        return 'brevo';
    }

    public function authType(): string
    {
        return 'api_key';
    }

    public function isConfigured(): bool
    {
        return IntegrationManager::isConfigured('brevo');
    }

    protected function baseUrl(): string
    {
        return 'https://api.brevo.com/v3';
    }

    protected function buildHeaders(): array
    {
        $apiKey = defined('BREVO_API_KEY') ? BREVO_API_KEY : getenv('BREVO_API_KEY');
        return [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    /**
     * إرسال بريد معاملة (transactional) منفرد.
     * @return array ['success'=>bool, 'data'=>mixed, 'error'=>?string]
     */
    public function sendTransactionalEmail(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        ?string $fromEmail = null,
        ?string $fromName = null
    ): array {
        $payload = [
            'sender' => [
                'email' => $fromEmail ?: (defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'noreply@tourfecto.com'),
                'name' => $fromName ?: (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Tourfecto'),
            ],
            'to' => [['email' => $toEmail, 'name' => $toName]],
            'subject' => $subject,
            'htmlContent' => $htmlBody,
        ];

        return $this->httpRequest('POST', '/smtp/email', $payload);
    }

    public function request(string $action, array $params = [], array $context = []): array
    {
        switch ($action) {
            case 'send_transactional_email':
                return $this->sendTransactionalEmail(
                    $params['to_email'] ?? '',
                    $params['to_name'] ?? '',
                    $params['subject'] ?? '',
                    $params['html_body'] ?? '',
                    $params['from_email'] ?? null,
                    $params['from_name'] ?? null
                );
            default:
                return ['success' => false, 'data' => null, 'error' => "action '{$action}' غير مدعوم في BrevoIntegration"];
        }
    }
}
