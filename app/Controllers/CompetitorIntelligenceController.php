<?php
/**
 * Tourfecto - Competitor Intelligence Controller
 * @version 1.0.0
 *
 * موديول واحد موحّد يغطي: Discovery, Tracking, Monitoring, Analysis,
 * Benchmarking, Change Detection (عبر MonitoringEngine), Alerts,
 * Market Intelligence, AI Insights, Reports. لا يعيد بناء أو يكرر
 * /competitor-monitoring القديم (تتبع سعر/عرض يدوي بسيط) - يبقى شغالاً
 * كما هو لأي عميل يستخدمه بالفعل.
 *
 * عزل الـ Tenant: كل استعلام هنا مفلتر بـ user_id = $this->user['id']
 * دائمًا. أي وصول لمنافس/تنبيه/تقرير يتحقق أولاً إنه ملك المستخدم
 * الحالي عبر assertCompetitorOwnership() قبل أي عملية.
 */
class CompetitorIntelligenceController extends Controller {

    /** GET /competitor-intelligence */
    public function index(array $params = []): array {
        $body = $this->renderShell();
        $script = $this->renderScript();

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('competitor_intelligence', $this->tr('ci.page.title'), $this->tr('ci.page.subtitle'), $body, $script);
        exit;
    }

    // ============================================================
    // Dashboard
    // ============================================================

    /** GET /api/competitor-intelligence/dashboard */
    public function apiDashboard(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $userId = (int) $this->user['id'];

        // 6 استعلامات COUNT + Activity Feed على كل فتح للـ Dashboard - Cache
        // قصير (60 ثانية) بيقلل الحمل على الداتابيز لو المستخدم فاتح
        // الصفحة وسايبها (بولينج متكرر)، من غير ما البيانات تبقى قديمة
        // بشكل ملحوظ. مفتاح الكاش مربوط بالـ user_id فمفيش تسريب بيانات
        // بين المستخدمين.
        $cacheKey = "ci_dashboard:{$userId}";
        $cache = class_exists('Cache') ? new Cache() : null;

        $build = function () use ($userId) {
            $totalCompetitors = (int) ($this->db->query("SELECT COUNT(*) c FROM competitors WHERE user_id = ?", [$userId])[0]['c'] ?? 0);
            $activeCompetitors = (int) ($this->db->query("SELECT COUNT(*) c FROM competitors WHERE user_id = ? AND is_active = 1 AND monitoring_paused = 0", [$userId])[0]['c'] ?? 0);
            $newChanges7d = (int) ($this->db->query("SELECT COUNT(*) c FROM ci_changes WHERE user_id = ? AND detected_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", [$userId])[0]['c'] ?? 0);
            $highAlerts = (int) ($this->db->query("SELECT COUNT(*) c FROM ci_alerts WHERE user_id = ? AND severity IN ('high','critical') AND is_read = 0", [$userId])[0]['c'] ?? 0);
            $threats = (int) ($this->db->query("SELECT COUNT(*) c FROM ci_insights WHERE user_id = ? AND type = 'threat' AND status = 'new'", [$userId])[0]['c'] ?? 0);
            $opportunities = (int) ($this->db->query("SELECT COUNT(*) c FROM ci_insights WHERE user_id = ? AND type = 'opportunity' AND status = 'new'", [$userId])[0]['c'] ?? 0);
            $recentActivity = (new CompetitorTrackingService())->getActivityFeed($userId, 10);

            // اتجاه التغييرات آخر 14 يوم (لرسم بياني في الـ Dashboard) - مجمّع
            // بالخطورة عشان الرسم يوضح مش بس العدد الكلي.
            $trendRows = $this->db->query(
                "SELECT DATE(detected_at) AS d, severity, COUNT(*) AS c FROM ci_changes
                 WHERE user_id = ? AND detected_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                 GROUP BY DATE(detected_at), severity ORDER BY d ASC",
                [$userId]
            );
            $changesTrend = [];
            for ($i = 13; $i >= 0; $i--) {
                $day = date('Y-m-d', strtotime("-{$i} days"));
                $changesTrend[$day] = ['date' => $day, 'low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
            }
            foreach ($trendRows as $r) {
                if (isset($changesTrend[$r['d']])) {
                    $changesTrend[$r['d']][$r['severity']] = (int) $r['c'];
                }
            }

            return [
                'total_competitors' => $totalCompetitors,
                'active_competitors' => $activeCompetitors,
                'new_changes_7d' => $newChanges7d,
                'high_priority_alerts' => $highAlerts,
                'threats' => $threats,
                'opportunities' => $opportunities,
                'recent_activity' => $recentActivity,
                'changes_trend' => array_values($changesTrend),
            ];
        };

        $data = $cache ? $cache->remember($cacheKey, $build, 60) : $build();

        return $this->success($data);
    }

    // ============================================================
    // Competitors: CRUD + Discovery + Profile + Check Now + Timeline
    // ============================================================

    /** GET /api/competitor-intelligence/competitors */
    public function apiListCompetitors(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $userId = (int) $this->user['id'];

        $category = (string) $this->get('category', '');
        $search = (string) $this->get('search', '');
        [$page, $perPage, $offset] = $this->paginationParams();
        $sort = $this->sortClause($this->get('sort', 'created_at'), $this->get('order', 'desc'), [
            'created_at', 'competitor_name', 'category', 'last_change_at', 'last_monitored_at',
        ], 'created_at');

        $sql = "SELECT * FROM competitors WHERE user_id = ?";
        $args = [$userId];
        if ($category !== '') { $sql .= " AND category = ?"; $args[] = $category; }
        if ($search !== '') { $sql .= " AND (competitor_name LIKE ? OR competitor_domain LIKE ?)"; $args[] = "%{$search}%"; $args[] = "%{$search}%"; }

        $totalRows = $this->db->query("SELECT COUNT(*) c FROM ({$sql}) t", $args);
        $total = (int) ($totalRows[0]['c'] ?? 0);

        $sql .= " ORDER BY {$sort} LIMIT ? OFFSET ?";
        $args[] = $perPage;
        $args[] = $offset;

        $rows = $this->db->query($sql, $args);
        return $this->success(['competitors' => $rows, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]]);
    }

    /** POST /api/competitor-intelligence/competitors */
    public function apiAddCompetitor(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!CiPermissions::can($this->user, CiPermissions::PERM_ADD)) return $this->error('Forbidden', 403);
        if (!$this->validate(['website_id' => 'required', 'competitor_name' => 'required'])) {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }
        if (mb_strlen((string) $this->get('competitor_name')) > 255) {
            return $this->error($this->tr('ci.error.input_too_long'), 422);
        }

        $domain = trim((string) $this->get('competitor_domain', ''));
        if ($domain !== '') {
            $normalized = CompetitorDomain::normalizeSafe($domain);
            if ($normalized === null) {
                return $this->error($this->tr('ci.error.invalid_or_unsafe_url'), 422);
            }
            $domain = $normalized;
        }

        // نجيب تفضيلات المستخدم الافتراضية (لو ضبطها من تاب Settings) بدل
        // قيم ثابتة مكتوبة بالكود - لو لسه مفيش تفضيلات، نستخدم افتراضيات معقولة.
        $defaults = $this->userDefaults((int) $this->user['id']);

        $competitor = new Competitor([
            'user_id' => (int) $this->user['id'],
            'website_id' => (int) $this->get('website_id'),
            'competitor_name' => (string) $this->get('competitor_name'),
            'competitor_domain' => $domain,
            'notes' => (string) $this->get('notes', ''),
            'industry' => $this->get('industry') ?: null,
            'country' => $this->get('country') ?: null,
            'market_segment' => $this->get('market_segment') ?: null,
            'category' => CiConstants::within(CiConstants::CATEGORIES, $this->get('category'), 'direct'),
            'source' => 'manual',
            'monitoring_frequency' => CiConstants::within(CiConstants::FREQUENCIES, $this->get('monitoring_frequency'), $defaults['frequency']),
            'is_active' => 1,
        ]);
        $competitor->save();

        // إضافة تلقائية لقائمة المتابعة بإعدادات المستخدم الافتراضية
        $watchlist = new CiWatchlistItem([
            'user_id' => (int) $this->user['id'],
            'competitor_id' => (int) $competitor->getAttribute('id'),
            'priority' => 'medium',
            'alert_min_severity' => $defaults['min_severity'],
            'alert_channels' => $defaults['channels'],
            'is_paused' => 0,
        ]);
        $watchlist->save();

        ActivityLog::record('competitor_intelligence', 'competitor.added', [
            'user_id' => (int) $this->user['id'], 'subject_type' => 'competitors', 'subject_id' => (int) $competitor->getAttribute('id'),
        ]);
        $this->invalidateDashboardCache((int) $this->user['id']);

        return $this->success(['competitor' => $competitor->toArray()], $this->tr('common.added'), 201);
    }

    /**
     * POST /api/competitor-intelligence/competitors/bulk-import
     * Body: { website_id, rows: [{name, domain, industry, country, category}, ...] }
     * كل صف يُعامَل زي apiAddCompetitor بالظبط (نفس الفحص/نفس الإعدادات
     * الافتراضية) - الفرق الوحيد إنه بيعالج مجموعة صفوف مرة واحدة
     * ويرجّع تقرير نجاح/فشل واضح لكل صف بدل ما يفشل كله لأول خطأ.
     * سقف 200 صف لكل استدعاء لمنع استعلامات ضخمة داخل request واحد.
     */
    public function apiBulkImportCompetitors(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!CiPermissions::can($this->user, CiPermissions::PERM_ADD)) return $this->error('Forbidden', 403);
        if (!$this->validate(['website_id' => 'required', 'rows' => 'required'])) {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }

        $userId = (int) $this->user['id'];
        $websiteId = (int) $this->get('website_id');
        $rows = (array) $this->get('rows');
        if (count($rows) > CiConstants::BULK_IMPORT_MAX_ROWS) {
            return $this->error($this->tr('ci.error.bulk_import_too_many_rows'), 422);
        }

        $existingDomains = array_map(function ($r) {
            return CompetitorDomain::host((string) $r['competitor_domain']) ?? strtolower((string) $r['competitor_domain']);
        }, $this->db->query("SELECT competitor_domain FROM competitors WHERE user_id = ?", [$userId]));

        $defaults = $this->userDefaults($userId);

        $added = 0;
        $skipped = 0;
        $results = [];

        foreach ($rows as $i => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $domain = trim((string) ($row['domain'] ?? ''));

            if ($name === '') {
                $results[] = ['row' => $i + 1, 'status' => 'error', 'reason' => 'missing_name'];
                $skipped++;
                continue;
            }
            if (mb_strlen($name) > 255) {
                $results[] = ['row' => $i + 1, 'status' => 'error', 'reason' => 'name_too_long', 'name' => $name];
                $skipped++;
                continue;
            }

            if ($domain !== '') {
                $normalized = CompetitorDomain::normalizeSafe($domain);
                if ($normalized === null) {
                    $results[] = ['row' => $i + 1, 'status' => 'error', 'reason' => 'invalid_or_unsafe_url', 'name' => $name];
                    $skipped++;
                    continue;
                }
                $host = CompetitorDomain::host($normalized) ?? $domain;
                if (in_array($host, $existingDomains, true)) {
                    $results[] = ['row' => $i + 1, 'status' => 'skipped', 'reason' => 'already_exists', 'name' => $name];
                    $skipped++;
                    continue;
                }
                $existingDomains[] = $host;
                $domain = $normalized;
            }

            $competitor = new Competitor([
                'user_id' => $userId, 'website_id' => $websiteId, 'competitor_name' => $name, 'competitor_domain' => $domain,
                'industry' => $row['industry'] ?? null, 'country' => $row['country'] ?? null,
                'category' => CiConstants::within(CiConstants::CATEGORIES, $row['category'] ?? '', 'direct'),
                'source' => 'bulk_import', 'monitoring_frequency' => $defaults['frequency'], 'is_active' => 1,
            ]);
            $competitor->save();

            (new CiWatchlistItem([
                'user_id' => $userId, 'competitor_id' => (int) $competitor->getAttribute('id'), 'priority' => 'medium',
                'alert_min_severity' => $defaults['min_severity'], 'alert_channels' => $defaults['channels'], 'is_paused' => 0,
            ]))->save();

            $results[] = ['row' => $i + 1, 'status' => 'added', 'name' => $name, 'competitor_id' => (int) $competitor->getAttribute('id')];
            $added++;
        }

        ActivityLog::record('competitor_intelligence', 'competitor.bulk_imported', [
            'user_id' => $userId, 'subject_type' => 'competitors', 'subject_id' => 0,
            'meta' => ['added' => $added, 'skipped' => $skipped, 'total_rows' => count($rows)],
        ]);
        $this->invalidateDashboardCache($userId);

        return $this->success(['added' => $added, 'skipped' => $skipped, 'results' => $results], $this->tr('ci.bulk_import.done'));
    }

    /** PUT /api/competitor-intelligence/competitors/{id} */
    public function apiUpdateCompetitor(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!CiPermissions::can($this->user, CiPermissions::PERM_EDIT)) return $this->error('Forbidden', 403);

        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) return $this->error('Not found', 404);

        $before = $competitor->toArray();
        foreach (['competitor_name', 'notes', 'industry', 'country', 'market_segment', 'category', 'monitoring_frequency', 'monitoring_paused'] as $field) {
            if ($this->get($field) !== null) {
                $competitor->setAttribute($field, $this->get($field));
            }
        }
        $competitor->save();

        ActivityLog::record('competitor_intelligence', 'competitor.updated', [
            'user_id' => (int) $this->user['id'], 'subject_type' => 'competitors', 'subject_id' => (int) $competitor->getAttribute('id'),
            'meta' => ['before' => $before, 'after' => $competitor->toArray()],
        ]);

        return $this->success(['competitor' => $competitor->toArray()], $this->tr('common.updated'));
    }

    /** DELETE /api/competitor-intelligence/competitors/{id} */
    public function apiDeleteCompetitor(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!CiPermissions::can($this->user, CiPermissions::PERM_DELETE)) return $this->error('Forbidden', 403);

        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) return $this->error('Not found', 404);

        ActivityLog::record('competitor_intelligence', 'competitor.deleted', [
            'user_id' => (int) $this->user['id'], 'subject_type' => 'competitors', 'subject_id' => (int) $competitor->getAttribute('id'),
            'meta' => ['before' => $competitor->toArray()],
        ]);

        $competitor->delete();
        $this->invalidateDashboardCache((int) $this->user['id']);
        return $this->success([], $this->tr('common.deleted'));
    }

    /** GET /api/competitor-intelligence/competitors/{id} */
    public function apiCompetitorProfile(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) return $this->error('Not found', 404);

        $competitorId = (int) $competitor->getAttribute('id');
        $latestSnapshots = $this->db->query(
            "SELECT s1.* FROM ci_snapshots s1
             INNER JOIN (SELECT page_type, MAX(captured_at) AS max_date FROM ci_snapshots WHERE competitor_id = ? GROUP BY page_type) s2
             ON s1.page_type = s2.page_type AND s1.captured_at = s2.max_date
             WHERE s1.competitor_id = ?", [$competitorId, $competitorId]
        );
        $recentChanges = $this->db->query("SELECT * FROM ci_changes WHERE competitor_id = ? ORDER BY detected_at DESC LIMIT 20", [$competitorId]);
        $insights = $this->db->query("SELECT * FROM ci_insights WHERE competitor_id = ? ORDER BY created_at DESC LIMIT 20", [$competitorId]);
        $scorecard = $this->db->query("SELECT * FROM ci_scorecards WHERE competitor_id = ? ORDER BY computed_at DESC LIMIT 1", [$competitorId]);

        return $this->success([
            'competitor' => $competitor->toArray(),
            'latest_snapshots' => $latestSnapshots,
            'recent_changes' => $recentChanges,
            'insights' => $insights,
            'scorecard' => $scorecard[0] ?? null,
        ]);
    }

    /** POST /api/competitor-intelligence/competitors/{id}/check-now */
    public function apiCheckNow(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!CiPermissions::can($this->user, CiPermissions::PERM_MANAGE_MONITORING)) return $this->error('Forbidden', 403);

        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) return $this->error('Not found', 404);

        // حد بسيط: مش أكتر من دورة يدوية كل 5 دقايق لنفس المنافس، لمنع
        // استخدام "Check Now" كوسيلة Flooding لموقع المنافس.
        $lastChecked = $competitor->getAttribute('last_monitored_at');
        if ($lastChecked && strtotime($lastChecked) > (time() - 300)) {
            return $this->error($this->tr('ci.error.check_now_rate_limited'), 429);
        }

        $engine = new MonitoringEngine();
        $result = $engine->monitor($competitor);
        $this->invalidateDashboardCache((int) $this->user['id']);

        return $this->success(['result' => $result], $this->tr('ci.monitoring.cycle_done'));
    }

    /** GET /api/competitor-intelligence/competitors/{id}/timeline */
    public function apiTimeline(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) return $this->error('Not found', 404);

        $timeline = (new CompetitorTrackingService())->getTimeline((int) $competitor->getAttribute('id'), (int) $this->get('months', 12));
        return $this->success(['timeline' => $timeline]);
    }

    // ============================================================
    // Discovery
    // ============================================================

    /** POST /api/competitor-intelligence/discovery/suggest */
    public function apiDiscoverySuggest(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!$this->validate(['website_id' => 'required', 'competitor_name' => 'required'])) {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }

        // نفس فحص أمان الـ URL اللي بيتم على "أضف منافس" - المرشح اللي
        // هيتم اعتماده هيبقى competitor_domain بعدين، فالخيط لازم يبان
        // آمن من البداية (وليس وقت الاعتماد فقط).
        $website = (string) $this->get('website', '');
        if ($website !== '' && CompetitorDomain::normalizeSafe($website) === null) {
            return $this->error($this->tr('ci.error.invalid_or_unsafe_url'), 422);
        }

        $service = new CompetitorDiscoveryService();
        $candidate = $service->suggestManualCandidate(
            (int) $this->user['id'], (int) $this->get('website_id'), (string) $this->get('competitor_name'),
            $website, (string) $this->get('industry', ''), (string) $this->get('country', '')
        );
        return $this->success(['candidate' => $candidate->toArray()], $this->tr('common.added'), 201);
    }

    /** POST /api/competitor-intelligence/discovery/run */
    public function apiDiscoveryRun(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!$this->validate(['website_id' => 'required'])) return $this->error($this->tr('ci.error.missing_fields'), 422);
        if (($limited = $this->assertRateLimit('discovery_run')) !== null) return $limited;

        $websiteId = (int) $this->get('website_id');
        $industry = $this->get('industry');
        $country = $this->get('country');

        // لو الصفحة مبعتتش industry/country صراحة، نجيبهم من بيانات
        // الموقع نفسه (اتملوا وقت الـ onboarding) بدل ما نطلب من المستخدم
        // يدخلهم يدويًا كل مرة.
        if (!$industry || !$country) {
            $siteRows = $this->db->query("SELECT industry, target_country FROM websites WHERE id = ? AND user_id = ? LIMIT 1", [$websiteId, (int) $this->user['id']]);
            if (!empty($siteRows)) {
                $industry = $industry ?: $siteRows[0]['industry'];
                $country = $country ?: $siteRows[0]['target_country'];
            }
        }

        // مصادر مُسجَّلة بترتيب الأولوية: onboarding (مجاني، بيانات المستخدم
        // نفسه) ثم Google Places. مفتاح Google Places بيتحل جوه المصدر نفسه
        // (لوحة الأدمن أولًا، واحتياطيًا .env) - لو مفيش مفتاح في أي منهم،
        // المصدر بيرجع available=false بسبب واضح بدل ما يفشل بصمت.
        $sources = [new WebsiteOnboardingDiscoverySource(), new GooglePlacesDiscoverySource()];

        $service = new CompetitorDiscoveryService($sources);
        $result = $service->runExternalDiscovery((int) $this->user['id'], $websiteId, [
            'industry' => $industry, 'country' => $country,
        ]);
        return $this->success($result);
    }

    /** GET /api/competitor-intelligence/discovery */
    public function apiDiscoveryList(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $rows = $this->db->query("SELECT * FROM ci_discovery_candidates WHERE user_id = ? ORDER BY discovered_at DESC", [(int) $this->user['id']]);
        return $this->success(['candidates' => $rows]);
    }

    /** POST /api/competitor-intelligence/discovery/{id}/approve */
    public function apiDiscoveryApprove(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $candidate = $this->assertDiscoveryOwnership((int) ($params['id'] ?? 0));
        if (!$candidate) return $this->error('Not found', 404);

        $competitor = (new CompetitorDiscoveryService())->approveCandidate($candidate);
        return $this->success(['competitor' => $competitor->toArray()], $this->tr('common.added'));
    }

    /** POST /api/competitor-intelligence/discovery/{id}/dismiss */
    public function apiDiscoveryDismiss(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $candidate = $this->assertDiscoveryOwnership((int) ($params['id'] ?? 0));
        if (!$candidate) return $this->error('Not found', 404);

        (new CompetitorDiscoveryService())->dismissCandidate($candidate);
        return $this->success([], $this->tr('common.updated'));
    }

    // ============================================================
    // Watchlist
    // ============================================================

    /** GET /api/competitor-intelligence/watchlist */
    public function apiWatchlist(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $rows = $this->db->query(
            "SELECT w.*, c.competitor_name, c.competitor_domain, c.category, c.last_change_at
             FROM ci_watchlist w JOIN competitors c ON c.id = w.competitor_id
             WHERE w.user_id = ? ORDER BY w.priority DESC, w.created_at DESC",
            [(int) $this->user['id']]
        );
        return $this->success(['watchlist' => $rows]);
    }

    /** POST /api/competitor-intelligence/watchlist */
    public function apiWatchlistUpsert(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!CiPermissions::can($this->user, CiPermissions::PERM_MANAGE_ALERTS)) return $this->error('Forbidden', 403);
        if (!$this->validate(['competitor_id' => 'required'])) return $this->error($this->tr('ci.error.missing_fields'), 422);

        $competitor = $this->assertCompetitorOwnership((int) $this->get('competitor_id'));
        if (!$competitor) return $this->error('Not found', 404);

        $existing = (new CiWatchlistItem())->where(['user_id' => (int) $this->user['id'], 'competitor_id' => (int) $competitor->getAttribute('id')], [], 1);
        $item = $existing[0] ?? new CiWatchlistItem(['user_id' => (int) $this->user['id'], 'competitor_id' => (int) $competitor->getAttribute('id')]);
        $before = $item->toArray();
        $wasPaused = (int) ($before['is_paused'] ?? 0);

        if ($this->get('priority')) $item->setAttribute('priority', $this->get('priority'));
        if ($this->get('alert_min_severity')) $item->setAttribute('alert_min_severity', $this->get('alert_min_severity'));
        if ($this->get('alert_channels')) $item->setAttribute('alert_channels', json_encode($this->get('alert_channels')));
        if ($this->get('keyword_filters') !== null) $item->setAttribute('keyword_filters', json_encode(array_values(array_filter((array) $this->get('keyword_filters')))));
        if ($this->get('is_paused') !== null) $item->setAttribute('is_paused', (int) $this->get('is_paused'));
        $item->save();

        $nowPaused = (int) $item->getAttribute('is_paused');
        $action = $nowPaused !== $wasPaused
            ? ($nowPaused ? 'watchlist.paused' : 'watchlist.resumed')
            : 'watchlist.updated';
        ActivityLog::record('competitor_intelligence', $action, [
            'user_id' => (int) $this->user['id'], 'subject_type' => 'ci_watchlist', 'subject_id' => (int) $item->getAttribute('id'),
            'meta' => ['before' => $before, 'after' => $item->toArray()],
        ]);

        return $this->success(['watchlist_item' => $item->toArray()], $this->tr('common.updated'));
    }

    /** DELETE /api/competitor-intelligence/watchlist/{competitorId} */
    public function apiWatchlistRemove(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $competitorId = (int) ($params['id'] ?? 0);
        $competitor = $this->assertCompetitorOwnership($competitorId);
        if (!$competitor) return $this->error('Not found', 404);

        $this->db->query("DELETE FROM ci_watchlist WHERE user_id = ? AND competitor_id = ?", [(int) $this->user['id'], $competitorId]);

        ActivityLog::record('competitor_intelligence', 'watchlist.removed', [
            'user_id' => (int) $this->user['id'], 'subject_type' => 'competitors', 'subject_id' => $competitorId,
        ]);

        return $this->success([], $this->tr('common.deleted'));
    }

    // ============================================================
    // Activity Feed / Comparison / Alerts / Insights / AI / Reports
    // ============================================================

    /** GET /api/competitor-intelligence/activity */
    public function apiActivityFeed(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $feed = (new CompetitorTrackingService())->getActivityFeed((int) $this->user['id'], (int) $this->get('limit', 50));
        return $this->success(['activity' => $feed]);
    }

    /** POST /api/competitor-intelligence/comparison */
    public function apiComparison(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!$this->validate(['website_id' => 'required', 'competitor_ids' => 'required'])) {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }
        $ids = array_map('intval', (array) $this->get('competitor_ids'));
        // نتأكد كل المنافسين المطلوب مقارنتهم ملك نفس المستخدم
        $owned = $this->db->query(
            "SELECT id FROM competitors WHERE user_id = ? AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")",
            array_merge([(int) $this->user['id']], $ids)
        );
        $ownedIds = array_map(fn($r) => (int) $r['id'], $owned);

        $comparison = (new BenchmarkingService())->compare((int) $this->get('website_id'), $ownedIds, (int) $this->get('days', 90));
        return $this->success($comparison);
    }

    /**
     * POST /api/competitor-intelligence/comparison/export
     * نفس بيانات المقارنة لكن بصيغة CSV جاهزة للتنزيل (ميزة تقارير
     * Excel اللي بتقدمها Prisync). بنرجّع النص كـ JSON والواجهة بتحوّله
     * لملف تحميل عبر Blob - مفيش حاجة تتخزن على السيرفر.
     */
    public function apiComparisonExport(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!$this->validate(['website_id' => 'required', 'competitor_ids' => 'required'])) {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }
        $ids = array_map('intval', (array) $this->get('competitor_ids'));
        $owned = $this->db->query(
            "SELECT id FROM competitors WHERE user_id = ? AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")",
            array_merge([(int) $this->user['id']], $ids)
        );
        $ownedIds = array_map(fn($r) => (int) $r['id'], $owned);
        if (empty($ownedIds)) {
            return $this->error('Not found', 404);
        }

        $comparison = (new BenchmarkingService())->compare((int) $this->get('website_id'), $ownedIds, (int) $this->get('days', 90));

        $rows = $comparison['rows'] ?? [];
        $labels = array_column($rows, 'label');
        $metrics = ['website_presence', 'content_activity', 'offer_activity', 'product_service_coverage', 'market_position_signals'];

        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_merge(['metric'], $labels));

        foreach ($metrics as $metric) {
            $line = [$metric];
            foreach ($rows as $row) {
                $value = $row[$metric] ?? '';
                $line[] = is_array($value) ? implode('; ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($value), array_values($value))) : (string) $value;
            }
            fputcsv($out, $line);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $this->success([
            'filename' => 'comparison-' . date('Y-m-d') . '.csv',
            'csv' => $csv,
        ]);
    }

    /** GET /api/competitor-intelligence/competitors/{id}/price-history */
    public function apiPriceHistory(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) return $this->error('Not found', 404);

        $rows = $this->db->query(
            "SELECT id, change_type, page_type, severity, price_before, price_after, currency, source_url, detected_at
             FROM ci_changes
             WHERE competitor_id = ? AND user_id = ? AND (price_before IS NOT NULL OR price_after IS NOT NULL)
             ORDER BY detected_at DESC LIMIT 200",
            [(int) $competitor->getAttribute('id'), (int) $this->user['id']]
        );
        return $this->success(['price_history' => $rows]);
    }

    /** GET /api/competitor-intelligence/alerts */
    public function apiAlerts(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $onlyUnread = $this->get('unread') === '1';
        [$page, $perPage, $offset] = $this->paginationParams();

        $sql = "SELECT a.*, c.competitor_name, c.competitor_domain FROM ci_alerts a JOIN competitors c ON c.id = a.competitor_id WHERE a.user_id = ?";
        $args = [(int) $this->user['id']];
        if ($onlyUnread) { $sql .= " AND a.is_read = 0"; }

        $totalRows = $this->db->query("SELECT COUNT(*) c FROM ({$sql}) t", $args);
        $total = (int) ($totalRows[0]['c'] ?? 0);

        $sql .= " ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
        $args[] = $perPage;
        $args[] = $offset;

        return $this->success(['alerts' => $this->db->query($sql, $args), 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]]);
    }

    /** POST /api/competitor-intelligence/alerts/{id}/read */
    public function apiMarkAlertRead(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $this->db->query("UPDATE ci_alerts SET is_read = 1 WHERE id = ? AND user_id = ?", [(int) ($params['id'] ?? 0), (int) $this->user['id']]);
        return $this->success([], $this->tr('common.updated'));
    }

    /** POST /api/competitor-intelligence/alerts/read-all */
    public function apiMarkAllAlertsRead(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $this->db->query("UPDATE ci_alerts SET is_read = 1 WHERE user_id = ? AND is_read = 0", [(int) $this->user['id']]);
        return $this->success([], $this->tr('common.updated'));
    }

    /** GET /api/competitor-intelligence/alerts/unread-count */
    public function apiUnreadAlertsCount(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $count = (int) ($this->db->query("SELECT COUNT(*) c FROM ci_alerts WHERE user_id = ? AND is_read = 0", [(int) $this->user['id']])[0]['c'] ?? 0);
        return $this->success(['unread_count' => $count]);
    }

    /** POST /api/competitor-intelligence/insights/{id}/status - body: {status: 'reviewed'|'dismissed'|'new'} */
    public function apiInsightStatus(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $status = CiConstants::within(CiConstants::INSIGHT_STATUSES, (string) $this->get('status'), '');
        if ($status === '') {
            return $this->error($this->tr('ci.error.missing_fields'), 422);
        }

        // رؤية معزولة بالمستخدم (tenant isolation) - محدش يعدّل رؤية حد تاني.
        $id = (int) ($params['id'] ?? 0);
        $rows = $this->db->query("SELECT id FROM ci_insights WHERE id = ? AND user_id = ? LIMIT 1", [$id, (int) $this->user['id']]);
        if (empty($rows)) return $this->error('Not found', 404);

        $this->db->query("UPDATE ci_insights SET status = ? WHERE id = ? AND user_id = ?", [$status, $id, (int) $this->user['id']]);
        return $this->success(['status' => $status], $this->tr('common.updated'));
    }

    /** GET /api/competitor-intelligence/insights */
    public function apiInsights(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $type = (string) $this->get('type', '');
        [$page, $perPage, $offset] = $this->paginationParams();

        $sql = "SELECT i.*, c.competitor_name, c.competitor_domain FROM ci_insights i LEFT JOIN competitors c ON c.id = i.competitor_id WHERE i.user_id = ?";
        $args = [(int) $this->user['id']];
        if (in_array($type, ['insight', 'threat', 'opportunity', 'recommendation'], true)) { $sql .= " AND i.type = ?"; $args[] = $type; }

        $totalRows = $this->db->query("SELECT COUNT(*) c FROM ({$sql}) t", $args);
        $total = (int) ($totalRows[0]['c'] ?? 0);

        $sql .= " ORDER BY i.created_at DESC LIMIT ? OFFSET ?";
        $args[] = $perPage;
        $args[] = $offset;

        return $this->success(['insights' => $this->db->query($sql, $args), 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]]);
    }

    /** POST /api/competitor-intelligence/competitors/{id}/scan-insights */
    public function apiScanInsights(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) return $this->error('Not found', 404);
        if (($limited = $this->assertRateLimit('ai_insights')) !== null) return $limited;

        $insights = (new ThreatOpportunityService())->scanCompetitor($competitor, (int) $this->get('days', 30));
        return $this->success(['insights' => array_map(fn($i) => $i->toArray(), $insights)]);
    }

    /** POST /api/competitor-intelligence/competitors/{id}/analyze-profile */
    public function apiAnalyzeProfile(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) return $this->error('Not found', 404);
        if (($limited = $this->assertRateLimit('ai_profile')) !== null) return $limited;

        $result = (new AICompetitiveAnalyst())->analyzeProfile($competitor);
        return $this->success($result);
    }

    /** POST /api/competitor-intelligence/competitors/{id}/compute-scorecard */
    public function apiComputeScorecard(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) return $this->error('Not found', 404);

        $scorecard = (new BenchmarkingService())->computeScorecard((int) $competitor->getAttribute('id'), (int) $this->get('days', 30));
        return $this->success(['scorecard' => $scorecard->toArray()], $this->tr('common.updated'));
    }

    /** GET /api/competitor-intelligence/competitors/{id}/scorecard-trend */
    public function apiScorecardTrend(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $competitor = $this->assertCompetitorOwnership((int) ($params['id'] ?? 0));
        if (!$competitor) return $this->error('Not found', 404);

        $rows = $this->db->query(
            "SELECT computed_at, visibility_score, content_activity_score, offer_activity_score, product_coverage_score, market_presence_score, basis
             FROM ci_scorecards WHERE competitor_id = ? ORDER BY computed_at ASC LIMIT 52",
            [(int) $competitor->getAttribute('id')]
        );
        return $this->success(['trend' => $rows]);
    }

    /** POST /api/competitor-intelligence/ai/ask */
    public function apiAiAsk(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!$this->validate(['question' => 'required'])) return $this->error($this->tr('ci.error.missing_fields'), 422);
        if (mb_strlen((string) $this->get('question')) > 2000) {
            return $this->error($this->tr('ci.error.input_too_long'), 422);
        }
        if (($limited = $this->assertRateLimit('ai_ask')) !== null) return $limited;

        $result = (new AICompetitiveAnalyst())->ask((int) $this->user['id'], (string) $this->get('question'), (int) $this->get('days', 30));
        return $this->success($result);
    }

    /** GET /api/competitor-intelligence/ai/weekly-summary */
    public function apiAiWeeklySummary(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!$this->validate(['website_id' => 'required'])) return $this->error($this->tr('ci.error.missing_fields'), 422);
        if (($limited = $this->assertRateLimit('ai_weekly_summary')) !== null) return $limited;

        $result = (new AICompetitiveAnalyst())->weeklySummary((int) $this->user['id'], (int) $this->get('website_id'));
        return $this->success($result);
    }

    /** GET /api/competitor-intelligence/reports */
    public function apiListReports(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        [$page, $perPage, $offset] = $this->paginationParams();

        $total = (int) ($this->db->query("SELECT COUNT(*) c FROM ci_reports WHERE user_id = ?", [(int) $this->user['id']])[0]['c'] ?? 0);
        $rows = $this->db->query(
            "SELECT id, type, title, period_start, period_end, generated_at FROM ci_reports WHERE user_id = ? ORDER BY generated_at DESC LIMIT ? OFFSET ?",
            [(int) $this->user['id'], $perPage, $offset]
        );
        return $this->success(['reports' => $rows, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]]);
    }

    /** POST /api/competitor-intelligence/reports */
    public function apiGenerateReport(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!CiPermissions::can($this->user, CiPermissions::PERM_EXPORT)) return $this->error('Forbidden', 403);
        if (!$this->validate(['website_id' => 'required', 'type' => 'required'])) return $this->error($this->tr('ci.error.missing_fields'), 422);

        $competitorId = $this->get('competitor_id') ? (int) $this->get('competitor_id') : null;
        if ($competitorId && !$this->assertCompetitorOwnership($competitorId)) return $this->error('Not found', 404);

        if (($limited = $this->assertRateLimit('report_generate')) !== null) return $limited;

        try {
            $report = (new ReportService())->generate((int) $this->user['id'], (int) $this->get('website_id'), (string) $this->get('type'), [], $competitorId);
            return $this->success(['report' => $report->toArray()], $this->tr('ci.report.generated'), 201);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** GET /api/competitor-intelligence/reports/{id} */
    public function apiGetReport(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $rows = $this->db->query("SELECT * FROM ci_reports WHERE id = ? AND user_id = ? LIMIT 1", [(int) ($params['id'] ?? 0), (int) $this->user['id']]);
        if (empty($rows)) return $this->error('Not found', 404);

        $report = $rows[0];
        $report['content'] = json_decode($report['content_json'], true);
        unset($report['content_json']);
        return $this->success(['report' => $report]);
    }

    // ============================================================
    // Settings (تفضيلات افتراضية + معلومات الصلاحيات/التكاملات)
    // ============================================================

    /** GET /api/competitor-intelligence/settings */
    public function apiGetSettings(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $userId = (int) $this->user['id'];

        $rows = (new CiUserPreference())->where(['user_id' => $userId], [], 1);
        $prefs = $rows[0] ?? null;

        $googlePlacesAvailable = false;
        if (class_exists('SystemSettingsService')) {
            $key = (new SystemSettingsService())->get('google_maps_api_key', defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '');
            $googlePlacesAvailable = $key !== '';
        }

        return $this->success([
            'preferences' => $prefs ? $prefs->toArray() : [
                'default_monitoring_frequency' => 'weekly',
                'default_alert_min_severity' => 'medium',
                'default_alert_channels' => json_encode(['dashboard']),
                'webhook_url' => null,
                'slack_webhook_url' => null,
                'weekly_digest_enabled' => 0,
            ],
            'ci_role' => CiPermissions::ciRole($this->user),
            'permissions' => [
                CiPermissions::PERM_VIEW, CiPermissions::PERM_ADD, CiPermissions::PERM_EDIT, CiPermissions::PERM_DELETE,
                CiPermissions::PERM_MANAGE_MONITORING, CiPermissions::PERM_MANAGE_ALERTS, CiPermissions::PERM_EXPORT, CiPermissions::PERM_MANAGE_SETTINGS,
            ],
            'granted_permissions' => array_values(array_filter([
                CiPermissions::PERM_VIEW, CiPermissions::PERM_ADD, CiPermissions::PERM_EDIT, CiPermissions::PERM_DELETE,
                CiPermissions::PERM_MANAGE_MONITORING, CiPermissions::PERM_MANAGE_ALERTS, CiPermissions::PERM_EXPORT, CiPermissions::PERM_MANAGE_SETTINGS,
            ], fn($p) => CiPermissions::can($this->user, $p))),
            'integrations' => [
                'google_places_discovery' => $googlePlacesAvailable,
                'ai_analyst' => defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '',
                'email_alerts' => defined('MAIL_HOST') && MAIL_HOST !== '',
            ],
        ]);
    }

    /** PUT /api/competitor-intelligence/settings */
    public function apiUpdateSettings(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!CiPermissions::can($this->user, CiPermissions::PERM_MANAGE_SETTINGS)) return $this->error('Forbidden', 403);

        $userId = (int) $this->user['id'];
        $rows = (new CiUserPreference())->where(['user_id' => $userId], [], 1);
        $prefs = $rows[0] ?? new CiUserPreference(['user_id' => $userId]);

        if (in_array($this->get('default_monitoring_frequency'), CiConstants::FREQUENCIES, true)) {
            $prefs->setAttribute('default_monitoring_frequency', $this->get('default_monitoring_frequency'));
        }
        if (in_array($this->get('default_alert_min_severity'), CiConstants::SEVERITIES, true)) {
            $prefs->setAttribute('default_alert_min_severity', $this->get('default_alert_min_severity'));
        }
        if ($this->get('default_alert_channels')) {
            $prefs->setAttribute('default_alert_channels', json_encode($this->get('default_alert_channels')));
        }
        if ($this->get('webhook_url') !== null) {
            $url = trim((string) $this->get('webhook_url'));
            if ($url !== '' && !SsrfGuard::isSafe($url)) {
                return $this->error($this->tr('ci.error.invalid_or_unsafe_url'), 422);
            }
            $prefs->setAttribute('webhook_url', $url ?: null);
        }
        if ($this->get('slack_webhook_url') !== null) {
            $url = trim((string) $this->get('slack_webhook_url'));
            if ($url !== '' && !SsrfGuard::isSafe($url)) {
                return $this->error($this->tr('ci.error.invalid_or_unsafe_url'), 422);
            }
            $prefs->setAttribute('slack_webhook_url', $url ?: null);
        }
        if ($this->get('weekly_digest_enabled') !== null) {
            $prefs->setAttribute('weekly_digest_enabled', (int) $this->get('weekly_digest_enabled') === 1 ? 1 : 0);
        }
        $prefs->save();

        ActivityLog::record('competitor_intelligence', 'settings.updated', [
            'user_id' => $userId, 'subject_type' => 'ci_user_preferences', 'subject_id' => (int) $prefs->getAttribute('id'),
        ]);

        return $this->success(['preferences' => $prefs->toArray()], $this->tr('common.updated'));
    }

    /** POST /api/competitor-intelligence/settings/pause-all */
    public function apiPauseAllMonitoring(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!CiPermissions::can($this->user, CiPermissions::PERM_MANAGE_SETTINGS)) return $this->error('Forbidden', 403);

        $userId = (int) $this->user['id'];
        $pause = (int) $this->get('pause', 1) === 1 ? 1 : 0;
        $this->db->query("UPDATE competitors SET monitoring_paused = ? WHERE user_id = ?", [$pause, $userId]);

        ActivityLog::record('competitor_intelligence', $pause ? 'monitoring.paused_all' : 'monitoring.resumed_all', [
            'user_id' => $userId, 'subject_type' => 'competitors', 'subject_id' => 0,
        ]);

        return $this->success([], $this->tr('common.updated'));
    }

    /**
     * GET /competitor-intelligence/reports/{id}/export?format=pdf|html|csv
     *
     * format=pdf|html (default): نفس أسلوب AIController::exportReport()
     * بالظبط - صفحة HTML بتصميم طباعة نظيف بتفتح ديالوج الطباعة
     * تلقائيًا، والعميل يختار "Save as PDF" من المتصفح نفسه. مفيش أي
     * مكتبة PDF على السيرفر (نفس سبب القرار الموجود بالفعل في
     * AIController - السيرفر مفيهوش SSH لتثبيت مكتبات Composer إضافية).
     *
     * format=csv: تصدير جدول بيانات خام (التغييرات أو الرؤى حسب نوع
     * التقرير) - مفيد لفتحه في Excel/Google Sheets مباشرة، منفصل عن
     * صفحة الطباعة/PDF.
     */
    public function exportReportPrintable(array $params = []): void {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $reportId = (int) ($params['id'] ?? 0);
        $rows = $this->db->query("SELECT * FROM ci_reports WHERE id = ? AND user_id = ? LIMIT 1", [$reportId, (int) $this->user['id']]);

        if (empty($rows)) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(404);
            echo 'Report not found';
            exit;
        }

        $report = $rows[0];
        $content = json_decode($report['content_json'], true) ?: [];

        if ((string) $this->get('format', 'html') === 'csv') {
            $this->streamReportCsv($report, $content);
            exit;
        }

        $html = $this->renderReportPrintableHtml($report, $content);

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    private function streamReportCsv(array $report, array $content): void {
        $filename = 'competitor-intelligence-' . preg_replace('/[^a-z0-9\-]+/i', '-', (string) $report['type']) . '-' . date('Ymd') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM عشان Excel يقرأ العربي صح

        if (isset($content['changes']) && is_array($content['changes'])) {
            fputcsv($out, ['Competitor', 'Page', 'Change Type', 'Severity', 'Confidence', 'Detected At', 'Source URL']);
            foreach ($content['changes'] as $c) {
                fputcsv($out, [$c['competitor'] ?? '', $c['page_type'] ?? '', $c['change_type'] ?? '', $c['severity'] ?? '', $c['confidence'] ?? '', $c['detected_at'] ?? '', $c['source_url'] ?? '']);
            }
        } elseif (isset($content['items']) && is_array($content['items'])) {
            fputcsv($out, ['Competitor', 'Title', 'Description', 'Confidence', 'Threat Level', 'Recommended Action', 'Created At']);
            foreach ($content['items'] as $i) {
                fputcsv($out, [$i['competitor'] ?? '', $i['title'] ?? '', $i['description'] ?? '', $i['confidence'] ?? '', $i['threat_level'] ?? '', $i['recommended_action'] ?? '', $i['created_at'] ?? '']);
            }
        } else {
            // تقرير profile أو أي شكل تاني - نصدّر JSON كخلية واحدة كحل أخير بدل ملف فاضي
            fputcsv($out, ['content_json']);
            fputcsv($out, [json_encode($content, JSON_UNESCAPED_UNICODE)]);
        }

        fclose($out);
    }

    private function renderReportPrintableHtml(array $report, array $content): string {
        $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $title = $esc($report['title']);
        $period = $esc(($report['period_start'] ?: '') . ($report['period_end'] ? ' → ' . $report['period_end'] : ''));
        $generatedAt = $esc($report['generated_at']);

        $bodyHtml = '<pre style="white-space:pre-wrap;font-family:inherit;font-size:13px;">' . $esc(json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';

        // لو التقرير من نوع weekly/monthly/change عندنا شكل أوضح (جدول تغييرات) بدل JSON خام
        if (isset($content['changes']) && is_array($content['changes'])) {
            $rowsHtml = '';
            foreach ($content['changes'] as $c) {
                $rowsHtml .= '<tr><td>' . $esc($c['competitor'] ?? '') . '</td><td>' . $esc($c['page_type'] ?? '') . '</td><td>' . $esc($c['change_type'] ?? '') . '</td><td>' . $esc($c['severity'] ?? '') . '</td><td>' . $esc($c['detected_at'] ?? '') . '</td></tr>';
            }
            $bodyHtml = '<p><strong>Total changes:</strong> ' . $esc($content['total_changes'] ?? count($content['changes'])) . '</p>'
                . '<table style="width:100%;border-collapse:collapse;" border="1" cellpadding="6"><thead><tr><th>Competitor</th><th>Page</th><th>Change</th><th>Severity</th><th>Detected</th></tr></thead><tbody>' . $rowsHtml . '</tbody></table>';
        } elseif (isset($content['items']) && is_array($content['items'])) {
            $rowsHtml = '';
            foreach ($content['items'] as $i) {
                $rowsHtml .= '<div style="margin-bottom:14px;padding:10px;border:1px solid #ddd;"><strong>' . $esc($i['title'] ?? '') . '</strong> (' . $esc($i['competitor'] ?? '') . ')<br>'
                    . $esc($i['description'] ?? '') . '<br><em>Confidence: ' . $esc($i['confidence'] ?? '') . '</em></div>';
            }
            $bodyHtml = $rowsHtml ?: '<p>No items in this period.</p>';
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{$title}</title>
<style>
body { font-family: Arial, Tahoma, sans-serif; padding: 30px; color: #222; }
h1 { font-size: 20px; margin-bottom: 4px; }
.meta { color: #777; font-size: 13px; margin-bottom: 20px; }
table { width: 100%; }
th { background: #f4f4f4; text-align: left; }
@media print { .no-print { display: none; } }
</style>
<script>window.addEventListener("load", () => setTimeout(() => window.print(), 400));</script>
</head>
<body>
<h1>{$title}</h1>
<div class="meta">Period: {$period} &middot; Generated: {$generatedAt} &middot; Tourfecto Competitor Intelligence</div>
{$bodyHtml}
</body>
</html>
HTML;
    }

    // ============================================================
    // Ownership helpers (Tenant Isolation)
    // ============================================================

    // ============================================================
    // Pagination / Sorting helpers
    // ============================================================

    /** يفضّي كاش الـ Dashboard لنفس المستخدم بعد أي عملية بتغيّر أرقامه (إضافة/حذف/فحص فوري) */
    private function invalidateDashboardCache(int $userId): void {
        if (class_exists('Cache')) {
            (new Cache())->delete("ci_dashboard:{$userId}");
        }
    }

    /**
     * بجيب تفضيلات المستخدم الافتراضية من ci_user_preferences (لو ضبطها من
     * تاب Settings) بقيم افتراضية معقولة لو مفيش تفضيلات محفوظة بعد. مستخدم
     * في نفس الصيغة من 3 أماكن (add + bulk import + المقارنة) - هنا في
     * مكان واحد عشان القيم الافتراضية متتكررش ولا تتحرف.
     * @return array{frequency:string, min_severity:string, channels:string}
     */
    private function userDefaults(int $userId): array {
        $prefRows = (new CiUserPreference())->where(['user_id' => $userId], [], 1);
        $prefs = $prefRows[0] ?? null;
        return [
            'frequency' => $prefs ? (string) $prefs->getAttribute('default_monitoring_frequency') : 'weekly',
            'min_severity' => $prefs ? (string) $prefs->getAttribute('default_alert_min_severity') : 'medium',
            'channels' => $prefs && $prefs->getAttribute('default_alert_channels') ? (string) $prefs->getAttribute('default_alert_channels') : json_encode(['dashboard']),
        ];
    }

    /**
     * حارس rate limit على الـ endpoints المكلفة - بيدا اتنين: رسالة خطأ
     * (429) لو العدد اتعدّى، أو null لو متاح. بتشتغل لكل مستخدم لوحده
     * (key معزول بالـ user_id) - مفيش أي مستخدم بيأثر على حدود غيره.
     */
    private function assertRateLimit(string $scope): ?array {
        $result = CiRateLimiter::hit($scope, 'user:' . (int) $this->user['id']);
        if (!$result['allowed']) {
            return $this->error($this->tr('ci.error.rate_limited'), 429);
        }
        return null;
    }

    /** @return array{0:int,1:int,2:int} [page, per_page, offset] */
    private function paginationParams(): array {
        $page = max(1, (int) $this->get('page', 1));
        $perPage = (int) $this->get('per_page', 20);
        $perPage = $perPage > 0 ? min(100, $perPage) : 20; // سقف 100 لكل صفحة لمنع استعلامات ثقيلة
        return [$page, $perPage, ($page - 1) * $perPage];
    }

    /**
     * يبني ORDER BY آمن - يقبل بس أعمدة من whitelist صريح، بيمنع أي
     * SQL Injection عبر اسم عمود الترتيب المُرسَل من الواجهة.
     */
    private function sortClause(string $requestedColumn, string $requestedOrder, array $allowedColumns, string $defaultColumn): string {
        $column = in_array($requestedColumn, $allowedColumns, true) ? $requestedColumn : $defaultColumn;
        $order = strtolower($requestedOrder) === 'asc' ? 'ASC' : 'DESC';
        return "`{$column}` {$order}";
    }

    private function assertCompetitorOwnership(int $id): ?Competitor {
        if ($id <= 0) return null;
        $competitor = (new Competitor())->find($id);
        if (!$competitor || (int) $competitor->getAttribute('user_id') !== (int) $this->user['id']) {
            return null;
        }
        return $competitor;
    }

    private function assertDiscoveryOwnership(int $id): ?CiDiscoveryCandidate {
        if ($id <= 0) return null;
        $candidate = (new CiDiscoveryCandidate())->find($id);
        if (!$candidate || (int) $candidate->getAttribute('user_id') !== (int) $this->user['id']) {
            return null;
        }
        return $candidate;
    }

    // ============================================================
    // Page shell (HTML) + client script (JS)
    // ============================================================

    private function renderShell(): string {
        $tabs = [
            'dashboard' => $this->tr('ci.tab.dashboard'),
            'competitors' => $this->tr('ci.tab.competitors'),
            'discovery' => $this->tr('ci.tab.discovery'),
            'watchlist' => $this->tr('ci.tab.watchlist'),
            'activity' => $this->tr('ci.tab.activity'),
            'comparison' => $this->tr('ci.tab.comparison'),
            'alerts' => $this->tr('ci.tab.alerts'),
            'insights' => $this->tr('ci.tab.insights'),
            'reports' => $this->tr('ci.tab.reports'),
            'settings' => $this->tr('ci.tab.settings'),
        ];
        $tabButtons = '';
        foreach ($tabs as $key => $label) {
            $tabButtons .= "<button class=\"ci-tab-btn\" data-tab=\"{$key}\" onclick=\"ciSwitchTab('{$key}')\">" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</button>";
        }

        $tHint = $this->tr('ci.how.body');

        return <<<HTML
        <style>
            .ci-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px; }
            .ci-tab-btn { padding:8px 14px; border-radius:8px; border:1px solid var(--panel-border,#e2e2e2); background:#fff; cursor:pointer; font-size:13px; font-weight:600; }
            .ci-tab-btn.active { background:var(--panel-primary,#4f46e5); color:#fff; border-color:transparent; }
            .ci-panel { display:none; }
            .ci-panel.active { display:block; }
            .ci-sev-badge { padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
            .ci-sev-low { background:#eef; color:#446; }
            .ci-sev-medium { background:#fff3cd; color:#8a6500; }
            .ci-sev-high { background:#ffe1d6; color:#a3390a; }
            .ci-sev-critical { background:#ffd6d6; color:#a30a0a; }
            .ci-unread-badge { background:#ef4444; color:#fff; font-size:11px; font-weight:700; padding:3px 10px; border-radius:99px; }
            .ci-profile-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:1000; display:flex; align-items:flex-start; justify-content:center; padding:30px 16px; overflow-y:auto; }
            .ci-profile-modal { background:#fff; border-radius:12px; padding:20px; max-width:900px; width:100%; box-shadow:0 20px 60px rgba(0,0,0,0.25); }
            .ci-profile-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; border-bottom:1px solid #eee; padding-bottom:12px; }
            .ci-profile-tab-panel { display:none; }
            .ci-profile-tab-panel.active { display:block; }
        </style>

        <div class="p-card" style="margin-bottom:16px;">
            <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                <div style="font-size:22px;line-height:1;">🕵️‍♂️</div>
                <div style="flex:1;min-width:240px;" class="p-cell-muted">{$tHint}</div>
            </div>
        </div>

        <div class="p-grid cols-4" id="ciStats">
            <div class="p-card stat-tile"><div class="stat-icon blue">🏁</div><div class="stat-info"><div class="stat-value" id="ciStatTotal">-</div><div class="stat-label">{$this->tr('ci.stat.total')}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon green">📡</div><div class="stat-info"><div class="stat-value" id="ciStatActive">-</div><div class="stat-label">{$this->tr('ci.stat.active')}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon orange">🔔</div><div class="stat-info"><div class="stat-value" id="ciStatAlerts">-</div><div class="stat-label">{$this->tr('ci.stat.high_alerts')}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon purple">⚡</div><div class="stat-info"><div class="stat-value" id="ciStatChanges">-</div><div class="stat-label">{$this->tr('ci.stat.changes_7d')}</div></div></div>
        </div>

        <div class="ci-tabs">{$tabButtons}</div>

        <div class="ci-panel" id="ciPanel-dashboard">
            <div class="p-card" style="margin-bottom:16px;">
                <h3 style="margin-top:0;">{$this->tr('ci.dashboard.trend_title')}</h3>
                <div style="padding:6px 2px;"><canvas id="ciTrendChart" height="90"></canvas></div>
            </div>
            <div class="p-grid cols-2">
                <div class="p-card"><h3>{$this->tr('ci.dashboard.threats_opportunities')}</h3>
                    <div id="ciDashboardThreatsOpps" class="p-cell-muted">{$this->tr('common.loading')}</div>
                </div>
                <div class="p-card"><h3>{$this->tr('ci.dashboard.recent_activity')}</h3>
                    <div class="p-table-scroll"><table class="p-table" id="ciDashboardActivityTable"><tbody></tbody></table></div>
                </div>
            </div>
        </div>

        <div class="ci-panel" id="ciPanel-competitors">
            <div class="p-toolbar" style="margin-bottom:10px;">
                <input type="text" id="ciCompetitorSearch" class="p-input" placeholder="{$this->tr('ci.competitors.search_placeholder')}">
                <select id="ciCompetitorCategoryFilter" class="p-select">
                    <option value="">{$this->tr('ci.category.all')}</option>
                    <option value="direct">{$this->tr('ci.category.direct')}</option>
                    <option value="indirect">{$this->tr('ci.category.indirect')}</option>
                    <option value="emerging">{$this->tr('ci.category.emerging')}</option>
                    <option value="potential">{$this->tr('ci.category.potential')}</option>
                </select>
                <button class="p-btn primary xs" onclick="ciOpenAddCompetitor()">+ {$this->tr('ci.competitors.add_btn')}</button>
                <button class="p-btn outline xs" onclick="ciOpenBulkImport()">{$this->tr('ci.bulk_import.open_btn')}</button>
            </div>
            <div id="ciAddCompetitorForm" class="p-card" style="display:none;margin-bottom:12px;">
                <div class="p-grid cols-2">
                    <input type="hidden" id="ciNewWebsiteId" value="">
                    <input type="text" id="ciNewName" class="p-input" placeholder="{$this->tr('ci.form.name')}">
                    <input type="text" id="ciNewDomain" class="p-input" placeholder="{$this->tr('ci.form.website')}">
                    <input type="text" id="ciNewIndustry" class="p-input" placeholder="{$this->tr('ci.form.industry')}">
                    <input type="text" id="ciNewCountry" class="p-input" placeholder="{$this->tr('ci.form.country')}">
                    <select id="ciNewCategory" class="p-select">
                        <option value="direct">{$this->tr('ci.category.direct')}</option>
                        <option value="indirect">{$this->tr('ci.category.indirect')}</option>
                        <option value="emerging">{$this->tr('ci.category.emerging')}</option>
                        <option value="potential">{$this->tr('ci.category.potential')}</option>
                    </select>
                    <select id="ciNewFrequency" class="p-select">
                        <option value="weekly">{$this->tr('ci.frequency.weekly')}</option>
                        <option value="daily">{$this->tr('ci.frequency.daily')}</option>
                    </select>
                </div>
                <div style="margin-top:10px;"><button class="p-btn primary xs" onclick="ciSubmitAddCompetitor()">{$this->tr('common.save')}</button></div>
            </div>
            <div id="ciBulkImportForm" class="p-card" style="display:none;margin-bottom:12px;">
                <p class="p-cell-muted" style="font-size:12px;">{$this->tr('ci.bulk_import.hint')}</p>
                <textarea id="ciBulkImportText" class="p-input" rows="6" placeholder="Booking Rivals, bookingrivals.com, Travel, Egypt, direct&#10;Trip Genie, tripgenie.example, Travel, UAE, indirect"></textarea>
                <div style="margin-top:10px;display:flex;gap:8px;">
                    <button class="p-btn primary xs" onclick="ciSubmitBulkImport()">{$this->tr('ci.bulk_import.submit_btn')}</button>
                    <button class="p-btn outline xs" onclick="ciCloseBulkImport()">{$this->tr('common.close')}</button>
                </div>
                <div id="ciBulkImportResult" style="margin-top:10px;"></div>
            </div>
            <div class="p-table-scroll"><table class="p-table" id="ciCompetitorsTable">
                <thead><tr><th>{$this->tr('ci.form.name')}</th><th>{$this->tr('ci.form.website')}</th><th>{$this->tr('ci.category.label')}</th><th>{$this->tr('ci.table.last_change')}</th><th>{$this->tr('ci.table.actions')}</th></tr></thead>
                <tbody></tbody>
            </table></div>
            <div id="ciCompetitorsPagination" class="p-toolbar" style="justify-content:center;margin-top:10px;"></div>
        </div>

        <div class="ci-panel" id="ciPanel-discovery">
            <div class="p-card" style="margin-bottom:12px;">
                <div class="p-grid cols-2">
                    <input type="hidden" id="ciDiscWebsiteId" value="">
                    <input type="text" id="ciDiscName" class="p-input" placeholder="{$this->tr('ci.form.name')}">
                    <input type="text" id="ciDiscWebsite" class="p-input" placeholder="{$this->tr('ci.form.website')}">
                </div>
                <div style="margin-top:10px;display:flex;gap:8px;">
                    <button class="p-btn outline xs" onclick="ciSuggestCandidate()">{$this->tr('ci.discovery.suggest_btn')}</button>
                    <button class="p-btn outline xs" onclick="ciRunDiscovery()">{$this->tr('ci.discovery.run_btn')}</button>
                </div>
                <div id="ciDiscoveryRunResult" class="p-cell-muted" style="margin-top:8px;"></div>
            </div>
            <div class="p-table-scroll"><table class="p-table" id="ciDiscoveryTable">
                <thead><tr><th>{$this->tr('ci.form.name')}</th><th>{$this->tr('ci.form.website')}</th><th>{$this->tr('ci.category.label')}</th><th>{$this->tr('ci.discovery.confidence')}</th><th>{$this->tr('ci.table.actions')}</th></tr></thead>
                <tbody></tbody>
            </table></div>
        </div>

        <div class="ci-panel" id="ciPanel-watchlist">
            <div class="p-table-scroll"><table class="p-table" id="ciWatchlistTable">
                <thead><tr><th>{$this->tr('ci.form.name')}</th><th>{$this->tr('ci.watchlist.priority')}</th><th>{$this->tr('ci.watchlist.min_severity')}</th><th>{$this->tr('ci.watchlist.keywords')}</th><th>{$this->tr('ci.table.status')}</th><th>{$this->tr('ci.table.actions')}</th></tr></thead>
                <tbody></tbody>
            </table></div>
        </div>

        <div class="ci-panel" id="ciPanel-activity">
            <div class="p-table-scroll"><table class="p-table" id="ciActivityTable">
                <thead><tr><th>{$this->tr('ci.form.name')}</th><th>{$this->tr('ci.table.event')}</th><th>{$this->tr('ci.table.severity')}</th><th>{$this->tr('ci.table.date')}</th></tr></thead>
                <tbody></tbody>
            </table></div>
        </div>

        <div class="ci-panel" id="ciPanel-comparison">
            <div class="p-card" style="margin-bottom:12px;">
                <div id="ciComparisonPicker" class="p-cell-muted">{$this->tr('common.loading')}</div>
                <button class="p-btn primary xs" style="margin-top:8px;" onclick="ciRunComparison()">{$this->tr('ci.comparison.run_btn')}</button>
            </div>
            <div class="p-card" id="ciComparisonChartCard" style="display:none;margin-bottom:12px;">
                <div style="padding:6px 2px;"><canvas id="ciComparisonChart" height="100"></canvas></div>
            </div>
            <div id="ciComparisonResult"></div>
        </div>

        <div class="ci-panel" id="ciPanel-alerts">
            <div class="p-toolbar" style="margin-bottom:10px;">
                <span id="ciUnreadBadge" class="ci-unread-badge" style="display:none;"></span>
                <span style="flex:1;"></span>
                <button class="p-btn outline xs" onclick="ciMarkAllAlertsRead()">{$this->tr('ci.js.mark_all_read')}</button>
            </div>
            <div class="p-table-scroll"><table class="p-table" id="ciAlertsTable">
                <thead><tr><th>{$this->tr('ci.form.name')}</th><th>{$this->tr('ci.table.severity')}</th><th>{$this->tr('ci.table.message')}</th><th>{$this->tr('ci.table.date')}</th><th>{$this->tr('ci.table.actions')}</th></tr></thead>
                <tbody></tbody>
            </table></div>
            <div id="ciAlertsPagination" class="p-toolbar" style="justify-content:center;margin-top:10px;"></div>
        </div>

        <div class="ci-panel" id="ciPanel-insights">
            <div class="p-toolbar" style="margin-bottom:10px;">
                <select id="ciInsightTypeFilter" class="p-select" onchange="ciLoadInsights()">
                    <option value="">{$this->tr('ci.category.all')}</option>
                    <option value="threat">{$this->tr('ci.insights.threat')}</option>
                    <option value="opportunity">{$this->tr('ci.insights.opportunity')}</option>
                    <option value="insight">{$this->tr('ci.insights.insight')}</option>
                </select>
            </div>
            <div class="p-card" style="margin-bottom:12px;">
                <textarea id="ciAiQuestion" class="p-input" rows="2" placeholder="{$this->tr('ci.ai.ask_placeholder')}"></textarea>
                <button class="p-btn primary xs" style="margin-top:8px;" onclick="ciAskAi()">{$this->tr('ci.ai.ask_btn')}</button>
                <div id="ciAiAnswer" class="p-cell-muted" style="margin-top:10px;white-space:pre-wrap;"></div>
            </div>
            <div id="ciInsightsList"></div>
            <div id="ciInsightsPagination" class="p-toolbar" style="justify-content:center;margin-top:10px;"></div>
        </div>

        <div class="ci-panel" id="ciPanel-reports">
            <div class="p-card" style="margin-bottom:12px;">
                <div class="p-toolbar">
                    <input type="hidden" id="ciReportWebsiteId" value="">
                    <select id="ciReportType" class="p-select">
                        <option value="weekly">{$this->tr('ci.reports.weekly')}</option>
                        <option value="monthly">{$this->tr('ci.reports.monthly')}</option>
                        <option value="threat">{$this->tr('ci.reports.threat')}</option>
                        <option value="opportunity">{$this->tr('ci.reports.opportunity')}</option>
                        <option value="change">{$this->tr('ci.reports.change')}</option>
                    </select>
                    <button class="p-btn primary xs" onclick="ciGenerateReport()">{$this->tr('ci.reports.generate_btn')}</button>
                </div>
            </div>
            <div class="p-table-scroll"><table class="p-table" id="ciReportsTable">
                <thead><tr><th>{$this->tr('ci.table.type')}</th><th>{$this->tr('ci.table.title')}</th><th>{$this->tr('ci.table.date')}</th><th>{$this->tr('ci.table.actions')}</th></tr></thead>
                <tbody></tbody>
            </table></div>
            <div id="ciReportsPagination" class="p-toolbar" style="justify-content:center;margin-top:10px;"></div>
            <div id="ciReportViewer" style="margin-top:12px;"></div>
        </div>

        <div class="ci-panel" id="ciPanel-settings">
            <div class="p-card" style="margin-bottom:12px;">
                <h3 style="margin-top:0;">{$this->tr('ci.settings.role_title')}</h3>
                <div id="ciSettingsRoleBox" class="p-cell-muted">{$this->tr('common.loading')}</div>
            </div>

            <div class="p-card" style="margin-bottom:12px;">
                <h3 style="margin-top:0;">{$this->tr('ci.settings.defaults_title')}</h3>
                <p class="p-cell-muted" style="font-size:12px;">{$this->tr('ci.settings.defaults_hint')}</p>
                <div class="p-grid cols-2">
                    <div class="form-group">
                        <label class="form-label">{$this->tr('ci.settings.default_frequency')}</label>
                        <select id="ciSettingsFrequency" class="p-select">
                            <option value="weekly">{$this->tr('ci.frequency.weekly')}</option>
                            <option value="daily">{$this->tr('ci.frequency.daily')}</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">{$this->tr('ci.watchlist.min_severity')}</label>
                        <select id="ciSettingsMinSeverity" class="p-select">
                            <option value="info">info</option>
                            <option value="low">low</option>
                            <option value="medium">medium</option>
                            <option value="high">high</option>
                            <option value="critical">critical</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top:10px;">
                    <label><input type="checkbox" id="ciSettingsChannelDashboard" checked> Dashboard</label>
                    &nbsp;&nbsp;<label><input type="checkbox" id="ciSettingsChannelEmail"> Email</label>
                    &nbsp;&nbsp;<label><input type="checkbox" id="ciSettingsChannelWebhook"> Webhook</label>
                    &nbsp;&nbsp;<label><input type="checkbox" id="ciSettingsChannelSlack"> Slack</label>
                </div>
                <div class="p-grid cols-2" style="margin-top:10px;">
                    <div class="form-group">
                        <label class="form-label">{$this->tr('ci.settings.webhook_url')}</label>
                        <input type="text" id="ciSettingsWebhookUrl" class="p-input" placeholder="https://your-endpoint.example/hooks/ci" dir="ltr">
                    </div>
                    <div class="form-group">
                        <label class="form-label">{$this->tr('ci.settings.slack_webhook_url')}</label>
                        <input type="text" id="ciSettingsSlackUrl" class="p-input" placeholder="https://hooks.slack.com/services/..." dir="ltr">
                    </div>
                </div>
                <div style="margin-top:10px;">
                    <label><input type="checkbox" id="ciSettingsWeeklyDigest"> {$this->tr('ci.settings.weekly_digest')}</label>
                </div>
                <button class="p-btn primary xs" style="margin-top:12px;" onclick="ciSaveSettings()">{$this->tr('common.save')}</button>
            </div>

            <div class="p-card" style="margin-bottom:12px;">
                <h3 style="margin-top:0;">{$this->tr('ci.settings.integrations_title')}</h3>
                <div id="ciSettingsIntegrations" class="p-cell-muted">{$this->tr('common.loading')}</div>
            </div>

            <div class="p-card">
                <h3 style="margin-top:0;">{$this->tr('ci.settings.danger_title')}</h3>
                <p class="p-cell-muted" style="font-size:12px;">{$this->tr('ci.settings.danger_hint')}</p>
                <button class="p-btn outline xs" onclick="ciPauseAllMonitoring(1)">{$this->tr('ci.settings.pause_all')}</button>
                <button class="p-btn outline xs" onclick="ciPauseAllMonitoring(0)">{$this->tr('ci.settings.resume_all')}</button>
            </div>
        </div>

        <div id="ciProfileOverlay" class="ci-profile-overlay" style="display:none;">
            <div class="ci-profile-modal">
                <div class="ci-profile-header">
                    <div>
                        <h2 id="ciProfileName" style="margin:0;">-</h2>
                        <div id="ciProfileDomain" class="p-cell-muted" style="font-size:12px;"></div>
                    </div>
                    <button class="p-btn outline xs" onclick="ciCloseProfile()">✕ {$this->tr('common.close')}</button>
                </div>
                <div class="ci-tabs" style="margin:14px 0;">
                    <button class="ci-tab-btn ci-profile-tab-btn active" data-ptab="overview" onclick="ciSwitchProfileTab('overview')">{$this->tr('ci.profile.overview')}</button>
                    <button class="ci-tab-btn ci-profile-tab-btn" data-ptab="changes" onclick="ciSwitchProfileTab('changes')">{$this->tr('ci.profile.changes')}</button>
                    <button class="ci-tab-btn ci-profile-tab-btn" data-ptab="timeline" onclick="ciSwitchProfileTab('timeline')">{$this->tr('ci.profile.timeline')}</button>
                    <button class="ci-tab-btn ci-profile-tab-btn" data-ptab="insights" onclick="ciSwitchProfileTab('insights')">{$this->tr('ci.profile.insights')}</button>
                </div>

                <div class="ci-profile-tab-panel active" id="ciProfileTab-overview">
                    <div class="p-grid cols-2">
                        <div class="p-card"><h4 style="margin-top:0;">{$this->tr('ci.profile.details')}</h4><div id="ciProfileDetails" class="p-cell-muted"></div></div>
                        <div class="p-card"><h4 style="margin-top:0;">{$this->tr('ci.profile.tech_signals')}</h4><div id="ciProfileTechSignals" class="p-cell-muted"></div></div>
                    </div>
                    <div class="p-card" style="margin-top:12px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <h4 style="margin:0;">{$this->tr('ci.profile.scorecard')}</h4>
                            <button class="p-btn outline xs" onclick="ciComputeScorecard()">{$this->tr('ci.profile.compute_scorecard_btn')}</button>
                        </div>
                        <div id="ciProfileScorecardBasis" class="p-cell-muted" style="font-size:11.5px;margin-top:6px;"></div>
                        <div style="padding:6px 2px;"><canvas id="ciScorecardChart" height="90"></canvas></div>
                        <div style="padding:6px 2px;margin-top:10px;border-top:1px solid #eee;padding-top:14px;">
                            <h5 style="margin:0 0 6px;font-size:12.5px;color:var(--panel-text-muted);">{$this->tr('ci.profile.scorecard_trend')}</h5>
                            <canvas id="ciScorecardTrendChart" height="80"></canvas>
                        </div>
                    </div>
                    <div class="p-card" style="margin-top:12px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <h4 style="margin:0;">{$this->tr('ci.profile.positioning')}</h4>
                            <button class="p-btn outline xs" onclick="ciAnalyzeProfile()">{$this->tr('ci.profile.analyze_btn')}</button>
                        </div>
                        <div id="ciProfilePositioning" class="p-cell-muted" style="margin-top:8px;white-space:pre-wrap;">{$this->tr('ci.profile.positioning_hint')}</div>
                    </div>
                </div>

                <div class="ci-profile-tab-panel" id="ciProfileTab-changes">
                    <div class="p-table-scroll"><table class="p-table"><thead><tr><th>{$this->tr('ci.table.date')}</th><th>{$this->tr('ci.table.type')}</th><th>{$this->tr('ci.table.severity')}</th><th>{$this->tr('ci.form.website')}</th></tr></thead>
                    <tbody id="ciProfileChangesBody"></tbody></table></div>
                </div>

                <div class="ci-profile-tab-panel" id="ciProfileTab-timeline">
                    <div id="ciProfileTimeline"></div>
                </div>

                <div class="ci-profile-tab-panel" id="ciProfileTab-insights">
                    <div style="text-align:right;margin-bottom:10px;">
                        <button class="p-btn outline xs" onclick="ciScanProfileInsights()">{$this->tr('ci.profile.scan_insights_btn')}</button>
                    </div>
                    <div id="ciProfileInsights"></div>
                </div>
            </div>
        </div>
        HTML;
    }

    private function renderScript(): string {
        $script = <<<'JS'
(function () {
    let allCompetitors = [];
    let ciTrendChartInstance = null;
    let ciComparisonChartInstance = null;
    let ciScorecardChartInstance = null;
    let ciScorecardTrendChartInstance = null;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const sevBadge = (s) => `<span class="ci-sev-badge ci-sev-${esc(s)}">${esc(s)}</span>`;
    const fmtPrice = (amount, currency) => (amount === null || amount === undefined) ? '—' : `${esc(currency || '')}${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim();
    const T = (key, vars) => {
        let s = (window.I18N && window.I18N[key]) || key;
        if (vars) Object.keys(vars).forEach(k => { s = s.replace('{' + k + '}', vars[k]); });
        return s;
    };
    const pageState = { competitors: 1, alerts: 1, insights: 1, reports: 1 };
    function renderPagination(elementId, key, pagination, reloadFn) {
        const el = document.getElementById(elementId);
        if (!el || !pagination) return;
        const totalPages = Math.max(1, Math.ceil(pagination.total / pagination.per_page));
        if (totalPages <= 1) { el.innerHTML = ''; return; }
        el.innerHTML = `
            <button class="p-btn outline xs" ${pagination.page <= 1 ? 'disabled' : ''} data-nav="prev">‹ ${T('ci.js.prev')}</button>
            <span class="p-cell-muted" style="align-self:center;font-size:12px;">${T('ci.js.page_of', { p: pagination.page, n: totalPages })}</span>
            <button class="p-btn outline xs" ${pagination.page >= totalPages ? 'disabled' : ''} data-nav="next">${T('ci.js.next')} ›</button>`;
        el.querySelector('[data-nav="prev"]')?.addEventListener('click', () => { pageState[key] = Math.max(1, pageState[key] - 1); reloadFn(); });
        el.querySelector('[data-nav="next"]')?.addEventListener('click', () => { pageState[key] = Math.min(totalPages, pageState[key] + 1); reloadFn(); });
    }

    window.ciSwitchTab = function (tab) {
        document.querySelectorAll('.ci-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
        document.querySelectorAll('.ci-panel').forEach(p => p.classList.toggle('active', p.id === 'ciPanel-' + tab));
        const loaders = {
            dashboard: loadDashboard, competitors: loadCompetitors, discovery: loadDiscovery,
            watchlist: loadWatchlist, activity: loadActivity, comparison: loadComparisonPicker,
            alerts: loadAlerts, insights: ciLoadInsights, reports: loadReports, settings: loadSettings,
        };
        if (loaders[tab]) loaders[tab]();
    };

    async function loadDashboard() {
        const res = await fetchJSON('/api/competitor-intelligence/dashboard');
        if (!res.success) return;
        document.getElementById('ciStatTotal').textContent = res.data.total_competitors;
        document.getElementById('ciStatActive').textContent = res.data.active_competitors;
        document.getElementById('ciStatAlerts').textContent = res.data.high_priority_alerts;
        document.getElementById('ciStatChanges').textContent = res.data.new_changes_7d;

        document.getElementById('ciDashboardThreatsOpps').innerHTML =
            `⚠️ ${T('ci.js.threats', { n: res.data.threats })} &nbsp; 💡 ${T('ci.js.opportunities', { n: res.data.opportunities })}`;

        const rows = res.data.recent_activity || [];
        document.querySelector('#ciDashboardActivityTable tbody').innerHTML = rows.length
            ? rows.map(a => `<tr><td>${esc(a.competitor)}</td><td>${esc(a.change_type)}</td><td>${sevBadge(a.severity)}</td><td>${esc(a.detected_at)}</td></tr>`).join('')
            : '<tr><td class="p-cell-muted">' + T('ci.js.no_recent_activity') + '</td></tr>';

        if (typeof Chart !== 'undefined') {
            const trend = res.data.changes_trend || [];
            if (ciTrendChartInstance) ciTrendChartInstance.destroy();
            ciTrendChartInstance = new Chart(document.getElementById('ciTrendChart'), {
                type: 'bar',
                data: {
                    labels: trend.map(t => t.date.slice(5)),
                    datasets: [
                        { label: T('ci.chart.low'), data: trend.map(t => t.low), backgroundColor: '#c9d6ea' },
                        { label: T('ci.chart.medium'), data: trend.map(t => t.medium), backgroundColor: '#f5c96b' },
                        { label: T('ci.chart.high'), data: trend.map(t => t.high), backgroundColor: '#f0916a' },
                        { label: T('ci.chart.critical'), data: trend.map(t => t.critical), backgroundColor: '#d9534f' },
                    ]
                },
                options: { responsive: true, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } } }
            });
        }
    }

    window.ciOpenAddCompetitor = function () {
        document.getElementById('ciBulkImportForm').style.display = 'none';
        document.getElementById('ciAddCompetitorForm').style.display = 'block';
    };

    window.ciSubmitAddCompetitor = async function () {
        const payload = {
            website_id: document.getElementById('ciNewWebsiteId').value || window.__CI_WEBSITE_ID__ || '',
            competitor_name: document.getElementById('ciNewName').value,
            competitor_domain: document.getElementById('ciNewDomain').value,
            industry: document.getElementById('ciNewIndustry').value,
            country: document.getElementById('ciNewCountry').value,
            category: document.getElementById('ciNewCategory').value,
            monitoring_frequency: document.getElementById('ciNewFrequency').value,
        };
        if (!payload.competitor_name) { toast(T('ci.js.name_required'), 'error'); return; }
        const res = await fetchJSON('/api/competitor-intelligence/competitors', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        if (res.success) {
            toast(T('ci.js.added'), 'success');
            document.getElementById('ciAddCompetitorForm').style.display = 'none';
            loadCompetitors();
        } else {
            toast(res.error || T('ci.js.error'), 'error');
        }
    };

    window.ciOpenBulkImport = function () {
        document.getElementById('ciAddCompetitorForm').style.display = 'none';
        document.getElementById('ciBulkImportForm').style.display = 'block';
    };

    window.ciCloseBulkImport = function () {
        document.getElementById('ciBulkImportForm').style.display = 'none';
        document.getElementById('ciBulkImportResult').innerHTML = '';
    };

    window.ciSubmitBulkImport = async function () {
        const raw = document.getElementById('ciBulkImportText').value.trim();
        if (!raw) { toast(T('ci.js.name_required'), 'error'); return; }

        // CSV بسيط: name, domain, industry, country, category - سطر لكل منافس
        const rows = raw.split('\n').map(line => line.trim()).filter(Boolean).map(line => {
            const parts = line.split(',').map(p => p.trim());
            return { name: parts[0] || '', domain: parts[1] || '', industry: parts[2] || '', country: parts[3] || '', category: parts[4] || 'direct' };
        }).filter(r => r.name);

        if (!rows.length) { toast(T('ci.js.name_required'), 'error'); return; }

        const websiteId = window.__CI_WEBSITE_ID__ || document.getElementById('ciNewWebsiteId').value || '';
        const res = await fetchJSON('/api/competitor-intelligence/competitors/bulk-import', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: websiteId, rows }),
        });

        const box = document.getElementById('ciBulkImportResult');
        if (!res.success) { box.textContent = res.error || T('ci.js.failed'); return; }

        box.innerHTML = `<div class="p-cell-muted">${T('ci.bulk_import.summary', { added: res.data.added, skipped: res.data.skipped })}</div>` +
            (res.data.results || []).filter(r => r.status !== 'added').map(r =>
                `<div style="font-size:12px;color:#a3390a;">Row ${r.row}: ${esc(r.name || '')} — ${esc(r.reason || r.status)}</div>`
            ).join('');

        toast(T('ci.bulk_import.summary', { added: res.data.added, skipped: res.data.skipped }), 'success');
        loadCompetitors();
    };

    async function loadCompetitors() {
        const search = document.getElementById('ciCompetitorSearch').value;
        const category = document.getElementById('ciCompetitorCategoryFilter').value;
        const res = await fetchJSON('/api/competitor-intelligence/competitors?search=' + encodeURIComponent(search) + '&category=' + encodeURIComponent(category) + '&page=' + pageState.competitors);
        if (!res.success) return;
        allCompetitors = res.data.competitors || [];
        document.querySelector('#ciCompetitorsTable tbody').innerHTML = allCompetitors.length
            ? allCompetitors.map(c => `<tr>
                <td>${esc(c.competitor_name || c.competitor_domain)}</td>
                <td>${esc(c.competitor_domain || '-')}</td>
                <td>${esc(c.category)}</td>
                <td>${esc(c.last_change_at || T('ci.js.no_changes_yet'))}</td>
                <td>
                    <button class="p-btn outline xs" onclick="ciOpenProfile(${c.id})">${T('ci.profile.view_btn')}</button>
                    <button class="p-btn outline xs" onclick="ciCheckNow(${c.id})">${T('ci.js.check_now')}</button>
                    <button class="p-btn outline xs" onclick="ciDeleteCompetitor(${c.id})">${T('ci.js.delete')}</button>
                </td>
              </tr>`).join('')
            : '<tr><td class="p-cell-muted">' + T('ci.js.no_competitors') + '</td></tr>';
        renderPagination('ciCompetitorsPagination', 'competitors', res.data.pagination, loadCompetitors);
    }

    document.addEventListener('input', (e) => { if (e.target && e.target.id === 'ciCompetitorSearch') { pageState.competitors = 1; loadCompetitors(); } });
    document.addEventListener('change', (e) => { if (e.target && e.target.id === 'ciCompetitorCategoryFilter') { pageState.competitors = 1; loadCompetitors(); } });

    window.ciCheckNow = async function (id) {
        toast(T('ci.js.checking'), 'info');
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + id + '/check-now', { method: 'POST' });
        toast(res.success ? T('ci.js.cycle_completed') : (res.error || T('ci.js.failed')), res.success ? 'success' : 'error');
        loadCompetitors();
    };

    window.ciDeleteCompetitor = async function (id) {
        if (!confirm('Delete this competitor?')) return;
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + id, { method: 'DELETE' });
        if (res.success) { toast(T('ci.js.deleted'), 'success'); loadCompetitors(); }
    };

    window.ciSuggestCandidate = async function () {
        const payload = {
            website_id: document.getElementById('ciDiscWebsiteId').value || window.__CI_WEBSITE_ID__ || '',
            competitor_name: document.getElementById('ciDiscName').value,
            website: document.getElementById('ciDiscWebsite').value,
        };
        if (!payload.competitor_name) { toast(T('ci.js.name_required'), 'error'); return; }
        const res = await fetchJSON('/api/competitor-intelligence/discovery/suggest', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        if (res.success) { toast(T('ci.js.suggested'), 'success'); loadDiscovery(); }
    };

    window.ciRunDiscovery = async function () {
        const websiteId = document.getElementById('ciDiscWebsiteId').value || window.__CI_WEBSITE_ID__ || '';
        const res = await fetchJSON('/api/competitor-intelligence/discovery/run', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ website_id: websiteId }) });
        const box = document.getElementById('ciDiscoveryRunResult');
        if (res.success && res.data.available && res.data.candidates_saved > 0) {
            box.textContent = T('ci.js.discovery_found', { n: res.data.candidates_saved });
        } else if (res.success) {
            // نعرض السبب الحقيقي الراجع من السيرفر بدل رسالة افتراضية ثابتة -
            // ممكن يكون "insufficient data" أو "كل المنافسين من onboarding مضافين بالفعل" وغيره.
            box.textContent = res.data.reason ? esc(res.data.reason) : T('ci.js.discovery_insufficient');
        } else {
            box.textContent = res.error || T('ci.js.failed');
        }
        loadDiscovery();
    };

    async function loadDiscovery() {
        const res = await fetchJSON('/api/competitor-intelligence/discovery');
        if (!res.success) return;
        const rows = res.data.candidates || [];
        document.querySelector('#ciDiscoveryTable tbody').innerHTML = rows.length
            ? rows.map(c => `<tr>
                <td>${esc(c.competitor_name)}</td><td>${esc(c.website || '-')}</td><td>${esc(c.category)}</td><td>${esc(c.confidence)}</td>
                <td>${c.status === 'pending' ? `<button class="p-btn outline xs" onclick="ciApproveCandidate(${c.id})">${T('ci.js.approve')}</button> <button class="p-btn outline xs" onclick="ciDismissCandidate(${c.id})">${T('ci.js.dismiss')}</button>` : esc(c.status)}</td>
              </tr>`).join('')
            : '<tr><td class="p-cell-muted">' + T('ci.js.no_candidates') + '</td></tr>';
    }

    window.ciApproveCandidate = async function (id) {
        const res = await fetchJSON('/api/competitor-intelligence/discovery/' + id + '/approve', { method: 'POST' });
        if (res.success) { toast(T('ci.js.added_as_competitor'), 'success'); loadDiscovery(); }
    };
    window.ciDismissCandidate = async function (id) {
        const res = await fetchJSON('/api/competitor-intelligence/discovery/' + id + '/dismiss', { method: 'POST' });
        if (res.success) loadDiscovery();
    };

    async function loadWatchlist() {
        const res = await fetchJSON('/api/competitor-intelligence/watchlist');
        if (!res.success) return;
        const rows = res.data.watchlist || [];
        document.querySelector('#ciWatchlistTable tbody').innerHTML = rows.length
            ? rows.map(w => {
                let keywords = [];
                try { keywords = JSON.parse(w.keyword_filters || '[]'); } catch (e) { keywords = []; }
                return `<tr>
                <td>${esc(w.competitor_name || w.competitor_domain)}</td><td>${esc(w.priority)}</td><td>${esc(w.alert_min_severity)}</td>
                <td>${keywords.length ? esc(keywords.join(', ')) : '<span class="p-cell-muted">' + T('ci.watchlist.no_keywords') + '</span>'}</td>
                <td>${w.is_paused == 1 ? T('ci.js.paused') : T('ci.js.active')}</td>
                <td>
                    <button class="p-btn outline xs" onclick="ciEditKeywords(${w.competitor_id}, '${esc(keywords.join(', ')).replace(/'/g, "&#39;")}')">${T('ci.watchlist.edit_keywords')}</button>
                    <button class="p-btn outline xs" onclick="ciToggleWatchlistPause(${w.competitor_id}, ${w.is_paused == 1 ? 0 : 1})">${w.is_paused == 1 ? T('ci.js.resume') : T('ci.js.pause')}</button>
                </td>
              </tr>`;
              }).join('')
            : '<tr><td class="p-cell-muted">' + T('ci.js.watchlist_empty') + '</td></tr>';
    }

    window.ciToggleWatchlistPause = async function (competitorId, pause) {
        const res = await fetchJSON('/api/competitor-intelligence/watchlist', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ competitor_id: competitorId, is_paused: pause }) });
        if (res.success) loadWatchlist();
    };

    window.ciEditKeywords = async function (competitorId, currentCsv) {
        const input = prompt(T('ci.watchlist.keywords_prompt'), currentCsv || '');
        if (input === null) return; // المستخدم عمل Cancel
        const keywords = input.split(',').map(k => k.trim()).filter(Boolean);
        const res = await fetchJSON('/api/competitor-intelligence/watchlist', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ competitor_id: competitorId, keyword_filters: keywords }) });
        if (res.success) { toast(T('ci.js.added'), 'success'); loadWatchlist(); } else { toast(res.error || T('ci.js.failed'), 'error'); }
    };

    async function loadActivity() {
        const res = await fetchJSON('/api/competitor-intelligence/activity');
        if (!res.success) return;
        const rows = res.data.activity || [];
        document.querySelector('#ciActivityTable tbody').innerHTML = rows.length
            ? rows.map(a => `<tr><td>${esc(a.competitor)}</td><td>${esc(a.change_type)} (${esc(a.page_type)})</td><td>${sevBadge(a.severity)}</td><td>${esc(a.detected_at)}</td></tr>`).join('')
            : '<tr><td class="p-cell-muted">' + T('ci.js.no_activity') + '</td></tr>';
    }

    async function loadComparisonPicker() {
        if (!allCompetitors.length) { const r = await fetchJSON('/api/competitor-intelligence/competitors'); allCompetitors = (r.success && r.data.competitors) || []; }
        document.getElementById('ciComparisonPicker').innerHTML = allCompetitors.length
            ? allCompetitors.map(c => `<label style="margin-right:12px;display:inline-block;"><input type="checkbox" class="ci-compare-cb" value="${c.id}"> ${esc(c.competitor_name || c.competitor_domain)}</label>`).join('')
            : T('ci.js.add_competitors_first');
    }

    window.ciRunComparison = async function () {
        const ids = Array.from(document.querySelectorAll('.ci-compare-cb:checked')).map(cb => parseInt(cb.value, 10));
        if (!ids.length) { toast(T('ci.js.select_competitor'), 'error'); return; }
        const websiteId = document.getElementById('ciReportWebsiteId').value || window.__CI_WEBSITE_ID__ || '';
        const res = await fetchJSON('/api/competitor-intelligence/comparison', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ website_id: websiteId, competitor_ids: ids }) });
        const box = document.getElementById('ciComparisonResult');
        if (!res.success) { box.textContent = res.error || 'Failed'; return; }
        const rows = res.data.rows || {};
        let html = `<div class="p-table-scroll"><table class="p-table"><thead><tr><th>${T('ci.js.col_business')}</th><th>${T('ci.js.col_website_presence')}</th><th>${T('ci.js.col_content_activity')}</th><th>${T('ci.js.col_offer_activity')}</th><th>${T('ci.js.col_market_signals')}</th></tr></thead><tbody>`;
        const chartLabels = [], chartContent = [], chartOffers = [];
        Object.values(rows).forEach(r => {
            const presence = typeof r.website_presence === 'object' ? Object.entries(r.website_presence).filter(([,v]) => v === true).map(([k]) => k).join(', ') || T('ci.js.none_detected') : 'N/A';
            html += `<tr><td>${esc(r.label)}</td><td>${esc(presence)}</td><td>${esc(r.content_activity)}</td><td>${esc(r.offer_activity)}</td><td>${esc(r.market_position_signals)}</td></tr>`;
            chartLabels.push(r.label);
            chartContent.push(typeof r.content_activity === 'number' ? r.content_activity : 0);
            chartOffers.push(typeof r.offer_activity === 'number' ? r.offer_activity : 0);
        });
        html += '</tbody></table></div>';
        html += `<div style="margin-top:10px;"><button class="p-btn outline xs" onclick="ciExportComparisonCsv()">${T('ci.js.export_csv')}</button></div>`;
        window.__ciLastComparison = { ids, websiteId };
        box.innerHTML = html;

        if (typeof Chart !== 'undefined') {
            document.getElementById('ciComparisonChartCard').style.display = 'block';
            if (ciComparisonChartInstance) ciComparisonChartInstance.destroy();
            ciComparisonChartInstance = new Chart(document.getElementById('ciComparisonChart'), {
                type: 'bar',
                data: {
                    labels: chartLabels,
                    datasets: [
                        { label: T('ci.js.col_content_activity'), data: chartContent, backgroundColor: '#4f46e5' },
                        { label: T('ci.js.col_offer_activity'), data: chartOffers, backgroundColor: '#f5a524' },
                    ]
                },
                options: { responsive: true, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
            });
        }
    };

    window.ciExportComparisonCsv = async function () {
        const last = window.__ciLastComparison;
        if (!last || !last.ids.length) return;
        const res = await fetchJSON('/api/competitor-intelligence/comparison/export', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: last.websiteId, competitor_ids: last.ids }),
        });
        if (!res.success) { toast(res.error || T('ci.js.failed'), 'error'); return; }
        const blob = new Blob(['\uFEFF' + res.data.csv], { type: 'text/csv;charset=utf-8;' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = res.data.filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(a.href);
        toast(T('ci.js.exported'), 'success');
    };

    async function loadAlerts() {
        const res = await fetchJSON('/api/competitor-intelligence/alerts?page=' + pageState.alerts);
        if (!res.success) return;
        const rows = res.data.alerts || [];
        document.querySelector('#ciAlertsTable tbody').innerHTML = rows.length
            ? rows.map(a => `<tr>
                <td>${esc(a.competitor_name || a.competitor_domain)}</td><td>${sevBadge(a.severity)}</td><td>${esc(a.message)}</td><td>${esc(a.created_at)}</td>
                <td>${a.is_read == 0 ? `<button class="p-btn outline xs" onclick="ciMarkAlertRead(${a.id})">${T('ci.js.mark_read')}</button>` : T('ci.js.read')}</td>
              </tr>`).join('')
            : '<tr><td class="p-cell-muted">' + T('ci.js.no_alerts') + '</td></tr>';
        renderPagination('ciAlertsPagination', 'alerts', res.data.pagination, loadAlerts);
        refreshUnreadBadge();
    }

    async function refreshUnreadBadge() {
        const badge = document.getElementById('ciUnreadBadge');
        if (!badge) return;
        const res = await fetchJSON('/api/competitor-intelligence/alerts/unread-count');
        if (!res.success) return;
        const count = res.data.unread_count || 0;
        badge.textContent = T('ci.js.unread_count', { n: count });
        badge.style.display = count > 0 ? 'inline-block' : 'none';
    }

    window.ciMarkAlertRead = async function (id) {
        const res = await fetchJSON('/api/competitor-intelligence/alerts/' + id + '/read', { method: 'POST' });
        if (res.success) loadAlerts();
    };

    window.ciMarkAllAlertsRead = async function () {
        const res = await fetchJSON('/api/competitor-intelligence/alerts/read-all', { method: 'POST' });
        if (res.success) { loadAlerts(); refreshUnreadBadge(); }
    };

    window.ciLoadInsights = async function () {
        const type = document.getElementById('ciInsightTypeFilter').value;
        const res = await fetchJSON('/api/competitor-intelligence/insights?page=' + pageState.insights + (type ? '&type=' + type : ''));
        if (!res.success) return;
        const rows = res.data.insights || [];
        document.getElementById('ciInsightsList').innerHTML = rows.length
            ? rows.map(i => `<div class="p-card" style="margin-bottom:8px;${i.status === 'dismissed' ? 'opacity:.55;' : ''}">
                <div style="font-weight:700;">${esc(i.title)} <span class="p-cell-muted" style="font-weight:400;">(${esc(i.competitor_name || i.competitor_domain || T('ci.js.market_wide'))})</span> ${insightStatusPill(i.status)}</div>
                <div class="p-cell-muted" style="margin:6px 0;">${esc(i.description)}</div>
                ${i.recommended_action ? `<div style="font-size:12.5px;"><strong>${T('ci.js.recommended')}:</strong> ${esc(i.recommended_action)}</div>` : ''}
                <div style="margin-top:6px;font-size:11.5px;color:#888;">${T('ci.js.confidence_label')}: ${esc(i.confidence)} · ${esc(i.created_at)}</div>
                <div style="margin-top:8px;">
                    ${i.status !== 'reviewed' ? `<button class="p-btn outline xs" onclick="ciSetInsightStatus(${i.id}, 'reviewed')">${T('ci.js.approve')}</button>` : ''}
                    ${i.status !== 'dismissed' ? `<button class="p-btn outline xs" onclick="ciSetInsightStatus(${i.id}, 'dismissed')">${T('ci.js.dismiss')}</button>` : ''}
                </div>
              </div>`).join('')
            : '<div class="p-cell-muted">' + T('ci.js.no_insights') + '</div>';
        renderPagination('ciInsightsPagination', 'insights', res.data.pagination, ciLoadInsights);
    };

    window.ciSetInsightStatus = async function (id, status) {
        const res = await fetchJSON('/api/competitor-intelligence/insights/' + id + '/status', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status }),
        });
        if (res.success) { toast(res.message || T('common.updated'), 'success'); ciLoadInsights(); } else { toast(res.error || T('ci.js.error'), 'error'); }
    };

    function insightStatusPill(status) {
        const label = T('ci.js.status_' + status);
        const colors = { new: '#e2e8f0', reviewed: '#dcfce7', dismissed: '#fee2e2' };
        return `<span style="font-size:10.5px;font-weight:600;padding:2px 7px;border-radius:99px;background:${colors[status] || '#eee'};color:#334155;">${esc(label)}</span>`;
    }

    document.addEventListener('change', (e) => { if (e.target && e.target.id === 'ciInsightTypeFilter') pageState.insights = 1; });

    window.ciAskAi = async function () {
        const question = document.getElementById('ciAiQuestion').value;
        if (!question) return;
        document.getElementById('ciAiAnswer').textContent = T('ci.js.thinking');
        const res = await fetchJSON('/api/competitor-intelligence/ai/ask', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ question }) });
        document.getElementById('ciAiAnswer').textContent = res.success ? (res.data.answer || T('ci.js.no_answer')) : (res.error || T('ci.js.failed'));
    };

    async function loadReports() {
        const res = await fetchJSON('/api/competitor-intelligence/reports?page=' + pageState.reports);
        if (!res.success) return;
        const rows = res.data.reports || [];
        document.querySelector('#ciReportsTable tbody').innerHTML = rows.length
            ? rows.map(r => `<tr><td>${esc(r.type)}</td><td>${esc(r.title)}</td><td>${esc(r.generated_at)}</td><td><button class="p-btn outline xs" onclick="ciViewReport(${r.id})">${T('ci.js.view')}</button></td></tr>`).join('')
            : '<tr><td class="p-cell-muted">' + T('ci.js.no_reports') + '</td></tr>';
        renderPagination('ciReportsPagination', 'reports', res.data.pagination, loadReports);
    }

    window.ciGenerateReport = async function () {
        const websiteId = document.getElementById('ciReportWebsiteId').value || window.__CI_WEBSITE_ID__ || '';
        const type = document.getElementById('ciReportType').value;
        const res = await fetchJSON('/api/competitor-intelligence/reports', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ website_id: websiteId, type }) });
        if (res.success) { toast(T('ci.js.report_generated'), 'success'); loadReports(); } else { toast(res.error || T('ci.js.failed'), 'error'); }
    };

    window.ciViewReport = async function (id) {
        const res = await fetchJSON('/api/competitor-intelligence/reports/' + id);
        if (!res.success) return;
        document.getElementById('ciReportViewer').innerHTML = `<div class="p-card">
            <div style="text-align:right;margin-bottom:8px;">
                <a class="p-btn outline xs" href="/competitor-intelligence/reports/${id}/export" target="_blank" rel="noopener">${T('ci.js.export_pdf')}</a>
                <a class="p-btn outline xs" href="/competitor-intelligence/reports/${id}/export?format=csv">${T('ci.js.export_csv')}</a>
            </div>
            <pre style="white-space:pre-wrap;font-size:12px;">${esc(JSON.stringify(res.data.report.content, null, 2))}</pre>
        </div>`;
    };

    async function loadSettings() {
        const res = await fetchJSON('/api/competitor-intelligence/settings');
        if (!res.success) return;
        const d = res.data;

        document.getElementById('ciSettingsRoleBox').innerHTML =
            `<strong>${esc(d.ci_role)}</strong> &middot; ${T('ci.settings.can_label')}: ${d.granted_permissions.map(esc).join(', ')}`;

        const prefs = d.preferences || {};
        document.getElementById('ciSettingsFrequency').value = prefs.default_monitoring_frequency || 'weekly';
        document.getElementById('ciSettingsMinSeverity').value = prefs.default_alert_min_severity || 'medium';
        let channels = [];
        try { channels = JSON.parse(prefs.default_alert_channels || '["dashboard"]'); } catch (e) { channels = ['dashboard']; }
        document.getElementById('ciSettingsChannelDashboard').checked = channels.includes('dashboard');
        document.getElementById('ciSettingsChannelEmail').checked = channels.includes('email');
        document.getElementById('ciSettingsChannelWebhook').checked = channels.includes('webhook');
        document.getElementById('ciSettingsChannelSlack').checked = channels.includes('slack');
        document.getElementById('ciSettingsWebhookUrl').value = prefs.webhook_url || '';
        document.getElementById('ciSettingsSlackUrl').value = prefs.slack_webhook_url || '';
        document.getElementById('ciSettingsWeeklyDigest').checked = prefs.weekly_digest_enabled == 1;

        const integ = d.integrations || {};
        const line = (label, ok) => `<div>${ok ? '✅' : '⚪'} ${esc(label)} — ${ok ? T('ci.settings.active') : T('ci.settings.inactive')}</div>`;
        document.getElementById('ciSettingsIntegrations').innerHTML =
            line(T('ci.settings.integration_google'), integ.google_places_discovery) +
            line(T('ci.settings.integration_ai'), integ.ai_analyst) +
            line(T('ci.settings.integration_email'), integ.email_alerts);

        // مفيش صلاحية manage_settings -> نعطّل الحفظ/الأزرار الخطيرة بدل ما نخفيها بالكامل (شفافية أفضل)
        const canManage = d.granted_permissions.includes('manage_settings');
        document.querySelectorAll('#ciPanel-settings button, #ciPanel-settings select, #ciPanel-settings input').forEach(el => {
            if (el.tagName !== 'INPUT' || el.type !== 'text') el.disabled = !canManage;
        });
    }

    window.ciSaveSettings = async function () {
        const channels = [];
        if (document.getElementById('ciSettingsChannelDashboard').checked) channels.push('dashboard');
        if (document.getElementById('ciSettingsChannelEmail').checked) channels.push('email');
        if (document.getElementById('ciSettingsChannelWebhook').checked) channels.push('webhook');
        if (document.getElementById('ciSettingsChannelSlack').checked) channels.push('slack');

        const payload = {
            default_monitoring_frequency: document.getElementById('ciSettingsFrequency').value,
            default_alert_min_severity: document.getElementById('ciSettingsMinSeverity').value,
            default_alert_channels: channels,
            webhook_url: document.getElementById('ciSettingsWebhookUrl').value.trim(),
            slack_webhook_url: document.getElementById('ciSettingsSlackUrl').value.trim(),
            weekly_digest_enabled: document.getElementById('ciSettingsWeeklyDigest').checked ? 1 : 0,
        };
        const res = await fetchJSON('/api/competitor-intelligence/settings', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        toast(res.success ? T('ci.js.added') : (res.error || T('ci.js.failed')), res.success ? 'success' : 'error');
    };

    window.ciPauseAllMonitoring = async function (pause) {
        if (!confirm(pause ? T('ci.settings.confirm_pause_all') : T('ci.settings.confirm_resume_all'))) return;
        const res = await fetchJSON('/api/competitor-intelligence/settings/pause-all', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ pause }) });
        toast(res.success ? T('ci.js.added') : (res.error || T('ci.js.failed')), res.success ? 'success' : 'error');
    };

    // ============================================================
    // Competitor Profile (modal) - Overview / Changes / Timeline / Insights
    // ============================================================
    let currentProfileId = null;

    window.ciOpenProfile = async function (id) {
        currentProfileId = id;
        document.getElementById('ciProfileOverlay').style.display = 'flex';
        document.getElementById('ciProfileName').textContent = T('common.loading');
        document.getElementById('ciProfilePositioning').textContent = T('ci.profile.positioning_hint');
        ciSwitchProfileTab('overview');
        await ciLoadProfileOverview(id);
    };

    window.ciCloseProfile = function () {
        document.getElementById('ciProfileOverlay').style.display = 'none';
        currentProfileId = null;
    };

    window.ciSwitchProfileTab = function (tab) {
        document.querySelectorAll('.ci-profile-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.ptab === tab));
        document.querySelectorAll('.ci-profile-tab-panel').forEach(p => p.classList.toggle('active', p.id === 'ciProfileTab-' + tab));
        if (!currentProfileId) return;
        if (tab === 'changes') ciLoadProfileChanges();
        if (tab === 'timeline') ciLoadProfileTimeline();
        if (tab === 'insights') ciLoadProfileInsights();
    };

    async function ciLoadProfileOverview(id) {
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + id);
        if (!res.success) { toast(res.error || T('ci.js.failed'), 'error'); ciCloseProfile(); return; }
        const c = res.data.competitor;

        document.getElementById('ciProfileName').textContent = c.competitor_name || c.competitor_domain;
        document.getElementById('ciProfileDomain').textContent = c.competitor_domain || '';

        document.getElementById('ciProfileDetails').innerHTML = [
            ['Industry', c.industry], ['Country', c.country], ['Category', c.category],
            ['Monitoring', c.monitoring_frequency + (c.monitoring_paused == 1 ? ' (' + T('ci.settings.paused') + ')' : '')],
            ['Last monitored', c.last_monitored_at || T('ci.js.no_changes_yet')],
            ['Last change', c.last_change_at || T('ci.js.no_changes_yet')],
        ].map(([k, v]) => `<div><strong>${esc(k)}:</strong> ${esc(v || '-')}</div>`).join('');

        const snapshots = res.data.latest_snapshots || [];
        const techLines = [];
        snapshots.forEach(s => {
            if (s.tech_signals) {
                try {
                    const t = JSON.parse(s.tech_signals);
                    Object.entries(t).forEach(([k, v]) => techLines.push(`<div><strong>${esc(k)}</strong> (${esc(s.page_type)}): ${esc(v)}</div>`));
                } catch (e) { /* ignore unparseable */ }
            }
        });
        document.getElementById('ciProfileTechSignals').innerHTML = techLines.length ? techLines.join('') : T('ci.profile.not_available');

        // لو فيه AI insight سابق من نوع positioning نعرضه فورًا بدل ما ننتظر ضغطة زرار
        const positioningInsight = (res.data.insights || []).find(i => i.generated_by === 'ai' && i.title && i.title.indexOf('positioning') !== -1);
        if (positioningInsight) {
            document.getElementById('ciProfilePositioning').textContent = positioningInsight.description;
        }

        ciRenderScorecardChart(res.data.scorecard || null);
        ciLoadScorecardTrend();
    }

    async function ciLoadScorecardTrend() {
        if (typeof Chart === 'undefined' || !currentProfileId) return;
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/scorecard-trend');
        if (ciScorecardTrendChartInstance) { ciScorecardTrendChartInstance.destroy(); ciScorecardTrendChartInstance = null; }
        if (!res.success) return;

        const rows = res.data.trend || [];
        if (rows.length < 2) return; // نقطة واحدة أو صفر - رسم اتجاه مش مفيد، الرسم الأساسي فوق كافي

        ciScorecardTrendChartInstance = new Chart(document.getElementById('ciScorecardTrendChart'), {
            type: 'line',
            data: {
                labels: rows.map(r => r.computed_at.slice(0, 10)),
                datasets: [
                    { label: T('ci.profile.score_visibility'), data: rows.map(r => r.visibility_score), borderColor: '#4f46e5', fill: false, tension: 0.25 },
                    { label: T('ci.profile.score_content'), data: rows.map(r => r.content_activity_score), borderColor: '#f5a524', fill: false, tension: 0.25 },
                    { label: T('ci.profile.score_market'), data: rows.map(r => r.market_presence_score), borderColor: '#2e9e6c', fill: false, tension: 0.25 },
                ]
            },
            options: { responsive: true, scales: { y: { beginAtZero: true, max: 100 } } }
        });
    }

    function ciRenderScorecardChart(scorecard) {
        const basisBox = document.getElementById('ciProfileScorecardBasis');
        if (typeof Chart === 'undefined') return;
        if (ciScorecardChartInstance) { ciScorecardChartInstance.destroy(); ciScorecardChartInstance = null; }

        if (!scorecard) {
            basisBox.textContent = T('ci.profile.scorecard_not_computed');
            return;
        }

        basisBox.textContent = (scorecard.basis === 'data_backed' ? T('ci.profile.basis_data_backed') : T('ci.profile.basis_estimated')) + ' · ' + scorecard.computed_at;

        const labels = [T('ci.profile.score_visibility'), T('ci.profile.score_content'), T('ci.profile.score_offers'), T('ci.profile.score_product'), T('ci.profile.score_market')];
        const values = [scorecard.visibility_score, scorecard.content_activity_score, scorecard.offer_activity_score, scorecard.product_coverage_score, scorecard.market_presence_score].map(v => v === null ? 0 : parseFloat(v));

        ciScorecardChartInstance = new Chart(document.getElementById('ciScorecardChart'), {
            type: 'bar',
            data: { labels, datasets: [{ label: T('ci.profile.scorecard'), data: values, backgroundColor: '#4f46e5' }] },
            options: { indexAxis: 'y', responsive: true, scales: { x: { beginAtZero: true, max: 100 } } }
        });
    }

    window.ciComputeScorecard = async function () {
        if (!currentProfileId) return;
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/compute-scorecard', { method: 'POST' });
        if (res.success) {
            toast(T('ci.js.added'), 'success');
            ciRenderScorecardChart(res.data.scorecard);
            ciLoadScorecardTrend();
        } else {
            toast(res.error || T('ci.js.failed'), 'error');
        }
    };

    async function ciLoadProfileChanges() {
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId);
        if (!res.success) return;
        const rows = res.data.recent_changes || [];
        document.getElementById('ciProfileChangesBody').innerHTML = rows.length
            ? rows.map(c => `<tr><td>${esc(c.detected_at)}</td><td>${esc(c.change_type)} (${esc(c.page_type)})</td><td>${sevBadge(c.severity)}</td><td>${c.source_url ? `<a href="${esc(c.source_url)}" target="_blank" rel="noopener">${T('ci.js.view')}</a>` : '-'}</td></tr>`).join('')
            : `<tr><td class="p-cell-muted">${T('ci.js.no_changes_yet')}</td></tr>`;
    }

    async function ciLoadProfileTimeline() {
        const box = document.getElementById('ciProfileTimeline');
        box.innerHTML = T('common.loading');
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/timeline');
        if (!res.success) { box.textContent = res.error || T('ci.js.failed'); return; }
        const timeline = res.data.timeline || {};
        const months = Object.keys(timeline).sort().reverse();
        let html = months.length
            ? months.map(m => `<div class="p-card" style="margin-bottom:10px;">
                <h4 style="margin:0 0 8px;">${esc(m)}</h4>
                ${timeline[m].map(c => `<div style="font-size:12.5px;margin-bottom:4px;">${sevBadge(c.severity)} ${esc(c.change_type)} — ${esc(c.page_type)} <span class="p-cell-muted">(${esc(c.detected_at)})</span></div>`).join('')}
              </div>`).join('')
            : `<div class="p-cell-muted">${T('ci.js.no_changes_yet')}</div>`;

        const ph = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/price-history');
        if (ph.success && (ph.data.price_history || []).length) {
            html += `<h4 style="margin:16px 0 8px;">${T('ci.js.price_history')}</h4>`
                + ph.data.price_history.map(p => `<div style="font-size:12.5px;margin-bottom:4px;">
                    ${esc(p.detected_at)} — ${fmtPrice(p.price_before, p.currency)} → ${fmtPrice(p.price_after, p.currency)}
                    <span class="p-cell-muted">(${esc(p.change_type)} · ${esc(p.page_type)})</span></div>`).join('');
        }

        box.innerHTML = html;
    }

    async function ciLoadProfileInsights() {
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId);
        if (!res.success) return;
        const rows = res.data.insights || [];
        document.getElementById('ciProfileInsights').innerHTML = rows.length
            ? rows.map(i => `<div class="p-card" style="margin-bottom:8px;">
                <div style="font-weight:700;">${esc(i.title)}</div>
                <div class="p-cell-muted" style="margin:6px 0;">${esc(i.description)}</div>
                ${i.recommended_action ? `<div style="font-size:12.5px;"><strong>${T('ci.js.recommended')}:</strong> ${esc(i.recommended_action)}</div>` : ''}
                <div style="margin-top:6px;font-size:11.5px;color:#888;">${esc(i.type)} · ${T('ci.js.confidence_label')}: ${esc(i.confidence)} · ${esc(i.created_at)}</div>
              </div>`).join('')
            : `<div class="p-cell-muted">${T('ci.js.no_insights')}</div>`;
    }

    window.ciScanProfileInsights = async function () {
        if (!currentProfileId) return;
        toast(T('ci.js.checking'), 'info');
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/scan-insights', { method: 'POST' });
        if (res.success) {
            toast(T('ci.js.added'), 'success');
            ciLoadProfileInsights();
        } else {
            toast(res.error || T('ci.js.failed'), 'error');
        }
    };

    window.ciAnalyzeProfile = async function () {
        if (!currentProfileId) return;
        document.getElementById('ciProfilePositioning').textContent = T('ci.js.thinking');
        const res = await fetchJSON('/api/competitor-intelligence/competitors/' + currentProfileId + '/analyze-profile', { method: 'POST' });
        if (res.success && res.data.available) {
            document.getElementById('ciProfilePositioning').textContent = res.data.insight.description;
        } else {
            document.getElementById('ciProfilePositioning').textContent = res.success ? T('ci.profile.not_available') : (res.error || T('ci.js.failed'));
        }
    };

    ciSwitchTab('dashboard');
    refreshUnreadBadge();
})();
JS;
        return $script;
    }
}
