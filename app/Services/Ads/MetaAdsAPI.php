<?php

/**
 * Tourfecto - Meta Ads Marketing API Client
 * سحب حسابات الإعلانات، الحملات، وبيانات الأداء الحقيقية (إنفاق،
 * ظهور، نقرات) من Meta Marketing API.
 * @version 1.0.0
 */
class MetaAdsAPI
{
    private string $apiVersion;
    private string $accessToken;

    public function __construct(string $accessToken)
    {
        $this->apiVersion = env('META_API_VERSION') ?: 'v25.0';
        $this->accessToken = $accessToken;
    }

    /**
     * قائمة حسابات الإعلانات المتاحة لصاحب التوكن.
     * @return array ['success'=>bool, 'accounts'=>[['id','name','currency']], 'error'=>?]
     */
    public function listAdAccounts(): array
    {
        $result = $this->get('me/adaccounts', [
            'fields' => 'id,name,account_status,currency,business_name',
        ]);

        if (!$result['success']) {
            return $result;
        }

        $accounts = array_map(function ($a) {
            return [
                'id' => $a['id'],
                'name' => $a['business_name'] ?? $a['name'] ?? $a['id'],
                'currency' => $a['currency'] ?? 'USD',
                'status' => ($a['account_status'] ?? 0) === 1 ? 'active' : 'inactive',
            ];
        }, $result['data']['data'] ?? []);

        return ['success' => true, 'accounts' => $accounts];
    }

    /**
     * كل الحملات (Campaigns) الخاصة بحساب إعلانات معيّن، مع بيانات
     * الأداء الحقيقية (insights) لكل حملة - إنفاق فعلي، ظهور، نقرات.
     * @param string $adAccountId مثال: 'act_1734399680615736'
     * @return array ['success'=>bool, 'campaigns'=>[], 'error'=>?]
     */
    public function listCampaignsWithInsights(string $adAccountId): array
    {
        $campaignsResult = $this->get("{$adAccountId}/campaigns", [
            'fields' => 'id,name,objective,status,daily_budget,start_time,stop_time',
            'limit' => 100,
        ]);

        if (!$campaignsResult['success']) {
            return $campaignsResult;
        }

        $campaigns = $campaignsResult['data']['data'] ?? [];
        $enriched = [];

        foreach ($campaigns as $c) {
            $insights = $this->get("{$c['id']}/insights", [
                'fields' => 'impressions,clicks,spend',
                'date_preset' => 'maximum',
            ]);

            $stats = ($insights['success'] && !empty($insights['data']['data'][0]))
                ? $insights['data']['data'][0]
                : ['impressions' => 0, 'clicks' => 0, 'spend' => 0];

            $enriched[] = [
                'external_campaign_id' => $c['id'],
                'name' => $c['name'],
                'objective' => $c['objective'] ?? null,
                'status' => strtolower($c['status'] ?? 'paused'),
                'daily_budget' => isset($c['daily_budget']) ? ((float) $c['daily_budget']) / 100 : null, // Meta بترجع القيمة بالسنت
                'started_at' => $c['start_time'] ?? null,
                'ended_at' => $c['stop_time'] ?? null,
                'impressions' => (int) ($stats['impressions'] ?? 0),
                'clicks' => (int) ($stats['clicks'] ?? 0),
                'spend' => (float) ($stats['spend'] ?? 0),
            ];
        }

        return ['success' => true, 'campaigns' => $enriched];
    }

    /**
     * إنشاء حملة حقيقية كاملة على Meta Ads: Campaign → Ad Set → Ad
     * Creative → Ad. بتتعمل دايمًا بحالة PAUSED (متوقفة) كإجراء أمان -
     * العميل لازم يراجعها ويفعّلها بنفسه من داخل Meta Ads Manager
     * الرسمي، عشان محدش يصرف فلوس حقيقية من غير مراجعة بشرية أخيرة.
     *
     * @param string $adAccountId مثال: 'act_123456'
     * @param string $pageId صفحة الفيسبوك اللي هتظهر عليها الإعلانات (لازم تكون متاحة لنفس التوكن)
     * @param array $campaign ['name','objective' (traffic/leads/awareness/engagement/calls),'daily_budget']
     * @param array $audience ['age_min','age_max','genders','locations' (أسماء دول عربي/انجليزي),'interests']
     * @param array $copies [['headline','description','primary_text','call_to_action','variant_label']] (نسخة إعلانية = إعلان مستقل داخل نفس الـ Ad Set لعمل A/B تلقائي)
     * @param string $destinationUrl رابط موقع العميل اللي هيوصل له الزائر
     * @return array ['success'=>bool, 'external_campaign_id'=>?, 'ad_ids'=>[], 'error'=>?]
     */
    public function createCampaign(string $adAccountId, string $pageId, array $campaign, array $audience, array $copies, string $destinationUrl, ?string $imageUrl = null): array
    {
        $objectiveMap = [
            'traffic' => 'OUTCOME_TRAFFIC', 'leads' => 'OUTCOME_LEADS',
            'awareness' => 'OUTCOME_AWARENESS', 'engagement' => 'OUTCOME_ENGAGEMENT',
            'calls' => 'OUTCOME_TRAFFIC', // Meta ملهاش outcome مخصص للمكالمات عبر الـ API العادي - أقرب حاجة traffic
        ];
        $metaObjective = $objectiveMap[$campaign['objective'] ?? 'traffic'] ?? 'OUTCOME_TRAFFIC';

        // 1) الحملة (Campaign)
        $campaignResult = $this->post("{$adAccountId}/campaigns", [
            'name' => $campaign['name'],
            'objective' => $metaObjective,
            'status' => 'PAUSED',
            'special_ad_categories' => json_encode([]),
        ]);
        if (!$campaignResult['success']) {
            return ['success' => false, 'error' => 'فشل إنشاء الحملة: ' . ($campaignResult['error'] ?? '')];
        }
        $campaignId = $campaignResult['data']['id'];

        // 2) المجموعة الإعلانية (Ad Set) - الاستهداف والميزانية بيتحطوا هنا
        $targeting = [
            'age_min' => max(13, (int) ($audience['age_min'] ?? 18)),
            'age_max' => min(65, (int) ($audience['age_max'] ?? 65)),
            'geo_locations' => ['countries' => $this->mapLocationsToCountryCodes($audience['locations'] ?? [])],
        ];
        if (!empty($audience['genders']) && $audience['genders'] !== 'all') {
            $targeting['genders'] = [$audience['genders'] === 'male' ? 1 : 2];
        }

        $adSetFields = [
            'name' => $campaign['name'] . ' - Ad Set',
            'campaign_id' => $campaignId,
            'daily_budget' => (int) round((float) ($campaign['daily_budget'] ?? 10) * 100), // Meta بتاخد القيمة بالسنت
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => 'LINK_CLICKS',
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'targeting' => json_encode($targeting),
            'status' => 'PAUSED',
        ];
        // تواريخ البداية/النهاية اللي العميل حددها وقت الإنشاء (لو موجودة)
        if (!empty($campaign['start_date'])) {
            $adSetFields['start_time'] = date('c', strtotime($campaign['start_date']));
        }
        if (!empty($campaign['end_date'])) {
            $adSetFields['end_time'] = date('c', strtotime($campaign['end_date']));
        }

        $adSetResult = $this->post("{$adAccountId}/adsets", $adSetFields);
        if (!$adSetResult['success']) {
            $this->post("{$campaignId}", ['status' => 'DELETED']); // تنظيف - مافيش داعي نسيب حملة فاضية معلّقة
            return ['success' => false, 'error' => 'فشل إنشاء المجموعة الإعلانية: ' . ($adSetResult['error'] ?? '')];
        }
        $adSetId = $adSetResult['data']['id'];

        // 3) إعلان مستقل لكل نسخة معتمدة (لحد 3 نسخ) - بيعمل A/B تلقائي جوه نفس الـ Ad Set
        $adIds = [];
        foreach (array_slice($copies, 0, 3) as $copy) {
            $linkData = [
                'link' => $destinationUrl,
                'message' => $copy['primary_text'] ?? '',
                'name' => $copy['headline'] ?? '',
                'description' => $copy['description'] ?? '',
                'call_to_action' => ['type' => $this->mapCta($copy['call_to_action'] ?? '')],
            ];
            // صورة الإعلان: Meta بتقبل رابط صورة مباشر في link_data.picture وبتجيبها بنفسها -
            // لو معندناش صورة (مفيش og:image في موقع العميل)، الإعلان هيتعمل من غير صورة (نص بس)
            // وده أضعف شكليًا لكن مش هيمنع الإنشاء.
            if ($imageUrl) {
                $linkData['picture'] = $imageUrl;
            }

            $creativeResult = $this->post("{$adAccountId}/adcreatives", [
                'name' => $campaign['name'] . ' - ' . ($copy['variant_label'] ?? 'A'),
                'object_story_spec' => json_encode(['page_id' => $pageId, 'link_data' => $linkData]),
            ]);
            if (!$creativeResult['success']) {
                continue; // نسخة واحدة فشلت - نكمل الباقي بدل ما نوقف كل حاجة
            }

            $adResult = $this->post("{$adAccountId}/ads", [
                'name' => $campaign['name'] . ' - ' . ($copy['variant_label'] ?? 'A'),
                'adset_id' => $adSetId,
                'creative' => json_encode(['creative_id' => $creativeResult['data']['id']]),
                'status' => 'PAUSED',
            ]);
            if ($adResult['success']) {
                $adIds[] = $adResult['data']['id'];
            }
        }

        if (empty($adIds)) {
            return ['success' => false, 'error' => 'اتعملت الحملة والمجموعة الإعلانية لكن فشل إنشاء كل الإعلانات - راجع الحملة يدويًا من Meta Ads Manager', 'external_campaign_id' => $campaignId, 'external_adset_id' => $adSetId];
        }

        return ['success' => true, 'external_campaign_id' => $campaignId, 'external_adset_id' => $adSetId, 'ad_ids' => $adIds];
    }

    /** تعديل حالة حملة موجودة فعلاً (ACTIVE / PAUSED) */
    public function updateCampaignStatus(string $campaignId, string $status): array
    {
        $metaStatus = strtoupper($status) === 'ACTIVE' ? 'ACTIVE' : 'PAUSED';
        $result = $this->post($campaignId, ['status' => $metaStatus]);
        return $result['success'] ? ['success' => true] : ['success' => false, 'error' => $result['error'] ?? 'فشل تعديل حالة الحملة'];
    }

    /** إلغاء/أرشفة حملة نهائيًا */
    public function deleteCampaign(string $campaignId): array
    {
        $result = $this->post($campaignId, ['status' => 'ARCHIVED']);
        return $result['success'] ? ['success' => true] : ['success' => false, 'error' => $result['error'] ?? 'فشل إلغاء الحملة'];
    }

    /** تعديل الميزانية اليومية لمجموعة إعلانية موجودة (adset_id، مش campaign_id) */
    public function updateAdSetBudget(string $adSetId, float $dailyBudgetUsd): array
    {
        $result = $this->post($adSetId, ['daily_budget' => (int) round($dailyBudgetUsd * 100)]);
        return $result['success'] ? ['success' => true] : ['success' => false, 'error' => $result['error'] ?? 'فشل تعديل الميزانية'];
    }

    /**
     * محاولة استخراج صورة تمثيلية من موقع العميل (og:image) عشان تتستخدم
     * في إعلان Meta - لو معملتش، الإعلان بيتعمل من غير صورة (نص بس).
     * @return string|null
     */
    public function fetchOgImageFromWebsite(string $url): ?string
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TourfectoBot/1.0)',
            ]);
            $html = curl_exec($ch);
            curl_close($ch);

            if (!$html) {
                return null;
            }

            if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
                return $m[1];
            }
            if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m)) {
                return $m[1];
            }
            return null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** تحويل اسم CTA العربي المستخدم عندنا لأقرب قيمة معتمدة من Meta */
    private function mapCta(string $cta): string
    {
        $map = [
            'احجز الآن' => 'BOOK_TRAVEL', 'اعرف المزيد' => 'LEARN_MORE', 'تواصل معنا' => 'CONTACT_US',
            'اشترك الآن' => 'SIGN_UP', 'تسوق الآن' => 'SHOP_NOW', 'قدّم الآن' => 'APPLY_NOW',
            'اتصل الآن' => 'CALL_NOW', 'راسلنا' => 'MESSAGE_PAGE', 'حمّل الآن' => 'DOWNLOAD',
        ];
        return $map[$cta] ?? 'LEARN_MORE';
    }

    /** خريطة مبسّطة لأسماء دول عربي/انجليزي شائعة إلى أكواد ISO اللي Meta محتاجاها - بترجع مصر افتراضيًا لو الاسم مش معروف */
    private function mapLocationsToCountryCodes(array $locations): array
    {
        $map = [
            'مصر' => 'EG', 'egypt' => 'EG', 'السعودية' => 'SA', 'saudi arabia' => 'SA',
            'الإمارات' => 'AE', 'uae' => 'AE', 'الكويت' => 'KW', 'kuwait' => 'KW',
            'قطر' => 'QA', 'qatar' => 'QA', 'البحرين' => 'BH', 'bahrain' => 'BH',
            'عمان' => 'OM', 'oman' => 'OM', 'الأردن' => 'JO', 'jordan' => 'JO',
            'لبنان' => 'LB', 'lebanon' => 'LB', 'المغرب' => 'MA', 'morocco' => 'MA',
            'تونس' => 'TN', 'tunisia' => 'TN', 'الجزائر' => 'DZ', 'algeria' => 'DZ',
            'العراق' => 'IQ', 'iraq' => 'IQ', 'ليبيا' => 'LY', 'libya' => 'LY',
            'السودان' => 'SD', 'sudan' => 'SD',
        ];
        $codes = [];
        foreach ($locations as $loc) {
            $key = mb_strtolower(trim((string) $loc));
            if (isset($map[$key])) {
                $codes[] = $map[$key];
            }
        }
        return !empty($codes) ? array_unique($codes) : ['EG'];
    }

    /**
     * طلب POST عام لـ Graph Marketing API (إنشاء موارد).
     * @return array ['success'=>bool, 'data'=>array, 'error'=>?]
     */
    private function post(string $path, array $fields = []): array
    {
        try {
            $fields['access_token'] = $this->accessToken;
            $url = "https://graph.facebook.com/{$this->apiVersion}/{$path}";

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($fields),
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                return ['success' => false, 'error' => 'cURL Error: ' . $curlError];
            }

            $data = json_decode((string) $response, true);

            if ($httpCode !== 200 || isset($data['error'])) {
                return ['success' => false, 'error' => $data['error']['message'] ?? "Meta API error (HTTP {$httpCode})"];
            }

            return ['success' => true, 'data' => $data];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Meta Ads API POST request failed', ['path' => $path, 'error' => $e->getMessage()]);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * طلب GET عام لـ Graph Marketing API.
     * @return array ['success'=>bool, 'data'=>array, 'error'=>?]
     */
    private function get(string $path, array $query = []): array
    {
        try {
            $query['access_token'] = $this->accessToken;
            $url = "https://graph.facebook.com/{$this->apiVersion}/{$path}?" . http_build_query($query);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                return ['success' => false, 'error' => 'cURL Error: ' . $curlError];
            }

            $data = json_decode($response, true);

            if ($httpCode !== 200 || isset($data['error'])) {
                return [
                    'success' => false,
                    'error' => $data['error']['message'] ?? "Meta API error (HTTP {$httpCode})",
                ];
            }

            return ['success' => true, 'data' => $data];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Meta Ads API request failed', ['path' => $path, 'error' => $e->getMessage()]);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
