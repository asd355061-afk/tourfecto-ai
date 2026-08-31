<?php

/**
 * Tourfecto - Agency Controller (White-Label)
 * @version 1.0.0
 */
class AgencyController extends Controller
{
    /** @var AgencyService */
    private $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new AgencyService();
    }

    /** GET /agency */
    public function index(array $params = []): array
    {
        $body = <<<HTML
        <div class="p-toolbar">
            <button class="p-btn" onclick="document.getElementById('newAgencyModal').classList.add('open')">+ وكالة جديدة</button>
        </div>
        <div class="p-grid cols-2" id="agencyGrid"><div class="p-empty">جارِ التحميل...</div></div>

        <div class="p-modal-overlay" id="newAgencyModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>وكالة جديدة</h3><button class="p-modal-close" onclick="document.getElementById('newAgencyModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label>اسم الوكالة</label>
                    <input type="text" id="agencyName" class="p-select" style="width:100%;">
                </div>
                <div class="p-modal-foot"><button class="p-btn" onclick="createAgency()">إنشاء</button></div>
            </div>
        </div>

        <div class="p-modal-overlay" id="clientsModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3 id="clientsModalTitle">عملاء الوكالة</h3><button class="p-modal-close" onclick="document.getElementById('clientsModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <div style="display:flex;gap:8px;margin-bottom:14px;">
                        <input type="email" id="newClientEmail" class="p-select" style="flex:1;" placeholder="بريد العميل الإلكتروني (لازم يكون مسجّل في تورفكتو بالفعل)">
                        <button class="p-btn" onclick="addClient()">+ إضافة</button>
                    </div>
                    <div id="clientsAlert" class="alert alert-danger" style="display:none;"></div>
                    <div id="clientsList" class="p-empty">جارِ التحميل...</div>
                </div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON;
    let currentAgencyId = null;

    async function load() {
        const res = await fetchJSON('/api/agency/list');
        const grid = document.getElementById('agencyGrid');
        if (res.success && res.data.agencies && res.data.agencies.length) {
            grid.innerHTML = res.data.agencies.map(a => `
                <div class="p-card">
                    <div class="p-card-head"><h3>${esc(a.name)}</h3><span class="p-card-sub">${esc(a.status)}</span></div>
                    <div class="p-kv"><span class="k">عدد المقاعد</span><span class="v">${esc(a.plan_seats)}</span></div>
                    <div class="p-kv"><span class="k">تاريخ الإنشاء</span><span class="v">${esc(a.created_at)}</span></div>
                    <button class="p-btn outline xs" style="margin-top:10px;" onclick="openClients(${a.id}, '${esc(a.name)}')">👥 إدارة العملاء</button>
                </div>
            `).join('');
        } else {
            grid.innerHTML = '<div class="p-empty"><div class="p-empty-icon">🏢</div>لا يوجد وكالات بعد</div>';
        }
    }

    window.createAgency = async function () {
        const name = document.getElementById('agencyName').value.trim();
        if (!name) return;
        const res = await fetchJSON('/api/agency/create', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name }) });
        document.getElementById('newAgencyModal').classList.remove('open');
        if (res.success) { P.toast('تم إنشاء الوكالة', 'success'); load(); }
        else P.toast(res.error || 'فشل الإنشاء', 'error');
    };

    window.openClients = function (agencyId, name) {
        currentAgencyId = agencyId;
        document.getElementById('clientsModalTitle').textContent = 'عملاء وكالة: ' + name;
        document.getElementById('clientsAlert').style.display = 'none';
        document.getElementById('newClientEmail').value = '';
        document.getElementById('clientsModal').classList.add('open');
        loadClients();
    };

    async function loadClients() {
        const box = document.getElementById('clientsList');
        box.innerHTML = 'جارِ التحميل...';
        const res = await fetchJSON('/api/agency/' + currentAgencyId + '/clients');
        if (res.success && res.data.clients && res.data.clients.length) {
            box.innerHTML = res.data.clients.map(c => `
                <div class="p-kv">
                    <span class="k">${esc(c.company_name || c.email)}</span>
                    <span class="v" style="display:flex;align-items:center;gap:8px;">
                        <span class="pill green">${esc(c.status)}</span>
                        <button class="p-btn danger xs" onclick="removeClient(${c.id})">إزالة</button>
                    </span>
                </div>`).join('');
        } else {
            box.innerHTML = '<div class="p-cell-muted" style="padding:10px 0;">لا يوجد عملاء مضافين لهذه الوكالة بعد</div>';
        }
    }

    window.addClient = async function () {
        const email = document.getElementById('newClientEmail').value.trim();
        const alertBox = document.getElementById('clientsAlert');
        alertBox.style.display = 'none';
        if (!email) return;

        const res = await fetchJSON('/api/agency/' + currentAgencyId + '/clients', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email }),
        });

        if (res.success) {
            document.getElementById('newClientEmail').value = '';
            P.toast('تمت إضافة العميل', 'success');
            loadClients();
        } else {
            alertBox.textContent = res.error || 'تعذر الإضافة';
            alertBox.style.display = 'block';
        }
    };

    window.removeClient = async function (clientId) {
        if (!confirm('متأكد من إزالة هذا العميل من الوكالة؟')) return;
        const res = await fetchJSON('/api/agency/' + currentAgencyId + '/clients/' + clientId, { method: 'DELETE' });
        if (res.success) { P.toast('تمت الإزالة', 'success'); loadClients(); }
        else P.toast(res.error || 'تعذر الإزالة', 'error');
    };

    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('agency', 'White Label - الوكالات', 'إدارة الوكالات والعملاء التابعين لها', $body, $script);
        exit;
    }

    /** GET /api/agency/list */
    public function list(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $agencies = (new Agency())->where(['owner_user_id' => $this->user['id']], ['created_at' => 'DESC']);
        return $this->success(['agencies' => array_map(fn ($a) => $a->toArray(), $agencies)]);
    }

    /** POST /api/agency/create */
    public function create(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['name' => 'required'])) {
            return $this->error('اسم الوكالة مطلوب', 422);
        }

        if (!in_array($this->user['role'] ?? '', ['super_admin', 'admin', 'agency_owner'], true)) {
            return $this->error('صلاحياتك الحالية لا تسمح بإنشاء وكالة White-Label - تواصل مع الدعم لترقية باقتك', 403);
        }

        try {
            $agency = $this->service->createAgency((int) $this->user['id'], (string) $this->get('name'));
            return $this->success(['agency' => $agency->toArray()], 'تم إنشاء الوكالة', 201);
        } catch (Exception $e) {
            Logger::error('createAgency Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنشاء الوكالة', 500);
        }
    }

    /** يتأكد إن الوكالة دي فعلاً ملك المستخدم الحالي، أو يرجّع null */
    private function ownedAgency(int $agencyId): ?Agency
    {
        $agency = (new Agency())->find($agencyId);
        if (!$agency || (int) $agency->getAttribute('owner_user_id') !== (int) $this->user['id']) {
            return null;
        }
        return $agency;
    }

    /** GET /api/agency/{id}/clients */
    public function listClients(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) {
            return $this->error('الوكالة غير موجودة', 404);
        }

        try {
            $links = (new AgencyClient())->where(['agency_id' => $agency->getAttribute('id')]);
            if (empty($links)) {
                return $this->success(['clients' => []]);
            }

            // تصحيح أداء: كان بيعمل استعلام منفصل لكل عميل جوه اللوب
            // (N+1) - لو الوكالة عندها 50 عميل كان بيبعت 50 استعلام
            // بدل واحد بس. دلوقتي استعلام واحد مجمّع بـ IN (...).
            $userIds = array_map(fn ($link) => (int) $link->getAttribute('client_user_id'), $links);
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $users = $this->db->query("SELECT id, email, company_name FROM users WHERE id IN ({$placeholders})", $userIds);
            $usersById = [];
            foreach ($users as $u) {
                $usersById[(int) $u['id']] = $u;
            }

            $clients = [];
            foreach ($links as $link) {
                $user = $usersById[(int) $link->getAttribute('client_user_id')] ?? null;
                if ($user) {
                    $clients[] = [
                        'id' => $link->getAttribute('id'),
                        'email' => $user['email'],
                        'company_name' => $user['company_name'],
                        'status' => $link->getAttribute('status'),
                    ];
                }
            }
            return $this->success(['clients' => $clients]);
        } catch (Exception $e) {
            Logger::error('listClients Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب العملاء', 500);
        }
    }

    /** POST /api/agency/{id}/clients - إضافة عميل موجود بالفعل في تورفكتو للوكالة عن طريق بريده */
    public function addClient(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['email' => 'required|email'])) {
            return $this->error('بريد إلكتروني غير صحيح', 422);
        }

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) {
            return $this->error('الوكالة غير موجودة', 404);
        }

        $clientUser = User::findByEmail((string) $this->get('email'));
        if (!$clientUser) {
            return $this->error('مفيش حساب مسجّل بالبريد ده في تورفكتو - العميل لازم يكون له حساب حقيقي الأول', 404);
        }
        if ((int) $clientUser->getAttribute('id') === (int) $this->user['id']) {
            return $this->error('متقدرش تضيف نفسك كعميل لوكالتك', 422);
        }

        try {
            $client = $this->service->addClient((int) $agency->getAttribute('id'), (int) $clientUser->getAttribute('id'));
            return $this->success(['client' => $client->toArray()], 'تمت إضافة العميل', 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** DELETE /api/agency/{id}/clients/{clientId} */
    public function removeClient(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) {
            return $this->error('الوكالة غير موجودة', 404);
        }

        try {
            $link = (new AgencyClient())->find((int) ($params['clientId'] ?? 0));
            if (!$link || (int) $link->getAttribute('agency_id') !== (int) $agency->getAttribute('id')) {
                return $this->error('العميل غير موجود في هذه الوكالة', 404);
            }
            $link->delete();
            ActivityLog::record('white_label', 'agency.client_removed', [
                'agency_id' => $agency->getAttribute('id'), 'subject_type' => 'agency_clients', 'subject_id' => (int) $params['clientId'],
            ]);
            return $this->success([], 'تمت إزالة العميل');
        } catch (Exception $e) {
            Logger::error('removeClient Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الإزالة', 500);
        }
    }

    /** GET /api/agency/{id}/commissions - عمولات الوكالة (عزل صارم: وكالة المستخدم الحالي فقط) */
    public function listCommissions(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) {
            return $this->error('الوكالة غير موجودة', 404);
        }

        try {
            $rows = $this->db->query(
                'SELECT c.id, c.booking_id, c.commission_amount, c.status, c.created_at,
                        b.booking_reference, b.customer_name, b.total_amount, b.currency,
                        u.company_name AS client_name
                 FROM agency_commissions c
                 JOIN bookings b ON b.id = c.booking_id
                 JOIN users u ON u.id = b.user_id
                 WHERE c.agency_id = ?
                 ORDER BY c.created_at DESC
                 LIMIT 200',
                [(int) $agency->getAttribute('id')]
            );
            return $this->success(['commissions' => $rows]);
        } catch (Exception $e) {
            Logger::error('listCommissions Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب العمولات', 500);
        }
    }

    /** POST /api/agency/commissions/{id}/paid - تعليم العمولة كمدفوعة يدويًا (الوكيل/الأدمن) */
    public function markCommissionPaid(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $commissionId = (int) ($params['id'] ?? 0);
        if ($commissionId <= 0) {
            return $this->error('رقم العمولة غير صحيح', 422);
        }

        try {
            $commission = (new AgencyCommission())->find($commissionId);
            if (!$commission) {
                return $this->error('العمولة غير موجودة', 404);
            }

            // عزل صارم: العمولة لازم تتبع وكالة يملكها المستخدم الحالي
            // (تتجاهل الوكالات التانية تمامًا وكأنها غير موجودة)
            $agency = $this->ownedAgency((int) $commission->getAttribute('agency_id'));
            if (!$agency) {
                return $this->error('العمولة غير موجودة', 404);
            }

            if ($commission->getAttribute('status') === 'paid') {
                return $this->success(['commission' => $commission->toArray()], 'هذه العمولة مدفوعة بالفعل');
            }

            $commission->setAttribute('status', 'paid');
            $commission->save();
            ActivityLog::record('white_label', 'agency.commission_paid', [
                'agency_id' => (int) $agency->getAttribute('id'),
                'subject_type' => 'agency_commissions', 'subject_id' => $commissionId,
            ]);
            return $this->success(['commission' => $commission->toArray()], 'تم تعليم العمولة كمدفوعة');
        } catch (Exception $e) {
            Logger::error('markCommissionPaid Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحديث العمولة', 500);
        }
    }

    /** GET /api/agency/{id}/performance - تقرير أداء الوكالة (عزل صارم ببيانات وكالته بس) */
    public function performanceReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) {
            return $this->error('الوكالة غير موجودة', 404);
        }
        $agencyId = (int) $agency->getAttribute('id');

        try {
            // 1) العملاء النشطون
            $clients = (new AgencyClient())->where(['agency_id' => $agencyId, 'status' => 'active']);
            $activeClientsCount = count($clients);
            $clientUserIds = array_map(fn ($c) => (int) $c->getAttribute('client_user_id'), $clients);

            // 2) الحجوزات المؤكدة + إيرادها - مقتصرة على عملاء وكالته حصرًا
            $confirmedCount = 0;
            $totalRevenue = 0.0;
            if (!empty($clientUserIds)) {
                $placeholders = implode(',', array_fill(0, count($clientUserIds), '?'));
                $agg = $this->db->query(
                    "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS revenue
                     FROM bookings
                     WHERE status = 'confirmed' AND user_id IN ({$placeholders})",
                    $clientUserIds
                );
                if (!empty($agg)) {
                    $confirmedCount = (int) $agg[0]['cnt'];
                    $totalRevenue = (float) $agg[0]['revenue'];
                }
            }

            // 3) العمولات pending/paid - كلها لوكالة دي (عزل مضمون بـ agency_id)
            $commissions = $this->db->query(
                'SELECT status, COALESCE(SUM(commission_amount), 0) AS total, COUNT(*) AS cnt
                 FROM agency_commissions
                 WHERE agency_id = ?
                 GROUP BY status',
                [$agencyId]
            );
            $commissionTotals = ['pending' => 0.0, 'paid' => 0.0];
            $commissionCounts = ['pending' => 0, 'paid' => 0];
            foreach ($commissions as $c) {
                if (isset($commissionTotals[$c['status']])) {
                    $commissionTotals[$c['status']] = (float) $c['total'];
                    $commissionCounts[$c['status']] = (int) $c['cnt'];
                }
            }

            return $this->success([
                'agency' => ['id' => $agencyId, 'name' => $agency->getAttribute('name')],
                'active_clients_count' => $activeClientsCount,
                'confirmed_bookings_count' => $confirmedCount,
                'total_revenue' => round($totalRevenue, 2),
                'commissions' => [
                    'pending_total' => round($commissionTotals['pending'], 2),
                    'paid_total' => round($commissionTotals['paid'], 2),
                    'pending_count' => $commissionCounts['pending'],
                    'paid_count' => $commissionCounts['paid'],
                ],
            ]);
        } catch (Exception $e) {
            Logger::error('performanceReport Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر توليد تقرير الأداء', 500);
        }
    }

    // ------------------------------------------------------------
    // دعوات العملاء (رمز/رابط القبول)
    // ------------------------------------------------------------

    /** POST /api/agency/{id}/invitations - إنشاء دعوة لعميل حقيقي (بريده) */
    public function createInvitation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['email' => 'required|email'])) {
            return $this->error('بريد إلكتروني غير صحيح', 422);
        }

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) {
            return $this->error('الوكالة غير موجودة', 404);
        }

        $clientUser = User::findByEmail((string) $this->get('email'));
        if (!$clientUser) {
            return $this->error('مفيش حساب مسجّل بالبريد ده في تورفكتو - العميل لازم يكون له حساب حقيقي الأول', 404);
        }
        if ((int) $clientUser->getAttribute('id') === (int) $this->user['id']) {
            return $this->error('متقدرش تدعو نفسك كعميل لوكالتك', 422);
        }
        if (!empty((new AgencyClient())->where(['agency_id' => (int) $agency->getAttribute('id'), 'client_user_id' => (int) $clientUser->getAttribute('id')]))) {
            return $this->error('هذا العميل مضاف بالفعل لهذه الوكالة', 422);
        }

        $rate = (float) $this->get('commission_rate', 10.00);

        try {
            $invitation = $this->service->createInvitation(
                (int) $agency->getAttribute('id'),
                (string) $this->get('email'),
                $rate,
                (int) $this->user['id']
            );
            return $this->success(['invitation' => $invitation->toArray()], 'تم إنشاء دعوة الانضمام', 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** GET /api/agency/{id}/invitations - قائمة دعوات الوكالة */
    public function listInvitations(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) {
            return $this->error('الوكالة غير موجودة', 404);
        }

        try {
            $invitations = array_map(
                fn ($inv) => $inv->toArray(),
                $this->service->listInvitations((int) $agency->getAttribute('id'))
            );
            return $this->success(['invitations' => $invitations]);
        } catch (Exception $e) {
            Logger::error('listInvitations Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الدعوات', 500);
        }
    }

    /** DELETE /api/agency/{id}/invitations/{inviteId} - إلغاء دعوة */
    public function revokeInvitation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) {
            return $this->error('الوكالة غير موجودة', 404);
        }

        $invitation = (new AgencyInvitation())->find((int) ($params['inviteId'] ?? 0));
        if (!$invitation || (int) $invitation->getAttribute('agency_id') !== (int) $agency->getAttribute('id')) {
            return $this->error('الدعوة غير موجودة في هذه الوكالة', 404);
        }

        try {
            $this->service->revokeInvitation((int) $invitation->getAttribute('id'));
            return $this->success([], 'تم إلغاء الدعوة');
        } catch (Exception $e) {
            Logger::error('revokeInvitation Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إلغاء الدعوة', 500);
        }
    }

    /** POST /api/agency/invitations/accept - قبول الدعوة بالرمز (أي مستخدم مسجّل دخوله) */
    public function acceptInvitation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $token = trim((string) $this->get('token'));
        if ($token === '') {
            return $this->error('رمز الدعوة مطلوب', 422);
        }

        try {
            $invitation = $this->service->acceptInvitation((int) $this->user['id'], $token);
            $data = $invitation->toArray();
            // الرمز حساس - لا يُعاد في الاستجابة بعد القبول
            unset($data['token']);
            return $this->success(['invitation' => $data], 'تم الانضمام للوكالة بنجاح');
        } catch (Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ------------------------------------------------------------
    // لوحة تحكم الوكيل
    // ------------------------------------------------------------

    /** GET /api/agency/{id}/dashboard - إحصائيات اللوحة + أداء كل عميل */
    public function agencyDashboard(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) {
            return $this->error('الوكالة غير موجودة', 404);
        }
        $agencyId = (int) $agency->getAttribute('id');

        try {
            $stats = $this->service->agencyStats($agencyId);
            if (empty($stats)) {
                return $this->error('الوكالة غير موجودة', 404);
            }
            return $this->success([
                'stats' => $stats,
                'clients_performance' => $this->service->clientPerformance($agencyId),
            ]);
        } catch (Exception $e) {
            Logger::error('agencyDashboard Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر توليد لوحة الوكالة', 500);
        }
    }
}
