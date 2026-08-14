<?php
/**
 * Tourfecto - Google Ads API Client (REST)
 * سحب حسابات الإعلانات، الحملات، وبيانات الأداء الحقيقية (إنفاق،
 * ظهور، نقرات) من Google Ads API عبر الواجهة الـ REST (متاحة رسميًا
 * من نسخة v15 وما بعدها، بدون الحاجة لمكتبة gRPC).
 * @version 1.0.0
 *
 * المتطلبات قبل ما ده يشتغل (خارج الكود، من Google Cloud + Google Ads):
 *  1) نفس مشروع Google Cloud المستخدم في GOOGLE_CLIENT_ID/SECRET، بعد
 *     تفعيل "Google Ads API" له من API Library.
 *  2) Developer Token من حساب Google Ads (Manager Account) بتاعك: من
 *     ads.google.com > Tools & Settings > Setup > API Center. الوصول
 *     الافتراضي "Test accounts" بس؛ للعمل مع حسابات عملاء حقيقية لازم
 *     تطلب "Basic access" رسمي من Google (مراجعة تستغرق أيام).
 *  3) القيم دي في .env: GOOGLE_ADS_DEVELOPER_TOKEN،
 *     GOOGLE_ADS_LOGIN_CUSTOMER_ID (اختياري - لو عندك MCC).
 */
class GoogleAdsAPI {
    private const BASE_URL = 'https://googleads.googleapis.com';

    private string $apiVersion;
    private string $accessToken;
    private string $developerToken;
    private string $loginCustomerId;

    public function __construct(string $accessToken) {
        $this->apiVersion = env('GOOGLE_ADS_API_VERSION') ?: 'v17';
        $this->accessToken = $accessToken;
        $this->developerToken = class_exists('SystemSettingsService')
            ? (new SystemSettingsService())->get('google_ads_developer_token', (string) (env('GOOGLE_ADS_DEVELOPER_TOKEN') ?: ''))
            : (string) (env('GOOGLE_ADS_DEVELOPER_TOKEN') ?: '');
        $this->loginCustomerId = class_exists('SystemSettingsService')
            ? (new SystemSettingsService())->get('google_ads_login_customer_id', (string) (env('GOOGLE_ADS_LOGIN_CUSTOMER_ID') ?: ''))
            : (string) (env('GOOGLE_ADS_LOGIN_CUSTOMER_ID') ?: '');
    }

    public function isDeveloperTokenConfigured(): bool {
        return $this->developerToken !== '';
    }

    /**
     * قائمة حسابات Google Ads (غير Manager) المتاحة لصاحب التوكن، مع
     * الاسم والعملة - عشان العميل يختار الحساب الصحيح لو عنده أكتر من واحد.
     * @return array ['success'=>bool, 'accounts'=>[['id','name','currency']], 'error'=>?]
     */
    public function listAdAccounts(): array {
        $listResult = $this->httpGet('/customers:listAccessibleCustomers');
        if (!$listResult['success']) {
            return $listResult;
        }

        $resourceNames = $listResult['data']['resourceNames'] ?? [];
        if (empty($resourceNames)) {
            return ['success' => true, 'accounts' => []];
        }

        $accounts = [];
        foreach ($resourceNames as $resourceName) {
            $customerId = str_replace('customers/', '', $resourceName);
            $detail = $this->query($customerId, 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.manager, customer.status FROM customer LIMIT 1');

            if (!$detail['success'] || empty($detail['rows'])) {
                continue; // ممكن يكون التوكن معندهوش صلاحية مباشرة على الحساب ده (يحتاج login-customer-id)
            }

            $customer = $detail['rows'][0]['customer'] ?? [];
            // بنستبعد حسابات الـ Manager (MCC) نفسها من قائمة الاختيار - العميل محتاج
            // يختار حساب إعلانات فعلي (Ad Account) تحتها، مش الحساب الإداري نفسه.
            if (!empty($customer['manager'])) {
                continue;
            }

            $accounts[] = [
                'id' => (string) $customerId,
                'name' => $customer['descriptiveName'] ?? $customerId,
                'currency' => $customer['currencyCode'] ?? 'USD',
                'status' => strtolower($customer['status'] ?? 'unknown'),
            ];
        }

        return ['success' => true, 'accounts' => $accounts];
    }

    /**
     * كل حملات البحث (Search Campaigns) الخاصة بحساب معيّن، مع أداء
     * حقيقي (إنفاق فعلي/ظهور/نقرات) خلال آخر 30 يوم.
     * @param string $customerId مثال: '1234567890' (من غير "customers/")
     * @return array ['success'=>bool, 'campaigns'=>[], 'error'=>?]
     */
    public function listCampaignsWithInsights(string $customerId): array {
        $startDate = date('Y-m-d', strtotime('-30 days'));
        $endDate = date('Y-m-d');

        $gaql = "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type,
                    campaign_budget.amount_micros, campaign.start_date, campaign.end_date,
                    metrics.impressions, metrics.clicks, metrics.cost_micros
                 FROM campaign
                 WHERE segments.date BETWEEN '{$startDate}' AND '{$endDate}'";

        $result = $this->query($customerId, $gaql);
        if (!$result['success']) {
            return $result;
        }

        // Google بترجع صف لكل يوم فيه أداء - بنجمّعهم حسب campaign.id
        $byCampaign = [];
        foreach ($result['rows'] as $row) {
            $c = $row['campaign'] ?? [];
            $id = (string) ($c['id'] ?? '');
            if ($id === '') continue;

            if (!isset($byCampaign[$id])) {
                $byCampaign[$id] = [
                    'external_campaign_id' => $id,
                    'name' => $c['name'] ?? ('Campaign ' . $id),
                    'objective' => $this->mapChannelType($c['advertisingChannelType'] ?? ''),
                    'status' => strtolower($c['status'] ?? 'paused'),
                    'daily_budget' => isset($row['campaignBudget']['amountMicros']) ? ((float) $row['campaignBudget']['amountMicros']) / 1_000_000 : null,
                    'started_at' => $c['startDate'] ?? null,
                    'ended_at' => (!empty($c['endDate']) && $c['endDate'] !== '') ? $c['endDate'] : null,
                    'impressions' => 0, 'clicks' => 0, 'spend' => 0.0,
                ];
            }

            $metrics = $row['metrics'] ?? [];
            $byCampaign[$id]['impressions'] += (int) ($metrics['impressions'] ?? 0);
            $byCampaign[$id]['clicks'] += (int) ($metrics['clicks'] ?? 0);
            $byCampaign[$id]['spend'] += ((float) ($metrics['costMicros'] ?? 0)) / 1_000_000;
        }

        return ['success' => true, 'campaigns' => array_values($byCampaign)];
    }

    private function mapChannelType(string $channelType): string {
        $map = [
            'SEARCH' => 'traffic', 'DISPLAY' => 'awareness', 'VIDEO' => 'awareness',
            'SHOPPING' => 'traffic', 'PERFORMANCE_MAX' => 'leads', 'LOCAL' => 'calls',
        ];
        return $map[$channelType] ?? 'traffic';
    }

    /**
     * تنفيذ استعلام GAQL عبر googleAds:search (بديل REST لـ searchStream،
     * أبسط للتعامل مع صفحة واحدة من النتائج زي احتياجنا هنا).
     * @return array ['success'=>bool, 'rows'=>array, 'error'=>?]
     */
    private function query(string $customerId, string $gaql): array {
        $result = $this->httpPost("/customers/{$customerId}/googleAds:search", ['query' => $gaql]);
        if (!$result['success']) {
            return $result;
        }
        return ['success' => true, 'rows' => $result['data']['results'] ?? []];
    }

    /**
     * إنشاء حملة بحث حقيقية كاملة على Google Ads: Campaign Budget →
     * Campaign → Ad Group → Keywords → Responsive Search Ad. بتتعمل
     * دايمًا بحالة PAUSED (متوقفة) كإجراء أمان - العميل لازم يراجعها
     * ويفعّلها بنفسه من داخل Google Ads الرسمي.
     *
     * @param string $customerId
     * @param array $campaign ['name','daily_budget']
     * @param array $copies [['headline','description','primary_text']] - بتتجمّع كلها في إعلان RSA واحد (Google بتفضّل كذا variation في نفس الإعلان مش إعلانات منفصلة)
     * @param array $keywords [['keyword','match_type']]
     * @param string $destinationUrl
     * @return array ['success'=>bool, 'external_campaign_id'=>?, 'error'=>?]
     */
    public function createSearchCampaign(string $customerId, array $campaign, array $copies, array $keywords, string $destinationUrl, string $bidStrategyHint = ''): array {
        $budgetMicros = (int) round((float) ($campaign['daily_budget'] ?? 10) * 1_000_000);

        // 1) ميزانية الحملة (كائن منفصل في Google Ads، بتتربط بالحملة بعدين)
        $budgetName = $campaign['name'] . ' - Budget ' . time();
        $budgetResult = $this->mutate($customerId, 'campaignBudgets', [[
            'create' => [
                'name' => $budgetName,
                'amountMicros' => (string) $budgetMicros,
                'deliveryMethod' => 'STANDARD',
            ],
        ]]);
        if (!$budgetResult['success']) {
            return ['success' => false, 'error' => 'فشل إنشاء ميزانية الحملة: ' . ($budgetResult['error'] ?? '')];
        }
        $budgetResourceName = $budgetResult['results'][0]['resourceName'];

        // 2) الحملة نفسها - نوع SEARCH، بتستخدم شبكة البحث بس (من غير Display Network) عشان نتائج أدق.
        // استراتيجية المزايدة: بنحاول نطابق توصية الذكاء الاصطناعي (bid_strategy) لأقرب استراتيجية
        // مدعومة فعليًا بدون الحاجة لبيانات تحويلات سابقة (maximize_clicks الأنسب لحملة جديدة تمامًا
        // لسه مفيهاش تاريخ تحويلات، وmaximize_conversions لو العميل عنده تتبّع تحويلات شغال بالفعل).
        $biddingField = (stripos($bidStrategyHint, 'تحويل') !== false || stripos($bidStrategyHint, 'conversion') !== false)
            ? 'maximizeConversions' : 'maximizeClicks';

        $campaignCreate = [
            'name' => $campaign['name'],
            'advertisingChannelType' => 'SEARCH',
            'status' => 'PAUSED',
            'campaignBudget' => $budgetResourceName,
            $biddingField => new stdClass(),
            'startDate' => !empty($campaign['start_date']) ? str_replace('-', '', $campaign['start_date']) : date('Ymd'),
            'networkSettings' => [
                'targetGoogleSearch' => true, 'targetSearchNetwork' => false,
                'targetContentNetwork' => false, 'targetPartnerSearchNetwork' => false,
            ],
        ];
        if (!empty($campaign['end_date'])) {
            $campaignCreate['endDate'] = str_replace('-', '', $campaign['end_date']);
        }

        $campaignResult = $this->mutate($customerId, 'campaigns', [['create' => $campaignCreate]]);
        if (!$campaignResult['success']) {
            $this->mutate($customerId, 'campaignBudgets', [['remove' => $budgetResourceName]]);
            return ['success' => false, 'error' => 'فشل إنشاء الحملة: ' . ($campaignResult['error'] ?? '')];
        }
        $campaignResourceName = $campaignResult['results'][0]['resourceName'];
        $campaignId = str_replace("customers/{$customerId}/campaigns/", '', $campaignResourceName);

        // 3) مجموعة إعلانية (Ad Group) - الكلمات المفتاحية والإعلان بيتحطوا جواها
        $adGroupResult = $this->mutate($customerId, 'adGroups', [[
            'create' => [
                'name' => $campaign['name'] . ' - Ad Group',
                'campaign' => $campaignResourceName,
                'status' => 'ENABLED',
                'type' => 'SEARCH_STANDARD',
            ],
        ]]);
        if (!$adGroupResult['success']) {
            return ['success' => false, 'error' => 'اتعملت الحملة لكن فشل إنشاء المجموعة الإعلانية - راجعها من Google Ads يدويًا: ' . ($adGroupResult['error'] ?? ''), 'external_campaign_id' => $campaignId, 'external_budget_resource' => $budgetResourceName];
        }
        $adGroupResourceName = $adGroupResult['results'][0]['resourceName'];

        // 4) الكلمات المفتاحية (لحد 20 كلمة، negative بتتحط في operation منفصل)
        $keywordOps = [];
        foreach (array_slice($keywords, 0, 20) as $k) {
            $matchType = strtoupper($k['match_type'] ?? 'PHRASE');
            $isNegative = $matchType === 'NEGATIVE';
            $keywordOps[] = ['create' => array_merge([
                'adGroup' => $adGroupResourceName,
                'status' => 'ENABLED',
                'keyword' => ['text' => $k['keyword'], 'matchType' => $isNegative ? 'PHRASE' : $matchType],
            ], $isNegative ? ['negative' => true] : [])];
        }
        if (!empty($keywordOps)) {
            $this->mutate($customerId, 'adGroupCriteria', $keywordOps); // فشل جزئي هنا مش قاطع - الحملة تقدر تشتغل من غيره
        }

        // 5) الإعلان (Responsive Search Ad) - بيجمع كل العناوين والأوصاف المعتمدة في إعلان واحد ديناميكي
        $headlines = [];
        $descriptions = [];
        foreach ($copies as $c) {
            if (!empty($c['headline'])) $headlines[] = ['text' => mb_substr($c['headline'], 0, 30)];
            if (!empty($c['description'])) $descriptions[] = ['text' => mb_substr($c['description'], 0, 90)];
            if (!empty($c['primary_text'])) $descriptions[] = ['text' => mb_substr($c['primary_text'], 0, 90)];
        }
        // Google بتطلب 3 عناوين و2 أوصاف كحد أدنى فعليًا
        if (count($headlines) < 3 || count($descriptions) < 2) {
            return ['success' => false, 'error' => 'محتاج على الأقل 3 نسخ معتمدة (عناوين) و2 أوصاف عشان ننشئ إعلان Google - اعتمد نسخ أكتر وحاول تاني', 'external_campaign_id' => $campaignId, 'external_budget_resource' => $budgetResourceName];
        }

        $adResult = $this->mutate($customerId, 'adGroupAds', [[
            'create' => [
                'adGroup' => $adGroupResourceName,
                'status' => 'PAUSED',
                'ad' => [
                    'finalUrls' => [$destinationUrl],
                    'responsiveSearchAd' => ['headlines' => array_slice($headlines, 0, 15), 'descriptions' => array_slice($descriptions, 0, 4)],
                ],
            ],
        ]]);
        if (!$adResult['success']) {
            return ['success' => false, 'error' => 'اتعملت الحملة والكلمات لكن فشل إنشاء الإعلان نفسه - راجعها من Google Ads يدويًا: ' . ($adResult['error'] ?? ''), 'external_campaign_id' => $campaignId, 'external_budget_resource' => $budgetResourceName];
        }

        return ['success' => true, 'external_campaign_id' => $campaignId, 'external_budget_resource' => $budgetResourceName];
    }

    /** تعديل حالة حملة موجودة (ENABLED / PAUSED) */
    public function updateCampaignStatus(string $customerId, string $campaignId, string $status): array {
        $googleStatus = strtoupper($status) === 'ENABLED' ? 'ENABLED' : 'PAUSED';
        $result = $this->mutate($customerId, 'campaigns', [[
            'update' => ['resourceName' => "customers/{$customerId}/campaigns/{$campaignId}", 'status' => $googleStatus],
            'updateMask' => 'status',
        ]]);
        return $result['success'] ? ['success' => true] : ['success' => false, 'error' => $result['error'] ?? 'فشل تعديل حالة الحملة'];
    }

    /** إلغاء حملة نهائيًا (Google معندهاش "حذف" فعلي - أقرب حاجة status=REMOVED) */
    public function deleteCampaign(string $customerId, string $campaignId): array {
        $result = $this->mutate($customerId, 'campaigns', [[
            'update' => ['resourceName' => "customers/{$customerId}/campaigns/{$campaignId}", 'status' => 'REMOVED'],
            'updateMask' => 'status',
        ]]);
        return $result['success'] ? ['success' => true] : ['success' => false, 'error' => $result['error'] ?? 'فشل إلغاء الحملة'];
    }

    /** تعديل الميزانية اليومية عبر resource name المحفوظ وقت الإنشاء (مثال: customers/123/campaignBudgets/456) */
    public function updateBudget(string $budgetResourceName, float $dailyBudgetUsd): array {
        if (!preg_match('#^customers/(\d+)/campaignBudgets/#', $budgetResourceName, $m)) {
            return ['success' => false, 'error' => 'رقم الميزانية غير صالح'];
        }
        $customerId = $m[1];
        $result = $this->mutate($customerId, 'campaignBudgets', [[
            'update' => ['resourceName' => $budgetResourceName, 'amountMicros' => (string) (int) round($dailyBudgetUsd * 1_000_000)],
            'updateMask' => 'amount_micros',
        ]]);
        return $result['success'] ? ['success' => true] : ['success' => false, 'error' => $result['error'] ?? 'فشل تعديل الميزانية'];
    }

    /**
     * تنفيذ عمليات Create/Remove عبر REST mutate endpoint العام (بديل REST لكل resource:mutate في Google Ads API).
     * @return array ['success'=>bool, 'results'=>array, 'error'=>?]
     */
    private function mutate(string $customerId, string $resource, array $operations): array {
        $result = $this->httpPost("/customers/{$customerId}/{$resource}:mutate", ['operations' => $operations]);
        if (!$result['success']) {
            return $result;
        }
        return ['success' => true, 'results' => $result['data']['results'] ?? []];
    }

    private function httpGet(string $path): array {
        return $this->request('GET', $path, null);
    }

    private function httpPost(string $path, array $body): array {
        return $this->request('POST', $path, $body);
    }

    private function request(string $method, string $path, ?array $body): array {
        if (!$this->isDeveloperTokenConfigured()) {
            return ['success' => false, 'error' => 'GOOGLE_ADS_DEVELOPER_TOKEN غير مضبوط في إعدادات النظام'];
        }

        try {
            $url = self::BASE_URL . '/' . $this->apiVersion . $path;

            $headers = [
                'Authorization: Bearer ' . $this->accessToken,
                'developer-token: ' . $this->developerToken,
                'Content-Type: application/json',
            ];
            if ($this->loginCustomerId !== '') {
                $headers[] = 'login-customer-id: ' . preg_replace('/[^0-9]/', '', $this->loginCustomerId);
            }

            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CUSTOMREQUEST => $method,
            ];
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
            }
            curl_setopt_array($ch, $opts);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                return ['success' => false, 'error' => 'cURL Error: ' . $curlError];
            }

            $data = json_decode((string) $response, true);

            if ($httpCode !== 200) {
                $message = $data['error']['message'] ?? "Google Ads API error (HTTP {$httpCode})";
                // رسائل الخطأ الأكثر شيوعًا - بنوضحها بالعربي عشان العميل يعرف الخطوة الناقصة فعليًا
                if ($httpCode === 403 && stripos((string) $message, 'developer token') !== false) {
                    $message = 'Developer Token مش مفعّل لحسابك بشكل كافي (Test access بس) - محتاج طلب Basic access رسمي من Google Ads API Center';
                }
                return ['success' => false, 'error' => $message];
            }

            return ['success' => true, 'data' => $data];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Google Ads API request failed', ['path' => $path, 'error' => $e->getMessage()]);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
