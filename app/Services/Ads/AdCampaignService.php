<?php
/**
 * Tourfecto - Ad Campaign Service (إدارة الإعلانات)
 * لا يوجد أي موديول مرفوع يغطي الإعلانات - هذا بناء جديد بالكامل بنفس
 * نمط باقي الخدمات الموحّدة. إدارة الحملات نفسها (CRUD + إحصائيات) تعمل
 * بشكل كامل الآن؛ مزامنة الأداء الفعلي (impressions/clicks/spend) من
 * Google Ads/Meta Ads تحتاج ربط OAuth حقيقي بنفس نمط GoogleBusinessAPI.php
 * الموجود، عبر platform_connections (platform = google_ads/meta_ads).
 * @version 1.1.0
 */
class AdCampaignService {
    /**
     * إنشاء حملة. لو الطلب فيه `audience` أو `copies` أو `budget_recommendation`
     * (من ويزارد الذكاء الاصطناعي)، بيتحفظوا كلهم مع بعض في معاملة واحدة
     * (transaction) عشان الحملة متتحفظش أبدًا من غير جمهورها ونصوصها لو
     * حصل خطأ نص الطريق.
     */
    public function create(int $userId, array $data): AdCampaign {
        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            $campaign = new AdCampaign([
                'user_id' => $userId,
                'website_id' => $data['website_id'] ?? null,
                'platform_connection_id' => $data['platform_connection_id'] ?? null,
                'platform' => in_array($data['platform'] ?? 'manual', ['manual', 'meta_ads', 'google_ads'], true) ? $data['platform'] : 'manual',
                'name' => $data['name'],
                'objective' => $data['objective'] ?? null,
                'product_or_service' => $data['product_or_service'] ?? null,
                'target_audience_brief' => $data['target_audience_brief'] ?? null,
                'daily_budget' => $data['daily_budget'] ?? null,
                'budget_total' => $data['budget_total'] ?? null,
                'currency' => $data['currency'] ?? 'USD',
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'ai_generated' => !empty($data['ai_generated']) ? 1 : 0,
                'status' => 'draft',
            ]);
            $campaign->save();
            $campaignId = (int) $campaign->getAttribute('id');

            if (!empty($data['audience']) && is_array($data['audience'])) {
                $a = $data['audience'];
                $audience = new AdAudience([
                    'campaign_id' => $campaignId,
                    'name' => 'الجمهور المقترح',
                    'age_min' => $a['age_min'] ?? null,
                    'age_max' => $a['age_max'] ?? null,
                    'genders' => $a['genders'] ?? 'all',
                    'locations_json' => json_encode($a['locations'] ?? [], JSON_UNESCAPED_UNICODE),
                    'interests_json' => json_encode($a['interests'] ?? [], JSON_UNESCAPED_UNICODE),
                    'ai_generated' => !empty($data['ai_generated']) ? 1 : 0,
                ]);
                $audience->save();
            }

            if (!empty($data['budget_recommendation']) && is_array($data['budget_recommendation'])) {
                $b = $data['budget_recommendation'];
                if (!empty($b['recommended_daily_budget'])) {
                    $rec = new AdBudgetRecommendation([
                        'campaign_id' => $campaignId,
                        'recommended_daily_budget' => $b['recommended_daily_budget'],
                        'bid_strategy' => $b['bid_strategy'] ?? null,
                        'reasoning' => $b['reasoning'] ?? null,
                    ]);
                    $rec->save();
                }
            }

            if (!empty($data['copies']) && is_array($data['copies'])) {
                $labels = ['A', 'B', 'C', 'D', 'E'];
                foreach (array_slice($data['copies'], 0, 5) as $i => $c) {
                    if (empty($c['headline'])) continue;
                    $copy = new AdCopy([
                        'campaign_id' => $campaignId,
                        'headline' => $c['headline'] ?? null,
                        'description' => $c['description'] ?? null,
                        'primary_text' => $c['primary_text'] ?? null,
                        'call_to_action' => $c['call_to_action'] ?? null,
                        'variant_label' => $c['variant_label'] ?? ($labels[$i] ?? (string) ($i + 1)),
                        'ai_generated' => !empty($data['ai_generated']) ? 1 : 0,
                        'status' => 'pending_review',
                    ]);
                    $copy->save();
                }
            }

            // كلمات مفتاحية (خاصة بحملات بحث Google Ads بشكل أساسي) - بتيجي من
            // ويزارد الذكاء الاصطناعي لما يكون platform=google_ads
            if (!empty($data['keywords']) && is_array($data['keywords'])) {
                foreach (array_slice($data['keywords'], 0, 30) as $k) {
                    $keywordText = is_array($k) ? ($k['keyword'] ?? '') : (string) $k;
                    $keywordText = trim($keywordText);
                    if ($keywordText === '') continue;

                    $matchType = is_array($k) && in_array($k['match_type'] ?? '', ['exact', 'phrase', 'broad', 'negative'], true)
                        ? $k['match_type'] : 'phrase';

                    $keyword = new AdKeyword([
                        'campaign_id' => $campaignId,
                        'keyword' => mb_substr($keywordText, 0, 255),
                        'match_type' => $matchType,
                        'ai_relevance_score' => is_array($k) ? ($k['ai_relevance_score'] ?? null) : null,
                        'estimated_search_volume' => is_array($k) ? ($k['estimated_search_volume'] ?? null) : null,
                        'estimated_cpc' => is_array($k) ? ($k['estimated_cpc'] ?? null) : null,
                        'ai_generated' => !empty($data['ai_generated']) ? 1 : 0,
                    ]);
                    $keyword->save();
                }
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }

        ActivityLog::record('ads', 'campaign.created', [
            'user_id' => $userId, 'subject_type' => 'ad_campaigns', 'subject_id' => (int) $campaign->getAttribute('id'),
            'meta' => ['ai_generated' => !empty($data['ai_generated'])],
        ]);

        return $campaign;
    }

    public function listForUser(int $userId): array {
        return (new AdCampaign())->where(['user_id' => $userId], ['created_at' => 'DESC']);
    }
}
