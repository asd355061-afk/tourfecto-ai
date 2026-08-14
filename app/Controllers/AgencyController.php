<?php
/**
 * Tourfecto - Agency Controller (White-Label)
 * @version 1.0.0
 */
class AgencyController extends Controller {
    /** @var AgencyService */
    private $service;

    public function __construct() {
        parent::__construct();
        $this->service = new AgencyService();
    }

    /** GET /agency */
    public function index(array $params = []): array {
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
    public function list(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $agencies = (new Agency())->where(['owner_user_id' => $this->user['id']], ['created_at' => 'DESC']);
        return $this->success(['agencies' => array_map(fn($a) => $a->toArray(), $agencies)]);
    }

    /** POST /api/agency/create */
    public function create(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!$this->validate(['name' => 'required'])) return $this->error('اسم الوكالة مطلوب', 422);

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
    private function ownedAgency(int $agencyId): ?Agency {
        $agency = (new Agency())->find($agencyId);
        if (!$agency || (int) $agency->getAttribute('owner_user_id') !== (int) $this->user['id']) {
            return null;
        }
        return $agency;
    }

    /** GET /api/agency/{id}/clients */
    public function listClients(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) return $this->error('الوكالة غير موجودة', 404);

        try {
            $links = (new AgencyClient())->where(['agency_id' => $agency->getAttribute('id')]);
            if (empty($links)) {
                return $this->success(['clients' => []]);
            }

            // تصحيح أداء: كان بيعمل استعلام منفصل لكل عميل جوه اللوب
            // (N+1) - لو الوكالة عندها 50 عميل كان بيبعت 50 استعلام
            // بدل واحد بس. دلوقتي استعلام واحد مجمّع بـ IN (...).
            $userIds = array_map(fn($link) => (int) $link->getAttribute('client_user_id'), $links);
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
    public function addClient(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!$this->validate(['email' => 'required|email'])) return $this->error('بريد إلكتروني غير صحيح', 422);

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) return $this->error('الوكالة غير موجودة', 404);

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
    public function removeClient(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) return $this->error('الوكالة غير موجودة', 404);

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
}
