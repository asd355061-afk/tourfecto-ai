<?php
/**
 * Tourfecto - AI Revenue Assistant Service
 * @version 1.0.0
 *
 * Section 10: AI REVENUE ASSISTANT
 *
 * تصميم متعمّد: مطابقة نوايا (Intent Matching) بكلمات مفتاحية عربي/
 * إنجليزي، والإجابة دائمًا محسوبة مباشرة من الخدمات الحقيقية (Overview/
 * Forecast/Insight/Customer/Pipeline) - وليست نصًا مولّدًا بحرية من نموذج
 * لغوي قد "يخترع" رقمًا. هذا يضمن الالتزام الصارم بقاعدة الموديول:
 * "الـAI يعتمد على بيانات المشروع الحقيقية فقط. لا يخترع إجابات.
 * إذا البيانات غير كافية: Not enough data."
 */
class RevenueAssistantService {
    private RevenueOverviewService $overview;
    private RevenueForecastService $forecastService;
    private RevenueInsightService $insightService;
    private CustomerRevenueService $customerService;

    public function __construct(
        ?RevenueOverviewService $overview = null,
        ?RevenueForecastService $forecastService = null,
        ?RevenueInsightService $insightService = null,
        ?CustomerRevenueService $customerService = null
    ) {
        $this->overview = $overview ?? new RevenueOverviewService();
        $this->forecastService = $forecastService ?? new RevenueForecastService();
        $this->insightService = $insightService ?? new RevenueInsightService();
        $this->customerService = $customerService ?? new CustomerRevenueService();
    }

    public function ask(int $userId, string $question, bool $persist = true): array {
        $intent = self::matchIntent($question);
        $answer = $this->answerIntent($userId, $intent);

        if ($persist) {
            try {
                (new RevaiAiQuery([
                    'user_id' => $userId,
                    'question' => mb_substr($question, 0, 500),
                    'matched_intent' => $intent,
                    'answer_summary' => mb_substr($answer['finding'] ?? '', 0, 1000),
                    'confidence' => $answer['confidence'] ?? null,
                    'had_enough_data' => !empty($answer['has_data']) ? 1 : 0,
                ]))->save();
            } catch (Exception $e) {
                if (class_exists('Logger')) {
                    Logger::error('RevenueAssistantService: failed to log query', ['message' => $e->getMessage()]);
                }
            }
        }

        return $answer + ['matched_intent' => $intent];
    }

    /** مطابقة النية - Pure function قابلة للاختبار بأمثلة نصية ثابتة. */
    public static function matchIntent(string $question): string {
        $q = mb_strtolower(trim($question));

        $patterns = [
            'why_revenue_declined' => ['ليه.*قل', 'ليه.*انخفض', 'kel|قلت.*الشهر', 'why.*(decrease|drop|declin|down|less)'],
            'top_revenue_sources' => ['أكبر مصادر', 'اكبر مصادر', 'مصادر الإيراد', 'مصادر الايراد', 'top.*(source|channel)', 'biggest.*source'],
            'top_value_customers' => ['أعلى قيمة', 'اعلى قيمة', 'عملاء.*قيمة', 'top.*(customer|client)', 'most valuable customer', 'best customer'],
            'growth_opportunities' => ['فرص', 'تزود الإيرادات', 'زيادة الإيرادات', 'opportunit', 'grow.*revenue', 'increase revenue'],
            'is_trending_up' => ['اتجاه صاعد', 'ماشية.*صاعد', 'trending up', 'is revenue up', 'going up'],
            'current_risks' => ['المخاطر', 'مخاطر موجودة', 'risk'],
            'next_month_forecast' => ['المتوقع الشهر', 'الشهر القادم', 'الشهر الجاي', 'next month', 'forecast', 'expected next'],
        ];

        foreach ($patterns as $intent => $regexes) {
            foreach ($regexes as $pattern) {
                if (@preg_match('/' . $pattern . '/u', $q) === 1) {
                    return $intent;
                }
            }
        }
        return 'unknown';
    }

    private function answerIntent(int $userId, string $intent): array {
        switch ($intent) {
            case 'why_revenue_declined':
                return $this->answerWhyRevenueDeclined($userId);
            case 'top_revenue_sources':
                return $this->answerTopRevenueSources($userId);
            case 'top_value_customers':
                return $this->answerTopValueCustomers($userId);
            case 'growth_opportunities':
                return $this->answerGrowthOpportunities($userId);
            case 'is_trending_up':
                return $this->answerTrend($userId);
            case 'current_risks':
                return $this->answerCurrentRisks($userId);
            case 'next_month_forecast':
                return $this->answerNextMonthForecast($userId);
            default:
                return [
                    'has_data' => false,
                    'confidence' => null,
                    'finding' => 'Not enough data.',
                    'evidence' => [],
                    'reasoning_summary' => "I couldn't confidently match this question to a supported revenue topic (revenue sources, top customers, opportunities, risks, trend, or forecast). Try rephrasing, or use one of the Revenue Intelligence tabs directly.",
                    'recommended_action' => null,
                ];
        }
    }

    private function answerWhyRevenueDeclined(int $userId): array {
        $risks = $this->insightService->getRisks($userId);
        $declineRisk = null;
        foreach ($risks as $r) {
            if ($r['category'] === 'revenue_decline') { $declineRisk = $r; break; }
        }
        if ($declineRisk === null) {
            $overview = $this->overview->getOverview($userId, 'monthly');
            if (!$overview['has_data']) {
                return self::insufficientData();
            }
            return [
                'has_data' => true,
                'confidence' => 'high',
                'finding' => $overview['growth_percent'] === null
                    ? 'Not enough data to compare against a previous period yet.'
                    : "Revenue did not meaningfully decline (growth: {$overview['growth_percent']}% vs previous period).",
                'evidence' => ['growth_percent' => $overview['growth_percent']],
                'reasoning_summary' => 'No revenue-decline risk was detected in the current analysis.',
                'recommended_action' => null,
            ];
        }
        return [
            'has_data' => true,
            'confidence' => $declineRisk['confidence'],
            'finding' => $declineRisk['finding'],
            'evidence' => $declineRisk['evidence'],
            'reasoning_summary' => $declineRisk['reasoning_summary'],
            'recommended_action' => $declineRisk['recommended_action'],
        ];
    }

    private function answerTopRevenueSources(int $userId): array {
        $sourceGrowth = $this->overview->getRevenueBySourceWithGrowth($userId, 'monthly');
        if (!$sourceGrowth['has_data'] || empty($sourceGrowth['sources'])) {
            return self::insufficientData();
        }
        $top = array_slice($sourceGrowth['sources'], 0, 5);
        $summary = implode(', ', array_map(static function ($s) { return "{$s['source']} ({$s['revenue']})"; }, $top));
        return [
            'has_data' => true,
            'confidence' => 'high',
            'finding' => "Top revenue sources this month: {$summary}.",
            'evidence' => ['top_sources' => $top],
            'reasoning_summary' => 'Directly aggregated from recorded revenue transactions grouped by source, this month.',
            'recommended_action' => null,
        ];
    }

    private function answerTopValueCustomers(int $userId): array {
        $intel = $this->customerService->getCustomerRevenueIntelligence($userId);
        if (!$intel['has_data']) {
            return self::insufficientData();
        }
        $top = array_slice($intel['customers'], 0, 5);
        $summary = implode(', ', array_map(static function ($c) { return "{$c['name']} ({$c['customer_revenue']})"; }, $top));
        return [
            'has_data' => true,
            'confidence' => 'high',
            'finding' => "Highest-value customers: {$summary}.",
            'evidence' => ['top_customers' => $top],
            'reasoning_summary' => 'Ranked by total realized revenue from won deals per customer.',
            'recommended_action' => 'Consider prioritizing these customers for retention and upsell attention.',
        ];
    }

    private function answerGrowthOpportunities(int $userId): array {
        $opportunities = $this->insightService->getOpportunities($userId);
        if (empty($opportunities)) {
            return self::insufficientData();
        }
        $top = array_slice($opportunities, 0, 5);
        $summary = implode(' | ', array_map(static function ($o) { return $o['title']; }, $top));
        return [
            'has_data' => true,
            'confidence' => 'medium',
            'finding' => "Top revenue opportunities right now: {$summary}.",
            'evidence' => ['opportunities' => $top],
            'reasoning_summary' => 'Derived from customer value/trend patterns and revenue-source growth in your real data.',
            'recommended_action' => 'Open the Opportunities tab for full details and recommended actions on each.',
        ];
    }

    private function answerTrend(int $userId): array {
        $overview = $this->overview->getOverview($userId, 'monthly');
        if (!$overview['has_data'] || $overview['growth_percent'] === null) {
            return self::insufficientData();
        }
        $trendWord = ['up' => 'trending up', 'down' => 'trending down', 'flat' => 'roughly flat'][$overview['growth_trend']] ?? 'unclear';
        return [
            'has_data' => true,
            'confidence' => 'high',
            'finding' => "Revenue is {$trendWord} this period: {$overview['growth_percent']}% vs the previous period ({$overview['previous_period_revenue']} -> {$overview['total_revenue']}).",
            'evidence' => ['growth_percent' => $overview['growth_percent'], 'growth_trend' => $overview['growth_trend']],
            'reasoning_summary' => 'Based on total recorded revenue this period compared to the immediately preceding period of equal length.',
            'recommended_action' => null,
        ];
    }

    private function answerCurrentRisks(int $userId): array {
        $risks = $this->insightService->getRisks($userId);
        if (empty($risks)) {
            return [
                'has_data' => true,
                'confidence' => 'medium',
                'finding' => 'No significant revenue risks detected in the current data.',
                'evidence' => [],
                'reasoning_summary' => 'Revenue decline, customer inactivity, channel decline, and pipeline weakness checks all came back clear.',
                'recommended_action' => null,
            ];
        }
        $top = array_slice($risks, 0, 5);
        $summary = implode(' | ', array_map(static function ($r) { return $r['title']; }, $top));
        return [
            'has_data' => true,
            'confidence' => 'medium',
            'finding' => "Current revenue risks: {$summary}.",
            'evidence' => ['risks' => $top],
            'reasoning_summary' => 'Derived from revenue trend, customer activity, source performance, and pipeline health in your real data.',
            'recommended_action' => 'Open the Risks tab for full detail and recommended action on each.',
        ];
    }

    private function answerNextMonthForecast(int $userId): array {
        $forecast = $this->forecastService->forecast($userId, 'monthly', false);
        if ($forecast['insufficient_data']) {
            return self::insufficientData('Not enough data for reliable forecast.');
        }
        return [
            'has_data' => true,
            'confidence' => $forecast['confidence'],
            'finding' => "Estimated revenue for the next {$forecast['period']['from']} to {$forecast['period']['to']}: {$forecast['expected_revenue']} (range: {$forecast['forecast_range']['low']}-{$forecast['forecast_range']['high']}). This is an estimate, not a guarantee.",
            'evidence' => ['expected_revenue' => $forecast['expected_revenue'], 'range' => $forecast['forecast_range'], 'data_points_used' => $forecast['data_points_used']],
            'reasoning_summary' => 'Based on a linear trend fitted to the last 90 days of recorded daily revenue.',
            'recommended_action' => null,
        ];
    }

    private static function insufficientData(string $message = 'Not enough data.'): array {
        return [
            'has_data' => false,
            'confidence' => null,
            'finding' => $message,
            'evidence' => [],
            'reasoning_summary' => null,
            'recommended_action' => null,
        ];
    }
}
