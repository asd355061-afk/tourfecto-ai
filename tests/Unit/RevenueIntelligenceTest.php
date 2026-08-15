<?php
/**
 * Tourfecto - AI Revenue Intelligence Test
 * اختبارات موديول ذكاء الإيرادات (TOURFECTO AI REVENUE INTELLIGENCE)
 * @version 1.3.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 *
 * ملاحظة مهمة (Section 22 من المتطلبات):
 * كل الخدمات هنا تُختبر عبر Pure Functions الثابتة (static) بمدخلات
 * Fixtures ثابتة، بدون أي اتصال بقاعدة بيانات حقيقية - هذه ليست
 * Production Data، فقط بيانات اختبار مصطنعة عمدًا لغرض التحقق من صحة
 * منطق الحساب (Forecast/Anomaly/Segmentation/Pipeline/Insights/Assistant).
 * لا تختبر هذه الملف أي Endpoint حي أو يقرأ من rev_revenue_records
 * الحقيقي.
 */

/**
 * Fixture/Mock فقط لأغراض الاختبار (Section 22: "اختبر Behavior باستخدام
 * Fixtures/Mocks للاختبار فقط. ولا تعتبرها Production Data.") - يمنع أي
 * محاولة اتصال بقاعدة بيانات حقيقية أثناء بناء الخدمات في هذا الملف،
 * لأن كل الاختبارات هنا تستهدف Pure Functions فقط ولا تحتاج بيانات فعلية.
 */
class RevenueDataGateway {
    public function __construct() {}
}

require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueOverviewService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueForecastService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueAnomalyService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/CustomerRevenueService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/PipelineRevenueService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueInsightService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueActionService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueAssistantService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueCacheService.php';

class RevenueIntelligenceTest {
    /** @var array */
    private $testResults = [];
    /** @var int */
    private $passed = 0;
    /** @var int */
    private $failed = 0;

    public function runAll(): void {
        echo "\n💰 AI Revenue Intelligence Tests\n";
        echo "=================================\n";

        $this->testForecastInsufficientData();
        $this->testForecastWithTrend();
        $this->testAnomalyDetection();
        $this->testAnomalyInsufficientData();
        $this->testCustomerSegmentation();
        $this->testPipelineIntelligence();
        $this->testInsightGeneration();
        $this->testNextBestActions();
        $this->testAssistantIntentMatching();
        $this->testAssistantNewIntents();
        $this->testAssistantSmartFallback();
        $this->testAssistantNoInventedAnswers();
        $this->testAssistantArabicNormalization();
        $this->testAssistantPeriodAware();
        $this->testAssistantWhatIfScenario();
        $this->testAssistantFollowUpSuggestions();
        $this->testScenarioForecast();
        $this->testPeriodToDays();
        $this->testSeasonalFactor();
        $this->testSeasonalForecast();
        $this->testGraduatedCacheTtl();
        $this->testAssistantArabicSynonyms();

        $this->printSummary();
    }

    // ============================================================
    // Section 2: REVENUE FORECASTING
    // ============================================================

    private function testForecastInsufficientData(): void {
        $this->startTest('Forecast: insufficient data -> honest message, no invented numbers');
        $series = [];
        for ($i = 0; $i < 5; $i++) {
            $series[] = ['date' => "2026-08-0{$i}", 'revenue' => 100];
        }
        $result = RevenueForecastService::computeForecast($series, 'monthly', '2026-08-09');

        $this->assertTrue($result['insufficient_data'] === true, 'Flags insufficient_data when below MIN_DATA_POINTS');
        $this->assertTrue($result['expected_revenue'] === null, 'expected_revenue is null (never invented)');
        $this->assertTrue($result['message'] === 'Not enough data for reliable forecast.', 'Exact required message is returned');
    }

    private function testForecastWithTrend(): void {
        $this->startTest('Forecast: enough data with clear upward trend');
        $series = [];
        for ($i = 0; $i < 30; $i++) {
            $series[] = ['date' => sprintf('2026-07-%02d', ($i % 28) + 1), 'revenue' => 100 + $i * 5];
        }
        $result = RevenueForecastService::computeForecast($series, 'monthly', '2026-08-09');

        $this->assertTrue($result['insufficient_data'] === false, 'Enough data points to attempt a forecast');
        $this->assertTrue($result['growth_trend'] === 'up', 'Detects upward trend correctly');
        $this->assertTrue($result['expected_revenue'] > 0, 'Produces a positive expected revenue');
        $this->assertTrue(in_array($result['confidence'], ['low', 'medium', 'high'], true), 'Confidence is one of low/medium/high');
        $this->assertTrue($result['forecast_range']['low'] <= $result['expected_revenue'], 'Range low <= expected');
        $this->assertTrue($result['forecast_range']['high'] >= $result['expected_revenue'], 'Range high >= expected');
    }

    // ============================================================
    // Section 9: REVENUE ANOMALY DETECTION
    // ============================================================

    private function testAnomalyDetection(): void {
        $this->startTest('Anomaly detection: flags an injected spike');
        $series = [];
        for ($i = 1; $i <= 20; $i++) {
            $series[] = ['date' => sprintf('2026-07-%02d', $i), 'revenue' => 100];
        }
        $series[9]['revenue'] = 1500; // شذوذ مصطنع للاختبار
        $result = RevenueAnomalyService::computeAnomalies($series);

        $this->assertTrue($result['has_data'] === true, 'Has enough data to run detection');
        $this->assertTrue(count($result['anomalies']) >= 1, 'Detects at least one anomaly');
        $found = false;
        foreach ($result['anomalies'] as $a) {
            if ($a['period'] === $series[9]['date'] && $a['type'] === 'sudden_increase') { $found = true; }
        }
        $this->assertTrue($found, 'Correctly identifies the spike day as sudden_increase');
    }

    private function testAnomalyInsufficientData(): void {
        $this->startTest('Anomaly detection: insufficient data -> no false positives');
        $series = [['date' => '2026-08-01', 'revenue' => 100], ['date' => '2026-08-02', 'revenue' => 5000]];
        $result = RevenueAnomalyService::computeAnomalies($series);
        $this->assertTrue($result['has_data'] === false, 'Refuses to compute anomalies below MIN_WINDOW');
        $this->assertTrue($result['anomalies'] === [], 'No anomalies invented from too little data');
    }

    // ============================================================
    // Section 5 & 12: CUSTOMER REVENUE INTELLIGENCE & SEGMENTATION
    // ============================================================

    private function testCustomerSegmentation(): void {
        $this->startTest('Customer segmentation: VIP / Inactive / Growing rules');

        // عميل VIP: أعلى إيراد + نشاط حديث
        $vipDeals = [['value' => 5000, 'closed_at' => '2026-07-01'], ['value' => 6000, 'closed_at' => '2026-08-01']];
        // عميل غير نشط: آخر شراء منذ أكثر من 180 يوم
        $inactiveDeals = [['value' => 300, 'closed_at' => '2025-01-01']];
        // عميل متنامي: صفقاته تكبر بمرور الوقت لكن إيراده الكلي منخفض نسبيًا
        $growingDeals = [['value' => 100, 'closed_at' => '2026-06-01'], ['value' => 400, 'closed_at' => '2026-08-01']];

        $byContact = [
            1 => ['contact_id' => 1, 'contact_name' => 'VIP Co', 'contact_email' => 'vip@x.com', 'deals' => $vipDeals, 'total' => 11000],
            2 => ['contact_id' => 2, 'contact_name' => 'Ghost Co', 'contact_email' => 'ghost@x.com', 'deals' => $inactiveDeals, 'total' => 300],
            3 => ['contact_id' => 3, 'contact_name' => 'Rising Co', 'contact_email' => 'rise@x.com', 'deals' => $growingDeals, 'total' => 500],
        ];
        $totals = [11000, 300, 500];

        $customers = CustomerRevenueService::buildCustomerRecords($byContact, $totals, '2026-08-09');
        $byId = [];
        foreach ($customers as $c) { $byId[$c['contact_id']] = $c; }

        $this->assertTrue($byId[1]['value_segment'] === 'VIP', 'Highest-revenue recently-active customer is VIP');
        $this->assertTrue($byId[2]['value_segment'] === 'Inactive', 'Customer with no purchase in 180+ days is Inactive');
        $this->assertTrue($byId[3]['revenue_trend'] === 'growing', 'Detects growing spend trend correctly');
        $this->assertTrue($byId[1]['customer_lifetime_value'] === $byId[1]['customer_revenue'], 'LTV equals realized revenue to date (no invented future value)');
    }

    // ============================================================
    // Section 6: DEAL & PIPELINE REVENUE INTELLIGENCE
    // ============================================================

    private function testPipelineIntelligence(): void {
        $this->startTest('Pipeline: weighted value, likely wins, at-risk (overdue) deals');
        $openDeals = [
            ['id' => 1, 'title' => 'Deal A', 'value' => 1000, 'probability' => 80, 'stage_win_probability' => 50, 'expected_close_date' => '2026-09-01', 'stage_name' => 'Negotiation'],
            ['id' => 2, 'title' => 'Deal B (overdue)', 'value' => 500, 'probability' => 0, 'stage_win_probability' => 20, 'expected_close_date' => '2026-07-01', 'stage_name' => 'Prospecting'],
        ];
        $pipeline = PipelineRevenueService::computePipeline($openDeals, 2000, '2026-08-09');

        $this->assertTrue($pipeline['pipeline_value'] === 1500.0, 'Pipeline value = sum of all open deal values');
        $this->assertTrue($pipeline['weighted_pipeline'] === 900.0, 'Weighted pipeline = sum(value x probability)');
        $this->assertTrue(count($pipeline['at_risk_deals']) === 1, 'Overdue open deal flagged as at-risk');
        $this->assertTrue(count($pipeline['likely_wins']) === 1, 'High-probability deal flagged as likely win');
        $this->assertTrue($pipeline['pipeline_coverage'] === 0.45, 'Pipeline coverage computed against provided actual-revenue baseline');
    }

    // ============================================================
    // Sections 3, 4 & 15: OPPORTUNITIES, RISKS, AI EXPLANATION SHAPE
    // ============================================================

    private function testInsightGeneration(): void {
        $this->startTest('Insights: every opportunity/risk has the required AI explanation shape');
        $customers = [
            ['contact_id' => 1, 'name' => 'VIP Co', 'customer_revenue' => 9000, 'purchase_frequency' => 3, 'value_segment' => 'VIP', 'revenue_trend' => 'stable', 'days_since_last_purchase' => 10],
            ['contact_id' => 2, 'name' => 'Ghost Co', 'customer_revenue' => 400, 'purchase_frequency' => 1, 'value_segment' => 'Inactive', 'revenue_trend' => 'stable', 'days_since_last_purchase' => 250],
        ];
        $opportunities = RevenueInsightService::opportunitiesFromCustomers($customers);
        $this->assertTrue(count($opportunities) >= 2, 'Generates opportunities for both VIP and inactive-reengagement');

        $overview = ['growth_percent' => -25.0, 'previous_period_revenue' => 1000, 'total_revenue' => 750];
        $risk = RevenueInsightService::riskFromOverview($overview);
        $this->assertTrue($risk !== null, 'Flags a risk when revenue drops more than 10%');

        $requiredKeys = ['type', 'category', 'title', 'finding', 'evidence', 'reasoning_summary', 'confidence', 'recommended_action'];
        $allPresent = true;
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $risk)) { $allPresent = false; }
        }
        $this->assertTrue($allPresent, 'Risk insight contains Finding/Evidence/Reasoning/Confidence/Recommended Action (section 15)');

        $noDeclineOverview = ['growth_percent' => 2.0, 'previous_period_revenue' => 1000, 'total_revenue' => 1020];
        $this->assertTrue(RevenueInsightService::riskFromOverview($noDeclineOverview) === null, 'Does not invent a risk when growth is healthy');
    }

    // ============================================================
    // Section 11: NEXT BEST REVENUE ACTION
    // ============================================================

    private function testNextBestActions(): void {
        $this->startTest('Next best actions: ranks by severity/confidence, never invents impact');
        $opportunities = [
            ['type' => 'opportunity', 'category' => 'high_value_customer', 'title' => 'X', 'finding' => 'f', 'evidence' => [], 'reasoning_summary' => 'r', 'confidence' => 'high', 'estimated_impact' => null, 'affected_area' => 'customer:1', 'recommended_action' => 'Contact them'],
        ];
        $risks = [
            ['type' => 'risk', 'category' => 'revenue_decline', 'title' => 'Y', 'finding' => 'f', 'evidence' => [], 'reasoning_summary' => 'r', 'confidence' => 'high', 'severity' => 'high', 'estimated_impact' => 500.0, 'affected_area' => 'overall_revenue', 'recommended_action' => 'Investigate'],
        ];
        $actions = RevenueActionService::rankActions($opportunities, $risks, [], 10);

        $this->assertTrue(count($actions) === 2, 'Combines opportunities and risks into a single ranked list');
        $this->assertTrue($actions[0]['source_type'] === 'risk', 'High-severity risk is ranked above a plain opportunity');
        $this->assertTrue($actions[0]['expected_impact'] === 500.0, 'Preserves real estimated_impact, never fabricates a new one');
        $this->assertTrue($actions[1]['expected_impact'] === null, 'Leaves expected_impact null when it cannot be honestly calculated');
    }

    // ============================================================
    // Section 10: AI REVENUE ASSISTANT
    // ============================================================

    private function testAssistantIntentMatching(): void {
        $this->startTest('AI Assistant: matches the required Arabic/English sample questions');
        $this->assertTrue(RevenueAssistantService::matchIntent('ليه الإيرادات قلت الشهر ده؟') === 'why_revenue_declined', 'Matches "why did revenue decrease"');
        $this->assertTrue(RevenueAssistantService::matchIntent('إيه أكبر مصادر الإيرادات؟') === 'top_revenue_sources', 'Matches "biggest revenue sources"');
        $this->assertTrue(RevenueAssistantService::matchIntent('مين العملاء الأعلى قيمة؟') === 'top_value_customers', 'Matches "highest value customers"');
        $this->assertTrue(RevenueAssistantService::matchIntent('إيه الفرص اللي ممكن تزود الإيرادات؟') === 'growth_opportunities', 'Matches "opportunities to grow revenue"');
        $this->assertTrue(RevenueAssistantService::matchIntent('هل الإيرادات ماشية في اتجاه صاعد؟') === 'is_trending_up', 'Matches "is revenue trending up"');
        $this->assertTrue(RevenueAssistantService::matchIntent('إيه المخاطر الموجودة؟') === 'current_risks', 'Matches "what risks exist"');
        $this->assertTrue(RevenueAssistantService::matchIntent('إيه المتوقع الشهر القادم؟') === 'next_month_forecast', 'Matches "what is expected next month"');
        $this->assertTrue(RevenueAssistantService::matchIntent('what is the weather today') === 'unknown', 'Unsupported/off-topic question maps to "unknown", not guessed');
    }

    private function testAssistantNewIntents(): void {
        $this->startTest('AI Assistant: matches the 4 new intents added in the assistant upgrade');
        $this->assertTrue(RevenueAssistantService::matchIntent('إيه حالة الصفقات المفتوحة؟') === 'pipeline_status', 'Matches "pipeline status" (Arabic)');
        $this->assertTrue(RevenueAssistantService::matchIntent('what is our pipeline status') === 'pipeline_status', 'Matches "pipeline status" (English)');
        $this->assertTrue(RevenueAssistantService::matchIntent('إيه تقسيم العملاء عندي؟') === 'customer_segments', 'Matches "customer segments" (Arabic)');
        $this->assertTrue(RevenueAssistantService::matchIntent('show me customer segments') === 'customer_segments', 'Matches "customer segments" (English)');
        $this->assertTrue(RevenueAssistantService::matchIntent('فيه حاجة غريبة حصلت في الإيراد؟') === 'anomaly_check', 'Matches "anything unusual" (Arabic)');
        $this->assertTrue(RevenueAssistantService::matchIntent('any unusual revenue activity') === 'anomaly_check', 'Matches "anything unusual" (English)');
        $this->assertTrue(RevenueAssistantService::matchIntent('إيه المفروض أعمله دلوقتي؟') === 'next_best_action', 'Matches "what should I do now" (Arabic)');
        $this->assertTrue(RevenueAssistantService::matchIntent('what should i do next') === 'next_best_action', 'Matches "what should I do" (English)');
    }

    private function testAssistantSmartFallback(): void {
        $this->startTest('AI Assistant: suggests related topics instead of a flat dead-end when no exact match');
        // "revenue sources" بدون صيغة مطابقة تمامًا - لازم يقترح top_revenue_sources كأقرب نية، مش يرفض جاف
        $suggestions = RevenueAssistantService::suggestClosestIntents('tell me about my revenue sources performance');
        $this->assertTrue(in_array('top_revenue_sources', $suggestions, true), 'Suggests top_revenue_sources for a loosely-related phrase');

        // سؤال عشوائي تمامًا بدون أي كلمة متعلقة - لازم يرجع مصفوفة فاضية (مفيش اقتراح مزيّف)
        $noSuggestions = RevenueAssistantService::suggestClosestIntents('xyzzy qwerty random gibberish');
        $this->assertTrue($noSuggestions === [], 'Returns no suggestions for genuinely unrelated gibberish, does not force a match');
    }

    private function testAssistantNoInventedAnswers(): void {
        $this->startTest('AI Assistant: never invents an answer for unmatched or data-less questions');
        $service = new RevenueAssistantService(new RevenueOverviewService(), new RevenueForecastService(), new RevenueInsightService(), new CustomerRevenueService());
        // سؤال لا يطابق أي Intent مدعوم - لازم يرجع "Not enough data." وليس إجابة مخترعة، وبدون محاولة اتصال DB (persist=false)
        $answer = $service->ask(0, 'random unrelated question 12345', false);
        $this->assertTrue($answer['has_data'] === false, 'Unmatched question returns has_data=false');
        $this->assertTrue($answer['finding'] === 'Not enough data.', 'Exact required fallback message for unmatched questions');
    }

    // ============================================================
    // Section 10 (v1.2.0): Arabic normalization, period-aware, what-if, follow-ups
    // ============================================================

    private function testAssistantArabicNormalization(): void {
        $this->startTest('AI Assistant: Arabic normalization (أ/ا، ى/ي، ة/ه) reaches same intent');
        $this->assertTrue(RevenueAssistantService::matchIntent('اكبر مصدر للايراد') === 'top_revenue_sources', 'Normalized spelling reaches top_revenue_sources');
        $this->assertTrue(RevenueAssistantService::matchIntent('اكبر عميل عندي') === 'top_value_customers', 'Normalized spelling reaches top_value_customers');
        $this->assertTrue(RevenueAssistantService::matchIntent('لية الايرادات انخفضت') === 'why_revenue_declined', 'Common colloquial spelling matches decline intent');
        $this->assertTrue(RevenueAssistantService::matchIntent('الوضع عامل ايه') === 'is_trending_up', 'Alif variant matches trend intent');
    }

    private function testAssistantPeriodAware(): void {
        $this->startTest('AI Assistant: detects the period from the question phrasing');
        $this->assertTrue(RevenueAssistantService::detectPeriod('إيه أكبر مصادر الإيرادات الشهر ده؟') === 'monthly', 'Detects monthly');
        $this->assertTrue(RevenueAssistantService::detectPeriod('الوضع عامل إيه الأسبوع ده؟') === 'weekly', 'Detects weekly');
        $this->assertTrue(RevenueAssistantService::detectPeriod('الربع ده ماشي إزاي؟') === 'quarterly', 'Detects quarterly');
        $this->assertTrue(RevenueAssistantService::detectPeriod('how are we doing this year?') === 'yearly', 'Detects yearly (English)');
        $this->assertTrue(RevenueAssistantService::detectPeriod('what is revenue today?') === 'daily', 'Detects daily (English)');
        $this->assertTrue(RevenueAssistantService::detectPeriod('أكبر مصادر الإيرادات؟') === 'monthly', 'Defaults to monthly when no period given');
    }

    private function testAssistantWhatIfScenario(): void {
        $this->startTest('AI Assistant: matches what-if scenario questions and extracts growth %');
        $this->assertTrue(RevenueAssistantService::matchIntent('ماذا لو زادت الإيرادات 20%؟') === 'what_if_scenario', 'Matches Arabic what-if');
        $this->assertTrue(RevenueAssistantService::matchIntent('what if revenue grows 15%') === 'what_if_scenario', 'Matches English what-if');
        $this->assertTrue(RevenueAssistantService::extractGrowthPercent('ماذا لو زادت الإيرادات 20%؟') === 20.0, 'Extracts 20% growth');
        $this->assertTrue(RevenueAssistantService::extractGrowthPercent('what if revenue grows 15%') === 15.0, 'Extracts 15% growth');
        $this->assertTrue(RevenueAssistantService::extractGrowthPercent('what if revenue improves') === 0.0, 'Defaults to 0 when no percentage');
    }

    private function testAssistantFollowUpSuggestions(): void {
        $this->startTest('AI Assistant: every answer carries follow-up question suggestions (Clari-style)');
        $followUps = RevenueAssistantService::suggestFollowUps('why_revenue_declined');
        $this->assertTrue(count($followUps) >= 3, 'Provides at least 3 follow-up suggestions');
        $this->assertTrue(is_string($followUps[0]), 'Suggestions are readable questions');
        $this->assertTrue(RevenueAssistantService::suggestFollowUps('unknown_intent') !== [], 'Unknown intent still gets generic suggestions');
    }

    private function testScenarioForecast(): void {
        $this->startTest('Forecast: what-if scenario scales the real base forecast only');
        $series = [];
        for ($i = 0; $i < 30; $i++) {
            $series[] = ['date' => sprintf('2026-07-%02d', ($i % 28) + 1), 'revenue' => 100 + $i * 5];
        }
        $base = RevenueForecastService::computeForecast($series, 'monthly', '2026-08-09');
        $scenario = RevenueForecastService::scenarioForecast($series, 'monthly', '2026-08-09', 20.0);

        $this->assertTrue($scenario['scenario'] === true, 'Flags the result as a scenario');
        $this->assertTrue($scenario['scenario_growth_percent'] === 20.0, 'Keeps the assumed growth percentage');
        $this->assertTrue($scenario['base_expected_revenue'] === $base['expected_revenue'], 'Base revenue comes from the real forecast, never invented');
        $this->assertTrue(abs($scenario['expected_revenue'] - ($base['expected_revenue'] * 1.2)) < 0.01, 'Applies exactly the assumed growth to the real base');
        $this->assertTrue($scenario['forecast_range']['low'] <= $scenario['expected_revenue'], 'Range low <= expected');
        $this->assertTrue($scenario['forecast_range']['high'] >= $scenario['expected_revenue'], 'Range high >= expected');
        $this->assertTrue($scenario['note'] !== '', 'Carries an honest "not a guarantee" note');
    }

    // ============================================================
    // Section 1: REVENUE OVERVIEW (period helper)
    // ============================================================

    private function testPeriodToDays(): void {
        $this->startTest('Overview: period-to-days mapping used across Daily/Weekly/Monthly/Quarterly/Yearly');
        $this->assertTrue(RevenueOverviewService::periodToDays('daily') === 1, 'daily = 1 day');
        $this->assertTrue(RevenueOverviewService::periodToDays('weekly') === 7, 'weekly = 7 days');
        $this->assertTrue(RevenueOverviewService::periodToDays('monthly') === 30, 'monthly = 30 days');
        $this->assertTrue(RevenueOverviewService::periodToDays('quarterly') === 90, 'quarterly = 90 days');
        $this->assertTrue(RevenueOverviewService::periodToDays('yearly') === 365, 'yearly = 365 days');
    }

    // ============================================================
    // v1.3.0: Seasonality, graduated cache TTL, Arabic synonyms
    // ============================================================

    private function testSeasonalFactor(): void {
        $this->startTest('Forecast: seasonal factor compares current period to the prior equivalent period');
        // نافذتان نظيفتان: الفترة السابقة كل أيامها 100، الحالية كل أيامها 150
        // (خلال 30 يوم لكل نافذة: السابقة [2026-06-10..2026-07-09]، الحالية [2026-07-10..2026-08-08])
        $series = self::buildDailySeries('2026-06-10', 30, 100);
        $series = array_merge($series, self::buildDailySeries('2026-07-10', 30, 150));

        $result = RevenueForecastService::computeSeasonalFactor($series, 'monthly', '2026-08-09');
        $this->assertTrue($result['seasonal_factor'] !== null, 'Produces a seasonal factor from enough data');
        $this->assertTrue(abs($result['seasonal_factor'] - 1.5) < 0.05, 'Current period 50% above prior period yields factor ~1.5');
        $this->assertTrue($result['has_seasonality'] === true, 'Flags seasonality when the deviation exceeds the 20% threshold');
    }

    private function testSeasonalForecast(): void {
        $this->startTest('Forecast: seasonal forecast scales the real linear forecast by the seasonal factor');
        $series = self::buildDailySeries('2026-06-10', 30, 100);
        $series = array_merge($series, self::buildDailySeries('2026-07-10', 30, 150));

        $base = RevenueForecastService::computeForecast($series, 'monthly', '2026-08-09');
        $seasonal = RevenueForecastService::seasonalForecast($series, 'monthly', '2026-08-09');

        $this->assertTrue($seasonal['insufficient_data'] === false, 'Seasonal forecast runs on sufficient data');
        $this->assertTrue($seasonal['seasonal'] === true, 'Flags that a seasonal adjustment was applied');
        $this->assertTrue($seasonal['seasonal_factor'] > 1, 'Factor above 1 for a high season');
        $this->assertTrue($seasonal['expected_revenue'] > $base['expected_revenue'], 'Seasonal forecast exceeds the plain linear forecast in a high season');
        $this->assertTrue($seasonal['forecast_range']['low'] <= $seasonal['expected_revenue'], 'Range low <= expected');
        $this->assertTrue($seasonal['forecast_range']['high'] >= $seasonal['expected_revenue'], 'Range high >= expected');
        $this->assertTrue(strpos($seasonal['seasonality_note'], 'not a full multi-year seasonal model') !== false, 'Honest note: simple comparison, not a full seasonal model');
    }

    private function testGraduatedCacheTtl(): void {
        $this->startTest('Cache: graduated TTL - shorter for fast-moving periods, longer for expensive ones');
        $this->assertTrue(RevenueCacheService::ttlForPeriod('daily') < RevenueCacheService::ttlForPeriod('monthly'), 'Daily TTL is shorter than monthly');
        $this->assertTrue(RevenueCacheService::ttlForPeriod('monthly') < RevenueCacheService::ttlForPeriod('yearly'), 'Monthly TTL is shorter than yearly');
        $this->assertTrue(RevenueCacheService::ttlForPeriod('quarterly') > RevenueCacheService::ttlForPeriod('weekly'), 'Quarterly TTL is longer than weekly');
        $this->assertTrue(RevenueCacheService::ttlForPeriod('unknown') === RevenueCacheService::DEFAULT_TTL, 'Unknown period falls back to the default monthly TTL');
    }

    private function testAssistantArabicSynonyms(): void {
        $this->startTest('AI Assistant: expanded Arabic synonyms reach the right intent');
        $this->assertTrue(RevenueAssistantService::matchIntent('أفضل زبون عندي') === 'top_value_customers', 'Matches "best customer" via زبون');
        $this->assertTrue(RevenueAssistantService::matchIntent('ليه المبيعات نزلت') === 'why_revenue_declined', 'Matches "why sales dropped" via مبيعات');
        $this->assertTrue(RevenueAssistantService::matchIntent('عايز أزود المبيعات') === 'growth_opportunities', 'Matches "want to grow sales" via المبيعات');
        $this->assertTrue(RevenueAssistantService::matchIntent('الايراد بييجي منين') === 'top_revenue_sources', 'Matches "where does revenue come from" via منين');
        $this->assertTrue(RevenueAssistantService::matchIntent('best client') === 'top_value_customers', 'Matches "best client" (English)');
        $this->assertTrue(RevenueAssistantService::matchIntent('sales forecast') === 'next_month_forecast', 'Matches "sales forecast" (English)');
    }

    // ============================================================
    // Test harness (نفس نمط باقي ملفات tests/Unit في المشروع)
    // ============================================================

    /** يولّد سلسلة أيام متتالية بقيمة ثابتة - Fixture نظيف للاختبار. */
    private static function buildDailySeries(string $fromDate, int $days, float $revenue): array {
        $start = new DateTime($fromDate);
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $series[] = ['date' => $start->format('Y-m-d'), 'revenue' => $revenue];
            $start->modify('+1 day');
        }
        return $series;
    }

    private function assertTrue(bool $condition, string $message): void {
        if ($condition) {
            $this->pass($message);
        } else {
            $this->fail($message);
        }
    }

    private function startTest(string $name): void {
        echo "\n  ▶ {$name}\n";
    }

    private function pass(string $message): void {
        echo "    ✅ {$message}\n";
        $this->passed++;
        $this->testResults[] = ['status' => 'PASS', 'message' => $message];
    }

    private function fail(string $message): void {
        echo "    ❌ {$message}\n";
        $this->failed++;
        $this->testResults[] = ['status' => 'FAIL', 'message' => $message];
    }

    private function printSummary(): void {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;

        echo "\n" . str_repeat('=', 50) . "\n";
        echo "📊 AI Revenue Intelligence Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  ✅ Passed: {$this->passed}\n";
        echo "  ❌ Failed: {$this->failed}\n";
        echo "  📝 Total: {$total}\n";
        echo "  📈 Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    $test = new RevenueIntelligenceTest();
    $test->runAll();
}
