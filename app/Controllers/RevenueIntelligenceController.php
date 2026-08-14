<?php
/**
 * Tourfecto - AI Revenue Intelligence Controller
 * @version 1.0.0
 *
 * موديول مستقل (TOURFECTO AI REVENUE INTELLIGENCE) - لا يعيد بناء CRM/
 * Analytics/Ads/Billing. يقرأ بيانات حقيقية عبر RevenueDataGateway
 * ويعرضها/يحللها فقط. الصفحة القديمة /revenue (RevenueController) تبقى
 * كما هي بدون أي تعديل - هذه صفحة/API إضافية منفصلة تمامًا.
 *
 * Tenant Isolation: كل استعلام في كل Service مقيّد بـ $this->user['id']
 * (نفس نمط باقي الـ Controllers في المشروع - AuthMiddleware يضمن وجود
 * جلسة صالحة قبل الوصول لأي Action هنا).
 */
class RevenueIntelligenceController extends Controller {
    private RevenueOverviewService $overviewService;
    private RevenueForecastService $forecastService;
    private RevenueInsightService $insightService;
    private CustomerRevenueService $customerService;
    private PipelineRevenueService $pipelineService;
    private RevenueAnomalyService $anomalyService;
    private RevenueAssistantService $assistantService;
    private RevenueActionService $actionService;
    private ExecutiveSummaryService $executiveSummaryService;
    private RevenueCacheService $cacheService;

    public function __construct() {
        parent::__construct();
        $this->overviewService = new RevenueOverviewService();
        $this->forecastService = new RevenueForecastService();
        $this->insightService = new RevenueInsightService();
        $this->customerService = new CustomerRevenueService();
        $this->pipelineService = new PipelineRevenueService();
        $this->anomalyService = new RevenueAnomalyService();
        $this->assistantService = new RevenueAssistantService();
        $this->actionService = new RevenueActionService();
        $this->executiveSummaryService = new ExecutiveSummaryService();
        $this->cacheService = new RevenueCacheService();
    }

    /** GET /revenue/intelligence - صفحة واحدة بتابات (Tabs) على الـ Client-side. */
    public function index(array $params = []): array {
        $tabs = [
            'executive' => $this->tr('revai.tab.executive'),
            'overview' => $this->tr('revai.tab.overview'),
            'forecast' => $this->tr('revai.tab.forecast'),
            'opportunities' => $this->tr('revai.tab.opportunities'),
            'risks' => $this->tr('revai.tab.risks'),
            'customers' => $this->tr('revai.tab.customers'),
            'pipeline' => $this->tr('revai.tab.pipeline'),
            'sources' => $this->tr('revai.tab.sources'),
            'anomalies' => $this->tr('revai.tab.anomalies'),
            'assistant' => $this->tr('revai.tab.assistant'),
            'reports' => $this->tr('revai.tab.reports'),
        ];

        $tabsHtml = '<div class="p-tabs" id="revaiTabs" style="margin-bottom:18px;flex-wrap:wrap;">';
        $first = true;
        foreach ($tabs as $key => $label) {
            $activeClass = $first ? ' active' : '';
            $tabsHtml .= "<a href=\"#\" data-tab=\"{$key}\" class=\"p-tab{$activeClass}\" style=\"text-decoration:none;\">{$label}</a>";
            $first = false;
        }
        $tabsHtml .= '</div>';

        $periodSelect = <<<HTML
        <select id="revaiPeriod" class="p-select" style="max-width:180px;">
            <option value="daily">{$this->tr('revai.period.daily')}</option>
            <option value="weekly">{$this->tr('revai.period.weekly')}</option>
            <option value="monthly" selected>{$this->tr('revai.period.monthly')}</option>
            <option value="quarterly">{$this->tr('revai.period.quarterly')}</option>
            <option value="yearly">{$this->tr('revai.period.yearly')}</option>
        </select>
HTML;

        $body = <<<HTML
        {$tabsHtml}
        <div style="display:flex;justify-content:flex-end;margin-bottom:14px;">{$periodSelect}</div>
        <div id="revaiPanel"><div class="p-empty">{$this->tr('common.loading')}</div></div>
HTML;

        $script = $this->pageScript();

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('revenue', $this->tr('revai.page.title'), $this->tr('revai.page.subtitle'), $body, $script);
        exit;
    }

    // ============================================================
    // API - كل Endpoint بيرجع JSON من خدمة واحدة فقط (Separation of concerns)
    // ============================================================

    /** GET /api/revenue-intelligence/overview */
    public function apiOverview(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $period = $this->validPeriod($this->get('period', 'monthly'));
        $userId = (int) $this->user['id'];
        try {
            $data = $this->cacheService->rememberOverview($userId, $period, function () use ($userId, $period) {
                return $this->overviewService->getOverview($userId, $period);
            });
            return $this->success($data);
        } catch (Throwable $e) {
            return $this->serverError('overview', $e);
        }
    }

    /** GET /api/revenue-intelligence/sources */
    public function apiSources(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $period = $this->validPeriod($this->get('period', 'monthly'));
        try {
            return $this->success($this->overviewService->getRevenueBySourceWithGrowth((int) $this->user['id'], $period));
        } catch (Throwable $e) {
            return $this->serverError('sources', $e);
        }
    }

    /** GET /api/revenue-intelligence/products - قسم 8: يفصح بصدق أن لا بيانات كافية */
    public function apiProducts(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        return $this->success($this->overviewService->getRevenueByProduct((int) $this->user['id']));
    }

    /** GET /api/revenue-intelligence/forecast */
    public function apiForecast(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $period = $this->validPeriod($this->get('period', 'monthly'));
        $userId = (int) $this->user['id'];
        try {
            $result = $this->cacheService->rememberForecast($userId, $period, function () use ($userId, $period) {
                return $this->forecastService->forecast($userId, $period, true);
            });
            return $this->success($result);
        } catch (Throwable $e) {
            return $this->serverError('forecast', $e);
        }
    }

    /** GET /api/revenue-intelligence/forecast/accuracy - يقارن توقعات سابقة بالإيراد الحقيقي اللي حصل فعلاً */
    public function apiForecastAccuracy(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            return $this->success($this->forecastService->getAccuracyHistory((int) $this->user['id']));
        } catch (Throwable $e) {
            return $this->serverError('forecast-accuracy', $e);
        }
    }

    /** GET /api/revenue-intelligence/opportunities */
    /**
     * GET /api/revenue-intelligence/opportunities
     * Section 17/18: بدون الكاش هنا كنا بنسجّل نفس الـ Opportunities في
     * revai_insights (Audit Log) في كل مرة المستخدم يفتح التاب - يعني
     * الجدول كان هيتملي بآلاف الصفوف المكررة لأتفه سبب (تحديث الصفحة).
     * دلوقتي: التسجيل بيحصل مرة واحدة فعلية لكل نافذة كاش (نفس مدة
     * Overview/Forecast)، والـ response نفسه بيتحسب لحظيًا برضه.
     */
    public function apiOpportunities(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $userId = (int) $this->user['id'];
        try {
            $opportunities = $this->insightService->getOpportunities($userId);
            $this->cacheService->rememberOncePerWindow('opportunities', $userId, function () use ($userId, $opportunities) {
                RevenueInsightPersister::persist($userId, $opportunities);
                return true;
            });
            return $this->success(['has_data' => !empty($opportunities), 'opportunities' => $opportunities]);
        } catch (Throwable $e) {
            return $this->serverError('opportunities', $e);
        }
    }

    /** GET /api/revenue-intelligence/risks */
    /** GET /api/revenue-intelligence/risks - نفس منطق تفادي التكرار المستخدم في apiOpportunities. */
    public function apiRisks(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $userId = (int) $this->user['id'];
        try {
            $risks = $this->insightService->getRisks($userId);
            $this->cacheService->rememberOncePerWindow('risks', $userId, function () use ($userId, $risks) {
                RevenueInsightPersister::persist($userId, $risks);
                return true;
            });
            return $this->success(['has_data' => !empty($risks), 'risks' => $risks]);
        } catch (Throwable $e) {
            return $this->serverError('risks', $e);
        }
    }

    /** GET /api/revenue-intelligence/anomalies */
    /** GET /api/revenue-intelligence/anomalies - نفس منطق تفادي التكرار. */
    public function apiAnomalies(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $userId = (int) $this->user['id'];
        try {
            $result = $this->anomalyService->detect($userId);
            if (!empty($result['anomalies'])) {
                $this->cacheService->rememberOncePerWindow('anomalies', $userId, function () use ($userId, $result) {
                    RevenueInsightPersister::persist(
                        $userId,
                        array_map([RevenueInsightPersister::class, 'anomalyToInsight'], $result['anomalies'])
                    );
                    return true;
                });
            }
            return $this->success($result);
        } catch (Throwable $e) {
            return $this->serverError('anomalies', $e);
        }
    }

    /**
     * GET /api/revenue-intelligence/customers?page=1&per_page=25
     * Section 18 (Pagination): customer count has no natural cap (unlike
     * Opportunities/Risks which are already bounded to ~15-20 items each
     * by design). Segmentation/percentile ranking needs the *full* dataset
     * to be computed first (percentile rank is only meaningful relative to
     * everyone), so pagination is applied to the response, not the query -
     * this keeps the payload small without breaking the ranking math. A
     * future optimization could push this down to a paginated SQL query if
     * a tenant's contact base grows very large.
     */
    public function apiCustomers(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $result = $this->customerService->getCustomerRevenueIntelligence((int) $this->user['id']);
            if (!empty($result['has_data'])) {
                $result = $this->paginate($result, 'customers');
            }
            return $this->success($result);
        } catch (Throwable $e) {
            return $this->serverError('customers', $e);
        }
    }

    /** GET /api/revenue-intelligence/segments */
    public function apiSegments(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            return $this->success($this->customerService->getSegments((int) $this->user['id']));
        } catch (Throwable $e) {
            return $this->serverError('segments', $e);
        }
    }

    /** GET /api/revenue-intelligence/pipeline */
    public function apiPipeline(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            return $this->success($this->pipelineService->getPipelineIntelligence((int) $this->user['id']));
        } catch (Throwable $e) {
            return $this->serverError('pipeline', $e);
        }
    }

    /** GET /api/revenue-intelligence/actions */
    public function apiActions(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $actions = $this->actionService->getNextBestActions((int) $this->user['id']);
            return $this->success(['has_data' => !empty($actions), 'actions' => $actions]);
        } catch (Throwable $e) {
            return $this->serverError('actions', $e);
        }
    }

    /** GET /api/revenue-intelligence/executive-summary */
    public function apiExecutiveSummary(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $userId = (int) $this->user['id'];
        try {
            $summary = $this->cacheService->rememberExecutiveSummary($userId, function () use ($userId) {
                return $this->executiveSummaryService->getSummary($userId);
            });
            ActivityLog::record('revenue_intelligence', 'executive_summary.viewed', ['user_id' => $userId]);
            return $this->success($summary);
        } catch (Throwable $e) {
            return $this->serverError('executive-summary', $e);
        }
    }

    /** POST /api/revenue-intelligence/assistant/ask { question } */
    public function apiAssistantAsk(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $question = trim((string) $this->get('question', ''));
        if ($question === '') {
            return $this->error($this->tr('revai.assistant.empty_question'), 422);
        }
        if (mb_strlen($question) > 500) {
            return $this->error('Question too long (max 500 characters)', 422);
        }
        try {
            $answer = $this->assistantService->ask((int) $this->user['id'], $question);
            return $this->success($answer);
        } catch (Throwable $e) {
            return $this->serverError('assistant', $e);
        }
    }

    /**
     * GET /api/revenue-intelligence/reports/export?type=overview|opportunities|risks|customers&format=csv
     * Section 13: REVENUE REPORTS (export). Section 17: يُسجَّل كـ Audit Log.
     * يدعم format=json (افتراضي) أو format=csv (ملف حقيقي قابل للتنزيل)،
     * وDate Range اختياري (from/to بصيغة Y-m-d) لتقرير overview.
     */
    public function apiExportReport(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $type = (string) $this->get('type', 'overview');
        $format = (string) $this->get('format', 'json');
        $userId = (int) $this->user['id'];

        $rows = null;
        $columns = [];
        switch ($type) {
            case 'overview':
                $columns = ['date', 'revenue'];
                $range = $this->resolveExportDateRange();
                if ($range !== null) {
                    $gateway = new RevenueDataGateway();
                    $series = $gateway->getDailyRevenueSeries($userId, $range['from'] . ' 00:00:00', $range['to'] . ' 23:59:59');
                    $rows = array_map(static function ($t) { return ['date' => $t['date'], 'revenue' => $t['revenue']]; }, $series);
                } else {
                    $overview = $this->overviewService->getOverview($userId, $this->validPeriod($this->get('period', 'monthly')));
                    $rows = array_map(static function ($t) { return ['date' => $t['date'], 'revenue' => $t['revenue']]; }, $overview['daily_trend']);
                }
                break;
            case 'opportunities':
                $columns = ['title', 'confidence', 'estimated_impact', 'recommended_action'];
                $rows = array_map(static function ($o) {
                    return ['title' => $o['title'], 'confidence' => $o['confidence'], 'estimated_impact' => $o['estimated_impact'], 'recommended_action' => $o['recommended_action']];
                }, $this->insightService->getOpportunities($userId));
                break;
            case 'risks':
                $columns = ['title', 'severity', 'confidence', 'recommended_action'];
                $rows = array_map(static function ($r) {
                    return ['title' => $r['title'], 'severity' => $r['severity'] ?? '', 'confidence' => $r['confidence'], 'recommended_action' => $r['recommended_action']];
                }, $this->insightService->getRisks($userId));
                break;
            case 'customers':
                $columns = ['name', 'customer_revenue', 'segment', 'last_purchase'];
                $intel = $this->customerService->getCustomerRevenueIntelligence($userId);
                $rows = $intel['has_data'] ? array_map(static function ($c) {
                    return ['name' => $c['name'], 'customer_revenue' => $c['customer_revenue'], 'segment' => $c['value_segment'], 'last_purchase' => $c['last_purchase']];
                }, $intel['customers']) : [];
                break;
            case 'pipeline_forecast':
                $columns = ['title', 'value', 'probability', 'expected_close_date', 'stage'];
                $pipeline = $this->pipelineService->getPipelineIntelligence($userId);
                $rows = ($pipeline['has_data'] ?? false)
                    ? array_merge($pipeline['pipeline']['likely_wins'], $pipeline['pipeline']['at_risk_deals'])
                    : [];
                break;
            default:
                return $this->error('Unknown report type', 422);
        }

        ActivityLog::record('revenue_intelligence', 'report.exported', ['user_id' => $userId, 'meta' => ['type' => $type, 'format' => $format]]);

        if ($format === 'csv') {
            $this->streamCsv($type, $columns, $rows ?? []);
            exit; // streamCsv() already sent headers + content - لازم نوقف هنا عشان الـ Router متطلعش JSON فوق الملف
        }

        if (empty($rows)) {
            return $this->success(['rows' => [], 'message' => 'Not enough data']);
        }
        return $this->success(['rows' => $rows]);
    }

    /** يقرأ ?from=Y-m-d&to=Y-m-d من الطلب لو الاتنين موجودين وصالحين، وإلا يرجّع null (نرجع لسلوك period الافتراضي). */
    private function resolveExportDateRange(): ?array {
        $from = (string) $this->get('from', '');
        $to = (string) $this->get('to', '');
        if ($from === '' || $to === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return null;
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return ['from' => $from, 'to' => $to];
    }

    /** يبني ملف CSV حقيقي (UTF-8 BOM عشان يفتح صح في Excel) ويبعته كـ Attachment، بدل رد JSON. */
    private function streamCsv(string $type, array $columns, array $rows): void {
        $filename = 'revenue-' . preg_replace('/[^a-z0-9_-]/i', '-', $type) . '-' . date('Y-m-d') . '.csv';

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
        }

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM

        if (!empty($rows)) {
            $header = $columns ?: array_keys($rows[0]);
            fputcsv($out, $header);
            foreach ($rows as $row) {
                $line = [];
                foreach ($header as $col) {
                    $line[] = $row[$col] ?? '';
                }
                fputcsv($out, $line);
            }
        } else {
            fputcsv($out, ['message']);
            fputcsv($out, ['Not enough data']);
        }

        fclose($out);
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function validPeriod(string $period): string {
        return in_array($period, ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'], true) ? $period : 'monthly';
    }

    /** Section 18 (Pagination): يقطّع أي مصفوفة داخل $result[$key] ويضيف meta.pagination. */
    private function paginate(array $result, string $key): array {
        $page = max(1, (int) $this->get('page', 1));
        $perPage = min(100, max(1, (int) $this->get('per_page', 25)));

        $items = $result[$key] ?? [];
        $total = count($items);
        $offset = ($page - 1) * $perPage;

        $result[$key] = array_slice($items, $offset, $perPage);
        $result['pagination'] = [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 1,
        ];
        return $result;
    }

    private function serverError(string $context, Throwable $e): array {
        if (class_exists('Logger')) {
            Logger::error("RevenueIntelligence[{$context}] error", ['message' => $e->getMessage()]);
        }
        return $this->error($this->tr('revai.error.generic'), 500);
    }

    /** JS الخاص بالصفحة - Tabs + Fetch لكل قسم. */
    private function pageScript(): string {
        return <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    const panel = document.getElementById('revaiPanel');
    const periodSelect = document.getElementById('revaiPeriod');
    let activeTab = 'executive';
    const charts = {};
    function destroyChart(key) { if (charts[key]) { charts[key].destroy(); delete charts[key]; } }
    function lineChart(canvasId, labels, datasets) {
        const ctx = document.getElementById(canvasId);
        if (!ctx || typeof Chart === 'undefined') return;
        destroyChart(canvasId);
        charts[canvasId] = new Chart(ctx, {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { family: 'Tajawal' } } } },
                scales: { y: { beginAtZero: true } },
            },
        });
    }
    function barChart(canvasId, labels, datasets) {
        const ctx = document.getElementById(canvasId);
        if (!ctx || typeof Chart === 'undefined') return;
        destroyChart(canvasId);
        charts[canvasId] = new Chart(ctx, {
            type: 'bar',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: datasets.length > 1, position: 'bottom', labels: { boxWidth: 10, font: { family: 'Tajawal' } } } },
                scales: { y: { beginAtZero: true } },
            },
        });
    }

    function fmt(n) {
        if (n === null || n === undefined) return '-';
        return '$' + (parseFloat(n) || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
    }
    function pct(n) { return (n === null || n === undefined) ? '-' : (n > 0 ? '+' : '') + n + '%'; }
    function loadingHtml() { return `<div class="p-empty">${I18N['common.loading']}</div>`; }
    function emptyHtml(msg) { return `<div class="p-card"><div class="p-empty">${esc(msg || I18N['common.no_records_yet'])}</div></div>`; }

    function badge(text, color) {
        return `<span style="display:inline-block;padding:2px 10px;border-radius:20px;font-size:12px;background:${color}20;color:${color};font-weight:600;">${esc(text)}</span>`;
    }
    const confColor = { high: '#22C55E', medium: '#F59E0B', low: '#EF4444' };
    const sevColor = { high: '#EF4444', medium: '#F59E0B', low: '#6366F1' };

    function insightCard(item, kind) {
        const color = kind === 'risk' ? (sevColor[item.severity] || '#6366F1') : (confColor[item.confidence] || '#6366F1');
        return `<div class="p-card" style="margin-bottom:12px;border-inline-start:4px solid ${color};">
            <div style="display:flex;justify-content:space-between;align-items:start;gap:10px;flex-wrap:wrap;">
                <h4 style="margin:0;">${esc(item.title)}</h4>
                <div style="display:flex;gap:6px;">${item.severity ? badge(item.severity, sevColor[item.severity] || '#6366F1') : ''}${badge(item.confidence, confColor[item.confidence] || '#6366F1')}</div>
            </div>
            <p style="margin:8px 0;color:var(--text-muted,#666);">${esc(item.finding)}</p>
            ${item.reasoning_summary ? `<p style="margin:4px 0;font-size:13px;opacity:.8;">${esc(item.reasoning_summary)}</p>` : ''}
            ${item.estimated_impact ? `<p style="margin:4px 0;font-size:13px;"><b>${I18N['revai.estimated_impact']}:</b> ${fmt(item.estimated_impact)}</p>` : ''}
            <p style="margin:8px 0 0;font-size:13px;"><b>${I18N['revai.recommended_action']}:</b> ${esc(item.recommended_action || '-')}</p>
        </div>`;
    }

    async function renderExecutive() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/executive-summary');
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        const d = res.data;
        panel.innerHTML = `
            <div class="p-grid cols-3" style="margin-bottom:18px;">
                <div class="p-card stat-tile"><div class="stat-icon green">💰</div><div class="stat-info"><div class="stat-value">${fmt(d.current_revenue)}</div><div class="stat-label">${I18N['revai.exec.current_revenue']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-icon purple">📈</div><div class="stat-info"><div class="stat-value">${pct(d.growth_percent)}</div><div class="stat-label">${I18N['revai.exec.growth']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-icon blue">🔮</div><div class="stat-info"><div class="stat-value">${d.forecast ? fmt(d.forecast.expected_revenue) : '-'}</div><div class="stat-label">${I18N['revai.exec.forecast']}</div></div></div>
            </div>
            <div class="p-grid cols-2">
                <div class="p-card"><h4>${I18N['revai.exec.top_opportunity']}</h4><p>${d.top_opportunity ? esc(d.top_opportunity.title) + ' — ' + esc(d.top_opportunity.recommended_action) : I18N['common.no_records_yet']}</p></div>
                <div class="p-card"><h4>${I18N['revai.exec.top_risk']}</h4><p>${d.top_risk ? esc(d.top_risk.title) + ' — ' + esc(d.top_risk.recommended_action) : I18N['common.no_records_yet']}</p></div>
                <div class="p-card"><h4>${I18N['revai.exec.top_segment']}</h4><p>${d.top_customer_segment ? esc(d.top_customer_segment.segment) + ' (' + d.top_customer_segment.customer_count + ')' : I18N['common.no_records_yet']}</p></div>
                <div class="p-card"><h4>${I18N['revai.exec.top_source']}</h4><p>${d.top_revenue_source ? esc(d.top_revenue_source.source) + ' — ' + fmt(d.top_revenue_source.revenue) : I18N['common.no_records_yet']}</p></div>
            </div>
            <div class="p-card" style="margin-top:18px;"><h4>${I18N['revai.exec.recommended_actions']}</h4>
                ${(d.recommended_actions || []).map(a => `<div style="padding:8px 0;border-bottom:1px solid var(--border,#eee);"><b>${esc(a.action)}</b> — ${esc(a.reason)}</div>`).join('') || `<div class="p-empty">${I18N['common.no_records_yet']}</div>`}
            </div>`;
    }

    async function renderOverview() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/overview?period=' + periodSelect.value);
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        const d = res.data;
        if (!d.has_data) { panel.innerHTML = emptyHtml(I18N['revai.no_revenue_data']); return; }
        const warningHtml = d.mixed_currency_warning
            ? `<div class="p-card" style="border-inline-start:4px solid #EF4444;margin-bottom:14px;"><b>⚠️ ${I18N['revai.currency_warning_title']}</b><p style="margin:6px 0 0;font-size:13px;">${esc(d.mixed_currency_warning)}</p></div>`
            : '';
        panel.innerHTML = warningHtml + `
            <div class="p-grid cols-4" style="margin-bottom:18px;">
                <div class="p-card stat-tile"><div class="stat-icon green">💰</div><div class="stat-info"><div class="stat-value">${fmt(d.total_revenue)}</div><div class="stat-label">${I18N['revai.overview.total']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-icon purple">📈</div><div class="stat-info"><div class="stat-value">${pct(d.growth_percent)}</div><div class="stat-label">${I18N['revai.overview.growth']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-icon blue">🔁</div><div class="stat-info"><div class="stat-value">${d.recurring_revenue.available ? fmt(d.recurring_revenue.monthly_recurring_revenue) : '-'}</div><div class="stat-label">${I18N['revai.overview.recurring']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-icon orange">🎯</div><div class="stat-info"><div class="stat-value">${d.roas ? d.roas + 'x' : '-'}</div><div class="stat-label">${I18N['revai.overview.roas']}</div></div></div>
            </div>
            <div class="p-card" style="margin-bottom:18px;">
                <h3 style="margin:0 0 12px;">${I18N['revai.chart.daily_trend']}</h3>
                <div style="height:260px;"><canvas id="revaiDailyTrendChart"></canvas></div>
            </div>
            <div class="p-grid cols-2" style="margin-bottom:18px;align-items:start;">
                <div class="p-card"><h3 style="margin:0 0 12px;">${I18N['revai.overview.by_source']}</h3><div style="height:240px;"><canvas id="revaiSourceChart"></canvas></div></div>
                <div class="p-card no-pad"><div class="p-card-head" style="padding:16px 20px 0;"><h4>${I18N['revai.overview.by_source']}</h4></div>
                    <div class="p-table-scroll"><table class="p-table"><thead><tr><th>${I18N['revai.col.source']}</th><th>${I18N['revai.col.revenue']}</th><th>${I18N['revai.col.count']}</th></tr></thead>
                    <tbody>${d.revenue_by_source.map(s => `<tr><td>${esc(s.source)}</td><td>${fmt(s.total)}</td><td>${s.count}</td></tr>`).join('') || `<tr><td colspan="3" class="p-cell-muted">${I18N['common.no_records_yet']}</td></tr>`}</tbody></table></div>
                </div>
            </div>`;
        lineChart('revaiDailyTrendChart', d.daily_trend.map(t => t.date), [
            { label: I18N['revai.col.revenue'], data: d.daily_trend.map(t => t.revenue), borderColor: '#EFB05E', backgroundColor: 'rgba(239,176,94,0.12)', tension: 0.35, fill: true, pointRadius: 0 },
        ]);
        barChart('revaiSourceChart', d.revenue_by_source.map(s => s.source), [
            { label: I18N['revai.col.revenue'], data: d.revenue_by_source.map(s => s.total), backgroundColor: '#6366F1' },
        ]);
    }

    async function renderForecast() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/forecast?period=' + periodSelect.value);
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        const d = res.data;
        if (d.insufficient_data) { panel.innerHTML = emptyHtml(d.message); return; }
        panel.innerHTML = `
            <div class="p-grid cols-3" style="margin-bottom:18px;">
                <div class="p-card stat-tile"><div class="stat-icon blue">🔮</div><div class="stat-info"><div class="stat-value">${fmt(d.expected_revenue)}</div><div class="stat-label">${I18N['revai.forecast.expected']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-icon purple">📊</div><div class="stat-info"><div class="stat-value">${fmt(d.forecast_range.low)} - ${fmt(d.forecast_range.high)}</div><div class="stat-label">${I18N['revai.forecast.range']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-icon green">✅</div><div class="stat-info"><div class="stat-value">${esc(d.confidence)}</div><div class="stat-label">${I18N['revai.forecast.confidence']}</div></div></div>
            </div>
            <div class="p-card" style="margin-bottom:18px;">
                <h3 style="margin:0 0 4px;">${I18N['revai.chart.forecast_basis']}</h3>
                <p style="margin:0 0 12px;font-size:12px;opacity:.7;">${I18N['revai.chart.forecast_basis_hint']}</p>
                <div style="height:240px;"><canvas id="revaiForecastHistoryChart"></canvas></div>
            </div>
            <div class="p-card"><p>${esc(d.note || '')}</p><p style="font-size:13px;opacity:.7;">${I18N['revai.forecast.data_points']}: ${d.data_points_used} · ${I18N['revai.forecast.trend']}: ${esc(d.growth_trend)}</p></div>
            <div class="p-card" style="margin-top:18px;"><h3 style="margin:0 0 4px;">${I18N['revai.forecast.accuracy_title']}</h3>
                <p style="margin:0 0 12px;font-size:12px;opacity:.7;">${I18N['revai.forecast.accuracy_hint']}</p>
                <div id="revaiAccuracyBox">${loadingHtml()}</div>
            </div>`;
        const series = d.historical_series || [];
        lineChart('revaiForecastHistoryChart', series.map(p => p.date), [
            { label: I18N['revai.col.revenue'], data: series.map(p => p.revenue), borderColor: '#6366F1', backgroundColor: 'rgba(99,102,241,0.1)', tension: 0.3, fill: true, pointRadius: 0 },
        ]);

        const accBox = document.getElementById('revaiAccuracyBox');
        const accRes = await fetchJSON('/api/revenue-intelligence/forecast/accuracy');
        if (!accRes.success || !accRes.data.has_data) { accBox.innerHTML = `<div class="p-empty">${I18N['revai.forecast.no_accuracy_yet']}</div>`; return; }
        const hist = accRes.data.history;
        const avg = accRes.data.average_accuracy_percent;
        accBox.innerHTML = `
            ${avg !== null ? `<p style="margin:0 0 12px;font-weight:600;">${I18N['revai.forecast.average_accuracy']}: ${avg}%</p>` : ''}
            <div class="p-table-scroll"><table class="p-table">
                <thead><tr><th>${I18N['revai.forecast.period']}</th><th>${I18N['revai.forecast.expected']}</th><th>${I18N['revai.forecast.actual']}</th><th>${I18N['revai.forecast.accuracy']}</th><th>${I18N['revai.forecast.within_range']}</th></tr></thead>
                <tbody>${hist.map(h => `<tr>
                    <td>${h.period_start.substring(0,10)} → ${h.period_end.substring(0,10)}</td>
                    <td>${fmt(h.expected_revenue)}</td>
                    <td>${h.actual_revenue !== null ? fmt(h.actual_revenue) : '-'}</td>
                    <td>${h.accuracy_percent !== null ? h.accuracy_percent + '%' : '-'}</td>
                    <td>${h.within_range === null ? '-' : (h.within_range ? '✅' : '❌')}</td>
                </tr>`).join('')}</tbody>
            </table></div>`;
    }

    async function renderOpportunities() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/opportunities');
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        const items = res.data.opportunities || [];
        panel.innerHTML = items.length ? items.map(i => insightCard(i, 'opportunity')).join('') : emptyHtml(I18N['revai.no_opportunities']);
    }

    async function renderRisks() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/risks');
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        const items = res.data.risks || [];
        panel.innerHTML = items.length ? items.map(i => insightCard(i, 'risk')).join('') : emptyHtml(I18N['revai.no_risks']);
    }

    async function renderAnomalies() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/anomalies');
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        if (!res.data.has_data) { panel.innerHTML = emptyHtml(res.data.message); return; }
        const items = res.data.anomalies || [];
        panel.innerHTML = items.length ? items.map(a => insightCard({
            title: (a.type === 'sudden_drop' ? I18N['revai.anomaly.drop'] : I18N['revai.anomaly.increase']) + ' — ' + a.period,
            finding: `${I18N['revai.col.revenue']}: ${fmt(a.value)} (${I18N['revai.forecast.range']}: ${fmt(a.expected_range.low)} - ${fmt(a.expected_range.high)})`,
            reasoning_summary: a.reason,
            confidence: a.severity === 'high' ? 'high' : 'medium',
            severity: a.severity,
            estimated_impact: null,
            recommended_action: a.recommended_investigation,
        }, 'risk')).join('') : emptyHtml(I18N['revai.no_anomalies']);
    }

    let customersPage = 1;
    async function renderCustomers() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/customers?page=' + customersPage + '&per_page=25');
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        if (!res.data.has_data) { panel.innerHTML = emptyHtml(res.data.message); return; }
        const rows = res.data.customers || [];
        const pg = res.data.pagination || { page: 1, total_pages: 1, total: rows.length };
        panel.innerHTML = `<div class="p-card no-pad"><div class="p-table-scroll"><table class="p-table">
            <thead><tr><th>${I18N['revai.col.customer']}</th><th>${I18N['revai.col.revenue']}</th><th>${I18N['revai.col.aov']}</th><th>${I18N['revai.col.frequency']}</th><th>${I18N['revai.col.last_purchase']}</th><th>${I18N['revai.col.segment']}</th></tr></thead>
            <tbody>${rows.map(c => `<tr><td>${esc(c.name)}</td><td>${fmt(c.customer_revenue)}</td><td>${fmt(c.average_order_value)}</td><td>${c.purchase_frequency}</td><td>${c.last_purchase ? c.last_purchase.substring(0,10) : '-'}</td><td>${badge(c.value_segment, confColor.high)}</td></tr>`).join('')}</tbody>
        </table></div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;">
            <button class="p-btn" id="revaiCustPrev" ${pg.page <= 1 ? 'disabled' : ''}>&laquo;</button>
            <span style="font-size:13px;opacity:.8;">${I18N['revai.pagination.page']} ${pg.page} / ${pg.total_pages} (${pg.total})</span>
            <button class="p-btn" id="revaiCustNext" ${pg.page >= pg.total_pages ? 'disabled' : ''}>&raquo;</button>
        </div></div>`;
        const prevBtn = document.getElementById('revaiCustPrev');
        const nextBtn = document.getElementById('revaiCustNext');
        if (prevBtn) prevBtn.addEventListener('click', () => { customersPage = Math.max(1, customersPage - 1); renderCustomers(); });
        if (nextBtn) nextBtn.addEventListener('click', () => { customersPage = Math.min(pg.total_pages, customersPage + 1); renderCustomers(); });
    }

    async function renderPipeline() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/pipeline');
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        if (!res.data.has_data) { panel.innerHTML = emptyHtml(I18N['common.no_records_yet']); return; }
        const p = res.data.pipeline;
        panel.innerHTML = `
            <div class="p-grid cols-4" style="margin-bottom:18px;">
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${fmt(p.pipeline_value)}</div><div class="stat-label">${I18N['revai.pipeline.value']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${fmt(p.weighted_pipeline)}</div><div class="stat-label">${I18N['revai.pipeline.weighted']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${p.open_deals_count}</div><div class="stat-label">${I18N['revai.pipeline.open_deals']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${p.pipeline_coverage !== null ? p.pipeline_coverage + 'x' : '-'}</div><div class="stat-label">${I18N['revai.pipeline.coverage']}</div></div></div>
            </div>
            <div class="p-grid cols-2">
                <div class="p-card"><h4>${I18N['revai.pipeline.likely_wins']}</h4>${p.likely_wins.length ? p.likely_wins.map(d => `<div style="padding:6px 0;border-bottom:1px solid var(--border,#eee);">${esc(d.title)} — ${fmt(d.value)} (${d.probability}%)</div>`).join('') : `<div class="p-empty">${I18N['common.no_records_yet']}</div>`}</div>
                <div class="p-card"><h4>${I18N['revai.pipeline.at_risk']}</h4>${p.at_risk_deals.length ? p.at_risk_deals.map(d => `<div style="padding:6px 0;border-bottom:1px solid var(--border,#eee);">${esc(d.title)} — ${fmt(d.value)} (${d.days_overdue}d ${I18N['revai.pipeline.overdue']})</div>`).join('') : `<div class="p-empty">${I18N['common.no_records_yet']}</div>`}</div>
            </div>`;
    }

    async function renderSources() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/sources?period=' + periodSelect.value);
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        if (!res.data.has_data) { panel.innerHTML = emptyHtml(I18N['revai.no_revenue_data']); return; }
        const rows = res.data.sources || [];
        panel.innerHTML = `
            <div class="p-card" style="margin-bottom:18px;"><h3 style="margin:0 0 12px;">${I18N['revai.overview.by_source']}</h3><div style="height:260px;"><canvas id="revaiSourcesGrowthChart"></canvas></div></div>
            <div class="p-card no-pad"><div class="p-table-scroll"><table class="p-table">
            <thead><tr><th>${I18N['revai.col.source']}</th><th>${I18N['revai.col.revenue']}</th><th>${I18N['revai.col.growth']}</th></tr></thead>
            <tbody>${rows.map(s => `<tr><td>${esc(s.source)}</td><td>${fmt(s.revenue)}</td><td>${pct(s.revenue_growth_percent)}</td></tr>`).join('')}</tbody>
        </table></div></div>`;
        barChart('revaiSourcesGrowthChart', rows.map(s => s.source), [
            { label: I18N['revai.col.revenue'], data: rows.map(s => s.revenue), backgroundColor: '#6366F1' },
        ]);
    }

    async function renderAssistant() {
        panel.innerHTML = `
            <div class="p-card">
                <div style="display:flex;gap:8px;">
                    <input type="text" id="revaiQuestion" class="p-input" placeholder="${I18N['revai.assistant.placeholder']}" style="flex:1;">
                    <button class="p-btn primary" id="revaiAskBtn">${I18N['revai.assistant.ask']}</button>
                </div>
                <div id="revaiAnswers" style="margin-top:16px;"></div>
            </div>`;
        const input = document.getElementById('revaiQuestion');
        const btn = document.getElementById('revaiAskBtn');
        const answers = document.getElementById('revaiAnswers');

        async function ask() {
            const q = input.value.trim();
            if (!q) return;
            btn.disabled = true;
            const res = await fetchJSON('/api/revenue-intelligence/assistant/ask', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ question: q })
            });
            btn.disabled = false;
            if (!res.success) { toast(res.error || I18N['common.load_failed'], 'error'); return; }
            const d = res.data;
            const block = document.createElement('div');
            block.className = 'p-card';
            block.style.marginBottom = '10px';
            block.innerHTML = `<p style="font-weight:600;">${esc(q)}</p><p>${esc(d.finding)}</p>${d.recommended_action ? `<p style="font-size:13px;opacity:.8;"><b>${I18N['revai.recommended_action']}:</b> ${esc(d.recommended_action)}</p>` : ''}`;
            answers.prepend(block);
            input.value = '';
        }
        btn.addEventListener('click', ask);
        input.addEventListener('keydown', e => { if (e.key === 'Enter') ask(); });
    }

    function renderReports() {
        panel.innerHTML = `
            <div class="p-card">
                <div class="p-grid cols-4" style="align-items:end;">
                    <div><label class="form-label">${I18N['revai.reports.type']}</label>
                        <select id="revaiReportType" class="p-select">
                            <option value="overview">${I18N['revai.tab.overview']}</option>
                            <option value="opportunities">${I18N['revai.tab.opportunities']}</option>
                            <option value="risks">${I18N['revai.tab.risks']}</option>
                            <option value="customers">${I18N['revai.tab.customers']}</option>
                            <option value="pipeline_forecast">${I18N['revai.tab.pipeline']}</option>
                        </select>
                    </div>
                    <div><label class="form-label">${I18N['revai.reports.from']}</label><input type="date" id="revaiReportFrom" class="p-input"></div>
                    <div><label class="form-label">${I18N['revai.reports.to']}</label><input type="date" id="revaiReportTo" class="p-input"></div>
                    <div><button class="p-btn primary" id="revaiExportCsvBtn" style="width:100%;">${I18N['revai.reports.download_csv']}</button></div>
                </div>
                <p style="font-size:12px;opacity:.7;margin-top:8px;">${I18N['revai.reports.date_range_hint']}</p>
                <div id="revaiReportPreview" style="margin-top:16px;"></div>
            </div>`;

        const typeSel = document.getElementById('revaiReportType');
        const fromInput = document.getElementById('revaiReportFrom');
        const toInput = document.getElementById('revaiReportTo');
        const preview = document.getElementById('revaiReportPreview');

        function buildQuery(extra) {
            const q = new URLSearchParams({ type: typeSel.value, period: periodSelect.value, ...extra });
            if (fromInput.value) q.set('from', fromInput.value);
            if (toInput.value) q.set('to', toInput.value);
            return q.toString();
        }

        async function loadPreview() {
            preview.innerHTML = loadingHtml();
            const res = await fetchJSON('/api/revenue-intelligence/reports/export?' + buildQuery({ format: 'json' }));
            if (!res.success) { preview.innerHTML = emptyHtml(res.error); return; }
            const rows = res.data.rows || [];
            if (!rows.length) { preview.innerHTML = emptyHtml(res.data.message || I18N['common.no_records_yet']); return; }
            const cols = Object.keys(rows[0]);
            preview.innerHTML = `<div class="p-table-scroll"><table class="p-table"><thead><tr>${cols.map(c => `<th>${esc(c)}</th>`).join('')}</tr></thead>
                <tbody>${rows.slice(0, 50).map(r => `<tr>${cols.map(c => `<td>${esc(String(r[c] ?? ''))}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
        }

        typeSel.addEventListener('change', loadPreview);
        fromInput.addEventListener('change', loadPreview);
        toInput.addEventListener('change', loadPreview);
        document.getElementById('revaiExportCsvBtn').addEventListener('click', () => {
            window.location.href = '/api/revenue-intelligence/reports/export?' + buildQuery({ format: 'csv' });
        });

        loadPreview();
    }

    const renderers = {
        executive: renderExecutive, overview: renderOverview, forecast: renderForecast,
        opportunities: renderOpportunities, risks: renderRisks, customers: renderCustomers,
        pipeline: renderPipeline, sources: renderSources, anomalies: renderAnomalies, assistant: renderAssistant,
        reports: renderReports,
    };

    function activate(tab) {
        activeTab = tab;
        document.querySelectorAll('#revaiTabs .p-tab').forEach(el => el.classList.toggle('active', el.dataset.tab === tab));
        (renderers[tab] || renderOverview)();
    }

    document.querySelectorAll('#revaiTabs .p-tab').forEach(el => {
        el.addEventListener('click', e => { e.preventDefault(); activate(el.dataset.tab); });
    });
    periodSelect.addEventListener('change', () => activate(activeTab));

    activate('executive');
})();
JS;
    }
}
