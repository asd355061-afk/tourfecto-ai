<?php

/**
 * Tourfecto - Ad Tracking Service (روابط UTM القابلة للتتبع)
 * بيبني روابط UTM قصيرة لكل حملة، وبيسجّل النقرات الحقيقية عبر /r/{code}
 * (رابط عام بدون تسجيل دخول - اللي بيضغط عليه زائر من إعلان)، وبيرجّع
 * قائمة الروابط بتاعة حملة معداد النقرات الفعلي.
 * @version 1.0.0
 */
class AdTrackingService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * يبني رابط UTM قصير ويحفظه للحملة. بيضمّن معاملات UTM جوا الرابط
     * نفسه (utm_source/utm_medium/utm_campaign + utm_content/utm_term اختياريين)
     * عشان التحويل النهائي يوصّل الزائر لصفحة الهبوط ومعاه بيانات التتبع.
     *
     * @return array{code:string, short_path:string, link:string, id:int, clicks:int}
     */
    public function buildLink(int $userId, AdCampaign $campaign, string $destinationUrl, string $utmSource = 'google', string $utmMedium = 'cpc', ?string $utmContent = null, ?string $utmTerm = null): array
    {
        $destinationUrl = trim($destinationUrl);
        if ($destinationUrl === '') {
            throw new InvalidArgumentException('رابط الوجهة مطلوب');
        }
        if (!preg_match('#^https?://#i', $destinationUrl)) {
            $destinationUrl = 'https://' . $destinationUrl;
        }
        if (mb_strlen($destinationUrl) > 900) {
            throw new InvalidArgumentException('الرابط أطول من اللازم');
        }

        $campaignName = (string) $campaign->getAttribute('name');
        $utmCampaign = $campaignName !== '' ? mb_substr($campaignName, 0, 255) : 'campaign-' . $campaign->getAttribute('id');

        $params = [
            'utm_source' => $utmSource,
            'utm_medium' => $utmMedium,
            'utm_campaign' => $utmCampaign,
        ];
        if ($utmContent !== null && trim((string) $utmContent) !== '') {
            $params['utm_content'] = trim((string) $utmContent);
        }
        if ($utmTerm !== null && trim((string) $utmTerm) !== '') {
            $params['utm_term'] = trim((string) $utmTerm);
        }

        $fullUrl = $destinationUrl . (strpos($destinationUrl, '?') !== false ? '&' : '?') . http_build_query($params);

        // كود فريد قصير - إعادة محاولة لو صادف كودًا موجود (احتمال ضئيل)
        $code = '';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = bin2hex(random_bytes(5));
            $exists = $this->db->query("SELECT id FROM ad_utm_links WHERE code = ? LIMIT 1", [$code]);
            if (empty($exists)) {
                break;
            }
        }

        $this->db->exec(
            "INSERT INTO ad_utm_links (campaign_id, code, destination_url, utm_source, utm_medium, utm_campaign, utm_content, utm_term)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                (int) $campaign->getAttribute('id'), $code, $fullUrl,
                $utmSource, $utmMedium, $utmCampaign, $utmContent, $utmTerm,
            ]
        );

        $idRow = $this->db->query("SELECT LAST_INSERT_ID() AS id");
        $id = (int) ($idRow[0]['id'] ?? 0);

        return [
            'code' => $code,
            'short_path' => '/r/' . $code,
            'short_redirect_url' => '/r/' . $code,
            'link' => $fullUrl,
            'id' => $id,
            'clicks' => 0,
        ];
    }

    /** قائمة روابط UTM لحملة معينة - الأحدث أولًا */
    public function listForCampaign(int $campaignId): array
    {
        return $this->db->query(
            "SELECT id, code, destination_url, utm_source, utm_medium, utm_campaign, utm_content, utm_term, clicks, created_at
             FROM ad_utm_links WHERE campaign_id = ? ORDER BY created_at DESC",
            [$campaignId]
        );
    }

    /**
     * يبحث عن الرابط بالكود، يسلّم النقرة (زيادة العداد)، ويرجّع وجهة
     * التحويل النهائية. بيرجّع null لو الكود مش موجود (404).
     */
    public function resolveAndTrackClick(string $code): ?string
    {
        $rows = $this->db->query("SELECT id, destination_url FROM ad_utm_links WHERE code = ? LIMIT 1", [$code]);
        if (empty($rows)) {
            return null;
        }

        $this->db->exec("UPDATE ad_utm_links SET clicks = clicks + 1 WHERE id = ?", [(int) $rows[0]['id']]);

        return (string) $rows[0]['destination_url'];
    }
}
