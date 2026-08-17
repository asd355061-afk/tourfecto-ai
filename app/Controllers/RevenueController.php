<?php

/**
 * Tourfecto - Revenue Intelligence Controller
 * أول مصدر بيانات إيرادات حقيقي في المنصة - قبل كده كانت بطاقة
 * "الإيرادات المباشرة" في لوحة القيادة التنفيذية بتفضل "مش متصلة بعد"
 * لعدم وجود جدول orders/مدفوعات. هنا بندخل الإيرادات يدويًا (أو من
 * مصدر خارجي لاحقًا) ونحسب KPIs حقيقية منها.
 * @version 1.0.0 - BATCH6
 */
class RevenueController extends Controller
{
    /** GET /revenue */
    public function index(array $params = []): array
    {
        $body = <<<HTML
        <div class="p-grid cols-4" id="revKpis">
            <div class="p-card stat-tile"><div class="stat-icon green">💰</div><div class="stat-info"><div class="stat-value" id="revTotal">0</div><div class="stat-label">{$this->tr('revenue.kpi.total')}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon orange">📣</div><div class="stat-info"><div class="stat-value" id="revSpend">0</div><div class="stat-label">{$this->tr('revenue.kpi.spend')}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon purple">📈</div><div class="stat-info"><div class="stat-value" id="revRoas">-</div><div class="stat-label">{$this->tr('revenue.kpi.roas')}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon blue">🧲</div><div class="stat-info"><div class="stat-value" id="revCac">-</div><div class="stat-label">{$this->tr('revenue.kpi.cac')}</div></div></div>
        </div>

        <div class="p-grid cols-2" style="margin-top:18px;align-items:start;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$this->tr('revenue.chart.title')}</h3></div>
                <div style="padding:10px 4px;"><canvas id="revTrendChart" height="110"></canvas></div>
            </div>
            <div class="p-card no-pad">
                <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('revenue.add.title')}</h3></div>
                <div style="padding:16px 20px;display:flex;flex-direction:column;gap:10px;">
                    <select id="revSource" class="p-select"><option value="booking">{$this->tr('revenue.source.booking')}</option><option value="order">{$this->tr('revenue.source.order')}</option><option value="subscription">{$this->tr('revenue.source.subscription')}</option><option value="manual">{$this->tr('revenue.source.other')}</option></select>
                    <input type="number" id="revAmount" class="p-input" placeholder="{$this->tr('revenue.amount_placeholder')}" step="0.01">
                    <input type="text" id="revNotes" class="p-input" placeholder="{$this->tr('revenue.notes_placeholder')}">
                    <button class="p-btn primary" onclick="revAdd()">{$this->tr('revenue.add_btn')}</button>
                </div>
            </div>
        </div>

        <div class="p-card no-pad" style="margin-top:18px;">
            <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('revenue.recent.title')}</h3></div>
            <div class="p-table-scroll"><table class="p-table" id="revTable">
                <thead><tr><th>{$this->tr('revenue.col.source')}</th><th>{$this->tr('revenue.col.amount')}</th><th>{$this->tr('revenue.col.date')}</th><th>{$this->tr('revenue.col.notes')}</th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="4">{$this->tr('common.loading')}</td></tr></tbody>
            </table></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    let chart = null;

    window.revAdd = async function () {
        const source = document.getElementById('revSource').value;
        const amount = parseFloat(document.getElementById('revAmount').value);
        const notes = document.getElementById('revNotes').value;
        if (!amount || amount <= 0) { toast(I18N['common.invalid_amount'], 'error'); return; }

        const res = await fetchJSON('/api/revenue/records', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ source, amount, notes })
        });
        if (res.success) { toast(I18N['common.added'], 'success'); document.getElementById('revAmount').value = ''; document.getElementById('revNotes').value = ''; load(); }
        else { toast(res.error || I18N['common.add_failed'], 'error'); }
    };

    function fmt(n) { return '$' + (parseFloat(n) || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }); }

    async function load() {
        const res = await fetchJSON('/api/revenue/kpis?days=30');
        if (!res.success) { toast(res.error || I18N['common.load_failed'], 'error'); return; }

        const k = res.data.kpis;
        document.getElementById('revTotal').textContent = fmt(k.revenue_total);
        document.getElementById('revSpend').textContent = fmt(k.spend_total);
        document.getElementById('revRoas').textContent = k.roas ? (k.roas + 'x') : '-';
        document.getElementById('revCac').textContent = k.cac ? fmt(k.cac) : '-';

        const rows = res.data.recent || [];
        const tbody = document.querySelector('#revTable tbody');
        tbody.innerHTML = rows.length ? rows.map(r => `
            <tr><td>${esc(r.source)}</td><td>${fmt(r.amount)}</td><td>${formatDate(r.recorded_at)}</td><td>${esc(r.notes || '-')}</td></tr>
        `).join('') : `<tr><td colspan="4" class="p-cell-muted">${I18N['common.no_records_yet']}</td></tr>`;

        if (typeof Chart !== 'undefined') {
            const trend = res.data.trend || [];
            if (chart) chart.destroy();
            chart = new Chart(document.getElementById('revTrendChart'), {
                type: 'line',
                data: {
                    labels: trend.map(t => t.date),
                    datasets: [
                        { label: 'إيرادات', data: trend.map(t => t.revenue), borderColor: '#3FA796', backgroundColor: 'transparent', tension: 0.3 },
                        { label: 'إنفاق', data: trend.map(t => t.spend), borderColor: '#E2A03F', backgroundColor: 'transparent', tension: 0.3 },
                    ]
                },
                options: { responsive: true }
            });
        }
    }

    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('revenue', $this->tr('revenue.page.title'), $this->tr('revenue.page.subtitle'), $body, $script);
        exit;
    }

    /** POST /api/revenue/records */
    public function createRecord(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $amount = (float) $this->get('amount');
        $source = $this->get('source', 'manual');
        $notes = $this->get('notes');

        if ($amount <= 0) {
            return $this->error('المبلغ لازم يكون أكبر من صفر', 422);
        }

        try {
            $this->db->exec(
                "INSERT INTO rev_revenue_records (user_id, source, amount, currency, recorded_at, notes)
                 VALUES (?, ?, ?, 'USD', NOW(), ?)",
                [$this->user['id'], $source, $amount, $notes]
            );

            // Revenue Intelligence module hook (section 25): يسمح لأي كاش/إعادة
            // حساب مرتبطة بالموديول تتحدّث لحظيًا - بدون ما RevenueController
            // نفسه يعرف حاجة عن وجود موديول Revenue Intelligence أصلاً.
            if (function_exists('event')) {
                event('revenue.updated', ['user_id' => (int) $this->user['id'], 'amount' => $amount, 'source' => $source]);
            }

            return $this->success([], 'تم تسجيل الإيراد');
        } catch (Exception $e) {
            Logger::error('Revenue Create Record Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تسجيل الإيراد', 500);
        }
    }

    /** GET /api/revenue/kpis */
    public function getKpis(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $days = max(1, min(365, (int) $this->get('days', 30)));
        $userId = (int) $this->user['id'];

        try {
            $revenueRow = $this->db->query(
                "SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as count
                 FROM rev_revenue_records
                 WHERE user_id = ? AND recorded_at > DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$userId, $days]
            );
            $spendRow = $this->db->query(
                "SELECT COALESCE(SUM(amount), 0) as total
                 FROM rev_marketing_spend
                 WHERE user_id = ? AND spend_date > DATE_SUB(CURDATE(), INTERVAL ? DAY)",
                [$userId, $days]
            );
            // إنفاق حقيقي من ad_campaigns لو المستخدم رابط حساباته فعليًا
            $adsSpendRow = $this->db->query(
                "SELECT COALESCE(SUM(spend), 0) as total FROM ad_campaigns WHERE user_id = ?",
                [$userId]
            );
            $leadsRow = $this->db->query(
                "SELECT COUNT(*) as count FROM crm_leads WHERE owner_user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$userId, $days]
            );

            $revenueTotal = (float) $revenueRow[0]['total'];
            $spendTotal = (float) $spendRow[0]['total'] + (float) $adsSpendRow[0]['total'];
            $newLeads = (int) ($leadsRow[0]['count'] ?? 0);

            $kpis = [
                'revenue_total' => round($revenueTotal, 2),
                'spend_total' => round($spendTotal, 2),
                'new_leads' => $newLeads,
                'roas' => $spendTotal > 0 ? round($revenueTotal / $spendTotal, 2) : null,
                'cac' => $newLeads > 0 ? round($spendTotal / $newLeads, 2) : null,
            ];

            $recent = $this->db->query(
                "SELECT source, amount, recorded_at, notes FROM rev_revenue_records
                 WHERE user_id = ? ORDER BY recorded_at DESC LIMIT 20",
                [$userId]
            );

            $trendRows = $this->db->query(
                "SELECT DATE(recorded_at) as d, SUM(amount) as revenue
                 FROM rev_revenue_records
                 WHERE user_id = ? AND recorded_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY DATE(recorded_at) ORDER BY d ASC",
                [$userId, $days]
            );
            $spendTrendRows = $this->db->query(
                "SELECT spend_date as d, SUM(amount) as spend
                 FROM rev_marketing_spend
                 WHERE user_id = ? AND spend_date > DATE_SUB(CURDATE(), INTERVAL ? DAY)
                 GROUP BY spend_date ORDER BY d ASC",
                [$userId, $days]
            );
            $spendByDate = [];
            foreach ($spendTrendRows as $r) {
                $spendByDate[$r['d']] = (float) $r['spend'];
            }
            $trend = array_map(function ($r) use ($spendByDate) {
                return ['date' => date('d M', strtotime($r['d'])), 'revenue' => round((float) $r['revenue'], 2), 'spend' => round($spendByDate[$r['d']] ?? 0, 2)];
            }, $trendRows);

            return $this->success(['kpis' => $kpis, 'recent' => $recent, 'trend' => $trend]);
        } catch (Exception $e) {
            Logger::error('Revenue Get KPIs Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحميل بيانات الإيرادات', 500);
        }
    }
}
