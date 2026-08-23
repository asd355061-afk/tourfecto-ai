<?php

/**
 * Tourfecto - Proactive Alerts Service
 * تنبيهات استباقية (Rule-based) لحملات الإعلانات - بتقيّم حملات المستخدم
 * النشطة مقابل قواعد قابلة للضبط (budget_exhausted / cpc_spike / ctr_drop /
 * landing_page_down / budget_pacing) وبتولّد تنبيهات ad_alerts + إشعارات
 * داخلية. مبدأ أساسي: كل تقييم مبني على بيانات أداء مُزامنة فعلية من
 * ad_performance_reports - لو مفيش بيانات كافية لأي قاعدة، بتتسكى بصمت
 * (insufficient_data) ومفيش أي رقم مُختلق.
 * @version 1.0.0
 */
class AdAlertService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ================================================================
    // إدارة القواعد
    // ================================================================

    /** يرجع قواعد المستخدم كـ map: rule_type => [is_enabled, threshold_value] */
    public function getRules(int $userId): array
    {
        $rules = AdAlertRule::forUser($userId);
        $out = [];
        foreach ($rules as $rule) {
            $out[$rule->getAttribute('rule_type')] = [
                'is_enabled' => (int) $rule->getAttribute('is_enabled'),
                'threshold_value' => $rule->getAttribute('threshold_value') !== null
                    ? (float) $rule->getAttribute('threshold_value')
                    : null,
            ];
        }
        return $out;
    }

    /**
     * حفظ/تحديث قواعد المستخدم. $data شكلها:
     * ['rules' => ['budget_exhausted' => ['is_enabled'=>1,'threshold_value'=>90], ...]]
     */
    public function saveRules(int $userId, array $data): array
    {
        $incoming = $data['rules'] ?? $data;
        if (!is_array($incoming)) {
            throw new InvalidArgumentException('صيغة القواعد غير صحيحة');
        }

        $known = ['budget_exhausted', 'cpc_spike', 'ctr_drop', 'landing_page_down', 'budget_pacing'];
        foreach ($incoming as $type => $cfg) {
            if (!in_array($type, $known, true)) {
                continue;
            }
            $isEnabled = (int) (($cfg['is_enabled'] ?? true) ? 1 : 0);
            $threshold = ($cfg['threshold_value'] ?? null);
            $threshold = ($threshold === '' || $threshold === null) ? null : (float) $threshold;

            $existing = (new AdAlertRule())->where(['user_id' => $userId, 'rule_type' => $type], [], 1);
            $rule = !empty($existing) ? $existing[0] : new AdAlertRule([
                'user_id' => $userId, 'rule_type' => $type,
            ]);
            $rule->setAttribute('is_enabled', $isEnabled);
            if ($threshold !== null || $type !== 'landing_page_down') {
                $rule->setAttribute('threshold_value', $threshold);
            }
            $rule->save();
        }

        ActivityLog::record('ads_alerts', 'rules.updated', [
            'user_id' => $userId, 'subject_type' => 'ad_alert_rules',
            'subject_id' => $userId,
        ]);

        return $this->getRules($userId);
    }

    // ================================================================
    // التقييم
    // ================================================================

    /**
     * يقيّم كل الحملات النشطة لمستخدم مقابل قواعده المفعّلة ويولّد تنبيهات.
     * @return array{generated:int, evaluated:int, insufficient_data:int, alerts:array}
     */
    public function evaluateForUser(int $userId, int $limit = 100): array
    {
        $rules = $this->getRules($userId);
        $campaigns = (new AdCampaign())->where(['user_id' => $userId, 'status' => 'active'], [], $limit);

        $summary = ['generated' => 0, 'evaluated' => 0, 'insufficient_data' => 0, 'alerts' => []];
        foreach ($campaigns as $campaign) {
            $campaignId = (int) $campaign->getAttribute('id');
            $campaignName = (string) $campaign->getAttribute('name');

            foreach ($rules as $ruleType => $cfg) {
                if (!$cfg['is_enabled']) {
                    continue;
                }
                $alert = $this->evaluateRule($userId, $campaignId, $campaignName, $ruleType, $cfg['threshold_value']);
                if ($alert === 'insufficient_data') {
                    $summary['insufficient_data']++;
                    continue;
                }
                if ($alert !== null) {
                    $summary['generated']++;
                    $summary['alerts'][] = $alert;
                    $this->persistAlert($alert);
                    $this->notifyUser($alert);
                }
            }
            $summary['evaluated']++;
        }

        return $summary;
    }

    /**
     * يقيّم قاعدة واحدة لحملة. بيرجّع null (مفيش مخالفة)، أو صف تنبيه،
     * أو سلسلة 'insufficient_data' (مفيش بيانات أداء فعلية كافية).
     */
    private function evaluateRule(int $userId, int $campaignId, string $campaignName, string $ruleType, ?float $threshold): ?array
    {
        // بيانات اليوم (للإنفاق ومعدل الصرف) - حقيقية من المزامنة
        $todayRows = $this->db->query(
            "SELECT SUM(spend) AS spend, SUM(clicks) AS clicks, SUM(impressions) AS impressions
             FROM ad_performance_reports
             WHERE campaign_id = ? AND date_start = CURDATE()",
            [$campaignId]
        );
        $today = $todayRows[0] ?? [];
        $todaySpend = (float) ($today['spend'] ?? 0);
        $todayClicks = (int) ($today['clicks'] ?? 0);
        $todayImpressions = (int) ($today['impressions'] ?? 0);

        switch ($ruleType) {
            case 'budget_exhausted': {
                $budget = (float) ((new AdCampaign())->find($campaignId)->getAttribute('daily_budget') ?? 0);
                if ($budget <= 0) {
                    return 'insufficient_data';
                }
                $thresholdPct = $threshold !== null ? $threshold : 90.0;
                $spentPct = ($todaySpend / $budget) * 100;
                if ($spentPct >= $thresholdPct) {
                    return [
                        'user_id' => $userId, 'campaign_id' => $campaignId, 'rule_type' => $ruleType,
                        'severity' => 'critical',
                        'title' => 'الميزانية اليومية أوشكت على النفاد',
                        'body' => sprintf(
                            'حملة "%s" صرفت %.2f%% من ميزانيتها اليومية (%.2f من أصل %.2f).',
                            $campaignName,
                            $spentPct,
                            $todaySpend,
                            $budget
                        ),
                        'alert_date' => date('Y-m-d'),
                    ];
                }
                return null;
            }

            case 'cpc_spike': {
                $recent = $this->avgCpc($campaignId, 7, 0);
                $baseline = $this->avgCpc($campaignId, 7, 7);
                if ($recent === null || $baseline === null || $baseline <= 0) {
                    return 'insufficient_data';
                }
                $thresholdPct = $threshold !== null ? $threshold : 200.0;
                $ratioPct = ($recent / $baseline) * 100;
                if ($ratioPct >= $thresholdPct) {
                    return [
                        'user_id' => $userId, 'campaign_id' => $campaignId, 'rule_type' => $ruleType,
                        'severity' => 'warning',
                        'title' => 'ارتفاع ملحوظ في تكلفة النقرة',
                        'body' => sprintf(
                            'حملة "%s": متوسط تكلفة النقرة آخر 7 أيام %.2f مقارنة بـ %.2f الأسبوع السابق (%d%% من المتوسط).',
                            $campaignName,
                            $recent,
                            $baseline,
                            round($ratioPct)
                        ),
                        'alert_date' => date('Y-m-d'),
                    ];
                }
                return null;
            }

            case 'ctr_drop': {
                $recent = $this->avgCtr($campaignId, 7, 0);
                $baseline = $this->avgCtr($campaignId, 7, 7);
                if ($recent === null || $baseline === null || $baseline <= 0) {
                    return 'insufficient_data';
                }
                $thresholdPct = $threshold !== null ? $threshold : 50.0;
                $dropPct = (1 - ($recent / $baseline)) * 100;
                if ($dropPct >= $thresholdPct) {
                    return [
                        'user_id' => $userId, 'campaign_id' => $campaignId, 'rule_type' => $ruleType,
                        'severity' => 'warning',
                        'title' => 'انخفاض ملحوظ في نسبة النقر',
                        'body' => sprintf(
                            'حملة "%s": نسبة النقر آخر 7 أيام %.2f%% مقابل %.2f%% الأسبوع السابق (انخفاض %d%%).',
                            $campaignName,
                            $recent,
                            $baseline,
                            round($dropPct)
                        ),
                        'alert_date' => date('Y-m-d'),
                    ];
                }
                return null;
            }

            case 'landing_page_down': {
                $campaign = (new AdCampaign())->find($campaignId);
                $url = (string) ($campaign->getAttribute('landing_page_url') ?? '');
                if ($url === '') {
                    return 'insufficient_data';
                }
                if (!$this->isPageReachable($url)) {
                    return [
                        'user_id' => $userId, 'campaign_id' => $campaignId, 'rule_type' => $ruleType,
                        'severity' => 'critical',
                        'title' => 'صفحة الهبوط غير متاحة',
                        'body' => sprintf('صفحة هبوط حملة "%s" (%s) لا تستجيب - راجع الرابط فورًا.', $campaignName, $url),
                        'alert_date' => date('Y-m-d'),
                    ];
                }
                return null;
            }

            case 'budget_pacing': {
                $budget = (float) ((new AdCampaign())->find($campaignId)->getAttribute('daily_budget') ?? 0);
                if ($budget <= 0) {
                    return 'insufficient_data';
                }
                $thresholdPct = $threshold !== null ? $threshold : 75.0;
                $elapsedPct = $this->elapsedDayPct();
                if ($elapsedPct >= $thresholdPct && $todaySpend < $budget * 0.5) {
                    return [
                        'user_id' => $userId, 'campaign_id' => $campaignId, 'rule_type' => $ruleType,
                        'severity' => 'info',
                        'title' => 'الحملة بتصرف أبطأ من المتوقع',
                        'body' => sprintf(
                            'حملة "%s" صرفت %.2f من أصل %.2f الميزانية اليومية (%.0f%% من اليوم عدّى). ممكن تحتاج رفع العروض أو توسيع الجمهور.',
                            $campaignName,
                            $todaySpend,
                            $budget,
                            $elapsedPct
                        ),
                        'alert_date' => date('Y-m-d'),
                    ];
                }
                return null;
            }
        }

        return null;
    }

    // ================================================================
    // مساعدون
    // ================================================================

    /**
     * متوسط تكلفة النقرة (أو null لو مفيش بيانات) على نافذة زمنية.
     * @param int $campaignId
     * @param int $days عدد أيام النافذة
     * @param int $offset offset بالأيام قبل النافذة (0 = أحدث نافذة)
     */
    private function avgCpc(int $campaignId, int $days, int $offset): ?float
    {
        $rows = $this->db->query(
            "SELECT SUM(spend) AS spend, SUM(clicks) AS clicks
             FROM ad_performance_reports
             WHERE campaign_id = ? AND date_start BETWEEN
                 DATE_SUB(CURDATE(), INTERVAL ? DAY) AND DATE_SUB(CURDATE(), INTERVAL ? DAY)",
            [$campaignId, $offset + $days, $offset + 1]
        );
        $row = $rows[0] ?? [];
        $clicks = (int) ($row['clicks'] ?? 0);
        if ($clicks <= 0) {
            return null;
        }
        return (float) ($row['spend'] ?? 0) / $clicks;
    }

    /** متوسط نسبة النقر (أو null) على نافذة زمنية */
    private function avgCtr(int $campaignId, int $days, int $offset): ?float
    {
        $rows = $this->db->query(
            "SELECT SUM(clicks) AS clicks, SUM(impressions) AS impressions
             FROM ad_performance_reports
             WHERE campaign_id = ? AND date_start BETWEEN
                 DATE_SUB(CURDATE(), INTERVAL ? DAY) AND DATE_SUB(CURDATE(), INTERVAL ? DAY)",
            [$campaignId, $offset + $days, $offset + 1]
        );
        $row = $rows[0] ?? [];
        $clicks = (int) ($row['clicks'] ?? 0);
        $impressions = (int) ($row['impressions'] ?? 0);
        if ($impressions <= 0) {
            return null;
        }
        return ($clicks / $impressions) * 100;
    }

    /** نسبة الوقت المنقضي من اليوم (ساعات/24 * 100) */
    private function elapsedDayPct(): float
    {
        $hour = (int) date('G');
        return round(($hour / 24) * 100, 1);
    }

    /** فحص وصول صفحة هبوط (cURL) - نفس منهجية LandingPageAnalysisService */
    private function isPageReachable(string $url): bool
    {
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        if (!function_exists('curl_init')) {
            return true; // مفيش cURL - نتجنب إنذار خاطئ
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TourfectoBot/1.0; +https://tourfecto.com/bot)',
        ]);
        $ok = curl_exec($ch) !== false;
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $ok && $httpCode >= 200 && $httpCode < 400;
    }

    private function persistAlert(array $alert): void
    {
        $existing = AdAlert::existsToday((int) $alert['user_id'], (int) $alert['campaign_id'], $alert['rule_type']);
        if ($existing) {
            return;
        }
        (new AdAlert([
            'user_id' => (int) $alert['user_id'],
            'campaign_id' => (int) $alert['campaign_id'],
            'rule_type' => $alert['rule_type'],
            'severity' => $alert['severity'],
            'title' => $alert['title'],
            'body' => $alert['body'],
            'is_read' => 0,
            'is_dismissed' => 0,
            'alert_date' => $alert['alert_date'],
        ]))->save();
    }

    private function notifyUser(array $alert): void
    {
        if (!class_exists('Notification')) {
            return;
        }
        $link = '/ads?tab=alerts&campaign=' . (int) $alert['campaign_id'];
        Notification::notify(
            (int) $alert['user_id'],
            'ads_alert_' . $alert['rule_type'],
            $alert['title'],
            (string) ($alert['body'] ?? ''),
            $link
        );
    }

    // ================================================================
    // استرجاع / إدارة التنبيهات
    // ================================================================

    public function listForUser(int $userId, int $limit = 50, bool $onlyUnread = false): array
    {
        return array_map(fn ($a) => $a->toArray(), AdAlert::recentForUser($userId, $limit, $onlyUnread));
    }

    public function unreadCount(int $userId): int
    {
        return AdAlert::unreadCount($userId);
    }

    public function markAllRead(int $userId): bool
    {
        return AdAlert::markAllReadForUser($userId);
    }

    public function dismiss(int $userId, int $alertId): bool
    {
        $alert = (new AdAlert())->find($alertId);
        if (!$alert || (int) $alert->getAttribute('user_id') !== $userId) {
            return false;
        }
        $alert->setAttribute('is_dismissed', 1);
        $alert->save();
        return true;
    }

    // ================================================================
    // نقطة تشغيل دورية (تُستدعى من cron/run_ads_alerts.php)
    // ================================================================

    /** يقيّم كل العملاء ويولّد تنبيهات. @return array ملخص للـ cron log */
    public function runForAllUsers(): array
    {
        $summary = ['users_evaluated' => 0, 'alerts_generated' => 0, 'errors' => 0];

        $users = $this->db->query(
            "SELECT DISTINCT user_id FROM ad_campaigns WHERE status = 'active'"
        );
        foreach ($users as $user) {
            $userId = (int) $user['user_id'];
            try {
                $result = $this->evaluateForUser($userId);
                $summary['users_evaluated']++;
                $summary['alerts_generated'] += $result['generated'];
            } catch (Throwable $e) {
                $summary['errors']++;
                if (class_exists('Logger')) {
                    Logger::error('AdAlertService::runForAllUsers failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
                }
            }
        }

        return $summary;
    }
}
