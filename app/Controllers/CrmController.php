<?php

/**
 * Tourfecto - CRM Controller
 * لا يوجد نظام CRM منفصل في أي موديول مرفوع؛ هذه اللوحة تجمّع بيانات
 * العملاء الموجودة فعليًا (websites, reviews, agency_clients) في واجهة
 * واحدة بدل تكرار جدول "عملاء" موازٍ يكرر بيانات users/websites أصلًا.
 * @version 1.0.0
 */
class CrmController extends Controller
{
    /** @var CrmLeadService */
    private $leadService;
    private $permissionService;

    public function __construct()
    {
        parent::__construct();
        $this->leadService = new CrmLeadService();
        $this->permissionService = new CrmPermissionService();
    }

    /**
     * الحساب (Tenant) الفعلي - راجع نفس الشرح في CrmApiController::tenantId().
     * إضافة المرحلة 6 (بند 30 - استكمال): هذه الدالة كانت غائبة تمامًا من
     * هذا الملف في المرحلة 5، وبالتالي Leads/Deals ظلّت بمعزل عن نظام
     * الفريق الجديد. تمت إضافتها هنا الآن + استبدال $this->user['id'] بيها
     * في مواضع العزل الفعلية فقط (وليس كل الاستخدامات - راجع CHANGELOG).
     */
    private function tenantId(): int
    {
        return $this->permissionService->resolveTenantId((int) ($this->user['id'] ?? 0));
    }

    /** GET /crm */
    public function index(array $params = []): array
    {
        $body = $this->renderView('crm/index', ['crmActive' => 'overview']);

        $script = '<script src="' . asset_v('/assets/js/crm/index.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.page.title'), $this->tr('crm.page.subtitle'), $body, $script);
        exit;
    }

    /** GET /api/crm/overview */
    public function overview(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $userId = (int) $this->user['id'];

            $websites = $this->db->query(
                "SELECT w.id, w.brand_name, w.domain, w.created_at,
                        COUNT(r.id) as review_count, ROUND(AVG(r.rating), 1) as avg_rating
                 FROM websites w
                 LEFT JOIN reviews r ON r.website_id = w.id
                 WHERE w.user_id = ? AND w.is_active = 1
                 GROUP BY w.id
                 ORDER BY w.created_at DESC",
                [$userId]
            );

            $totalReviews = array_sum(array_column($websites, 'review_count'));
            $ratings = array_filter(array_column($websites, 'avg_rating'));
            $avgRating = $ratings ? round(array_sum($ratings) / count($ratings), 1) : null;

            return $this->success([
                'total_websites' => count($websites),
                'total_reviews' => $totalReviews,
                'avg_rating' => $avgRating,
                'websites' => $websites,
            ]);
        } catch (Exception $e) {
            Logger::error('CRM overview Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحميل بيانات CRM', 500);
        }
    }

    /** GET /api/crm/leads */
    public function listLeads(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $leads = $this->db->query(
                "SELECT l.id, l.status, l.score, l.last_engagement_at, l.created_at,
                        c.name as contact_name, c.email as contact_email, c.phone as contact_phone
                 FROM crm_leads l
                 JOIN crm_contacts c ON c.id = l.contact_id
                 WHERE c.user_id = ?
                 ORDER BY l.created_at DESC
                 LIMIT 50",
                [$this->tenantId()]
            );
            return $this->success(['leads' => $leads]);
        } catch (Exception $e) {
            Logger::error('listLeads Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحميل العملاء المحتملين', 500);
        }
    }

    /** POST /api/crm/leads */
    public function createLead(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['name' => 'required'])) {
            return $this->error('اسم جهة الاتصال مطلوب', 422);
        }

        try {
            $contact = $this->leadService->createContact($this->tenantId(), [
                'name' => $this->get('name'),
                'email' => $this->get('email'),
                'phone' => $this->get('phone'),
                'source' => $this->get('source', 'manual'),
            ]);
            $lead = $this->leadService->createLead((int) $contact->getAttribute('id'), (int) $this->user['id']);

            return $this->success(['contact' => $contact->toArray(), 'lead' => $lead->toArray()], 'تم الإنشاء', 201);
        } catch (Exception $e) {
            Logger::error('createLead Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنشاء العميل المحتمل', 500);
        }
    }

    /** POST /api/crm/leads/{id}/status */
    public function updateLeadStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['status' => 'required'])) {
            return $this->error('الحالة مطلوبة', 422);
        }

        $allowed = ['new', 'nurturing', 'qualified', 'disqualified', 'converted'];
        if (!in_array($this->get('status'), $allowed, true)) {
            return $this->error('حالة غير صحيحة', 422);
        }

        try {
            $lead = $this->leadService->updateStatus((int) ($params['id'] ?? 0), (string) $this->get('status'), $this->tenantId());
            return $this->success(['lead' => $lead->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            Logger::error('updateLeadStatus Error', ['message' => $e->getMessage()]);
            // إصلاح المرحلة 9: استخدام كود الخطأ الفعلي (404 لو Lead مش
            // موجود/مش ملك الحساب) بدل 500 ثابت دايمًا - يتماشى مع تصحيح
            // ثغرة التحقق من الملكية.
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
            return $this->error($e->getMessage(), $code);
        }
    }

    /** GET /api/crm/pipeline-stages */
    public function listPipelineStages(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            // ملاحظة: Model::where(['agency_id' => null]) كانت هتولّد
            // `agency_id = ?` مع NULL كمعامل، وده تعبير SQL دايمًا كاذب
            // (لازم IS NULL صراحة) - فاستخدمت SQL خام هنا بدل الـ Model.
            $stages = $this->db->query(
                "SELECT * FROM crm_pipeline_stages WHERE agency_id IS NULL ORDER BY sort_order ASC"
            );
            return $this->success(['stages' => $stages]);
        } catch (Exception $e) {
            Logger::error('listPipelineStages Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحميل مراحل المسار', 500);
        }
    }

    /** GET /api/crm/deals */
    public function listDeals(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $deals = $this->db->query(
                "SELECT d.*, s.name as stage_name, s.color as stage_color
                 FROM crm_deals d
                 JOIN crm_pipeline_stages s ON s.id = d.stage_id
                 WHERE d.owner_user_id = ?
                 ORDER BY d.created_at DESC
                 LIMIT 100",
                [$this->tenantId()]
            );
            return $this->success(['deals' => $deals]);
        } catch (Exception $e) {
            Logger::error('listDeals Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحميل الصفقات', 500);
        }
    }

    /** POST /api/crm/deals */
    public function createDeal(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['title' => 'required', 'stage_id' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }

        try {
            $deal = new CrmDeal([
                'owner_user_id' => $this->tenantId(),
                'contact_id' => $this->get('contact_id'),
                'lead_id' => $this->get('lead_id'),
                'stage_id' => (int) $this->get('stage_id'),
                'title' => $this->get('title'),
                'value' => $this->get('value', 0),
                'currency' => $this->get('currency', 'USD'),
            ]);
            $deal->save();

            ActivityLog::record('crm', 'deal.created', [
                'user_id' => $this->user['id'], 'subject_type' => 'crm_deals', 'subject_id' => (int) $deal->getAttribute('id'),
            ]);

            // إضافة المرحلة 3 (بند 12/36): سطر واحد لإطلاق Automation - بدون
            // أي تغيير في منطق إنشاء الصفقة نفسه.
            // تحديث المرحلة 6: tenantId() بدل user['id'] مباشرة عشان قواعد
            // الأتمتة تتطابق مع حساب الـTenant الصحيح لو المُنفّذ عضو فريق.
            (new CrmAutomationService())->trigger('deal.created', $this->tenantId(), [
                'deal_id' => (int) $deal->getAttribute('id'),
            ]);

            return $this->success(['deal' => $deal->toArray()], 'تم إنشاء الصفقة', 201);
        } catch (Exception $e) {
            Logger::error('createDeal Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنشاء الصفقة', 500);
        }
    }

    /** POST /api/crm/deals/{id}/stage - نقل صفقة لمرحلة تانية (كانت الوظيفة دي ناقصة بالكامل - مفيش طريقة كانت موجودة لتحديث صفقة بعد إنشائها) */
    public function updateDealStage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['stage_id' => 'required'])) {
            return $this->error('المرحلة الجديدة مطلوبة', 422);
        }

        try {
            $deal = (new CrmDeal())->find((int) ($params['id'] ?? 0));
            if (!$deal || (int) $deal->getAttribute('owner_user_id') !== $this->tenantId()) {
                return $this->error('الصفقة غير موجودة', 404);
            }

            $stageId = (int) $this->get('stage_id');
            $stageRows = $this->db->query("SELECT * FROM crm_pipeline_stages WHERE id = ? LIMIT 1", [$stageId]);
            if (empty($stageRows)) {
                return $this->error('المرحلة غير موجودة', 404);
            }
            $stage = $stageRows[0];

            $deal->setAttribute('stage_id', $stageId);
            if ((bool) $stage['is_won']) {
                $deal->setAttribute('status', 'won');
                $deal->setAttribute('closed_at', date('Y-m-d H:i:s'));
            } elseif ((bool) $stage['is_lost']) {
                $deal->setAttribute('status', 'lost');
                $deal->setAttribute('closed_at', date('Y-m-d H:i:s'));
            } else {
                $deal->setAttribute('status', 'open');
                $deal->setAttribute('closed_at', null);
            }
            $deal->save();

            // ربط جديد: لو الصفقة اتقفلت "مكسوبة" وعميلنا مفعّل خيار
            // "ربط تلقائي مع CRM" في إعدادات حملة طلب المراجعات، ننشئ
            // طلب مراجعة تلقائي للـ contact بتاع الصفقة - من غير ما
            // يوقف نقل الصفقة نفسها لو فشل لأي سبب (رقم غلط، مفيش موقع...).
            if ((bool) $stage['is_won'] && class_exists('ReviewRequestService')) {
                try {
                    $contact = (new CrmContact())->find((int) $deal->getAttribute('contact_id'));
                    if ($contact) {
                        (new ReviewRequestService())->maybeCreateFromCrmDeal(
                            $this->tenantId(),
                            (string) $contact->getAttribute('name'),
                            $contact->getAttribute('phone')
                        );
                    }
                } catch (Exception $e) {
                    Logger::warning('CRM auto review-request skipped', ['deal_id' => $deal->getAttribute('id'), 'message' => $e->getMessage()]);
                }
            }

            ActivityLog::record('crm', 'deal.stage_changed', [
                'user_id' => $this->user['id'], 'subject_type' => 'crm_deals', 'subject_id' => $deal->getAttribute('id'),
            ]);

            // إضافة المرحلة 3 (بند 12/36): سطر واحد لإطلاق Automation - نفس
            // نمط تكامل ReviewRequestService فوق بالظبط (استدعاء إضافي بعد
            // نجاح النقل، بدون ما يمنع نقل الصفقة نفسها لو فشل لأي سبب).
            // 'deal.won'/'deal.lost' يغطيان مثال الطلب الأصلي حرفيًا: "WHEN:
            // Deal becomes Won THEN: Create Customer, Create Onboarding Task,
            // Notify Team".
            try {
                $automationEvent = 'deal.stage_changed';
                if ((bool) $stage['is_won']) {
                    $automationEvent = 'deal.won';
                } elseif ((bool) $stage['is_lost']) {
                    $automationEvent = 'deal.lost';
                }
                (new CrmAutomationService())->trigger($automationEvent, $this->tenantId(), [
                    'deal_id' => (int) $deal->getAttribute('id'), 'stage_id' => $stageId,
                ]);
            } catch (Exception $e) {
                Logger::warning('CRM automation trigger skipped', ['deal_id' => $deal->getAttribute('id'), 'message' => $e->getMessage()]);
            }

            return $this->success(['deal' => $deal->toArray()], 'تم النقل');
        } catch (Exception $e) {
            Logger::error('updateDealStage Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر نقل الصفقة', 500);
        }
    }

    /** GET /crm/leads */
    public function showLeads(array $params = []): array
    {
        $body = $this->renderView('crm/leads', ['crmActive' => 'leads']);

        $script = '<script src="' . asset_v('/assets/js/crm/leads.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.leads.title'), $this->tr('crm.leads.subtitle'), $body, $script);
        exit;
    }

    /** GET /crm/deals */
    public function showDeals(array $params = []): array
    {
        $body = $this->renderView('crm/deals', ['crmActive' => 'deals']);

        $script = '<script src="' . asset_v('/assets/js/crm/deals.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.deals.title'), $this->tr('crm.deals.subtitle'), $body, $script);
        exit;
    }

    // ================================================================
    // الصفحات التالية أُضيفت كجزء من موديول AI CRM (contacts/companies/
    // tasks/appointments/reports) - نفس نمط showLeads/showDeals أعلاه
    // بالضبط، وتعتمد على نقاط الـAPI في CrmApiController الجديد.
    // ================================================================

    /** GET /crm/contacts */
    public function showContacts(array $params = []): array
    {
        $body = $this->renderView('crm/contacts', ['crmActive' => 'contacts']);

        $script = '<script src="' . asset_v('/assets/js/crm/contacts.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.contacts.title'), $this->tr('crm.contacts.subtitle'), $body, $script);
        exit;
    }

    /** GET /crm/contacts/{id} - Customer 360 (بند 2) */
    public function showContactProfile(array $params = []): array
    {
        $contactId = (int) ($params['id'] ?? 0);
        $body = $this->renderView('crm/contact_profile', ['crmActive' => 'contacts_profile', 'contactId' => $contactId]);

        $script = '<script src="' . asset_v('/assets/js/crm/contact_profile.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.contacts.profile_title'), $this->tr('crm.contacts.subtitle'), $body, $script);
        exit;
    }

    /** GET /crm/companies */
    public function showCompanies(array $params = []): array
    {
        $body = $this->renderView('crm/companies', ['crmActive' => 'companies']);

        $script = '<script src="' . asset_v('/assets/js/crm/companies.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.companies.title'), $this->tr('crm.companies.subtitle'), $body, $script);
        exit;
    }

    /** GET /crm/tasks */
    public function showTasks(array $params = []): array
    {
        $body = $this->renderView('crm/tasks', ['crmActive' => 'tasks']);

        $script = '<script src="' . asset_v('/assets/js/crm/tasks.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.tasks.title'), $this->tr('crm.tasks.subtitle'), $body, $script);
        exit;
    }

    /** GET /crm/appointments */
    public function showAppointments(array $params = []): array
    {
        $body = $this->renderView('crm/appointments', ['crmActive' => 'appointments']);

        $script = '<script src="' . asset_v('/assets/js/crm/appointments.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.appointments.title'), $this->tr('crm.appointments.subtitle'), $body, $script);
        exit;
    }

    /** GET /crm/reports (بند 23، 24) */
    public function showReports(array $params = []): array
    {
        $body = $this->renderView('crm/reports', ['crmActive' => 'reports']);

        $script = '<script src="' . asset_v('/assets/js/crm/reports.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.reports.title'), $this->tr('crm.reports.subtitle'), $body, $script);
        exit;
    }

    /** GET /crm/automation (بند 12، 36) */
    /** GET /crm/automation (بند 12، 36) - Visual Builder حقيقي بدل القوالب الجاهزة فقط */
    public function showAutomation(array $params = []): array
    {
        $body = $this->renderView('crm/automation', ['crmActive' => 'automation']);

        $script = '<script src="' . asset_v('/assets/js/crm/automation.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.automation.title'), $this->tr('crm.automation.subtitle'), $body, $script);
        exit;
    }

    /** GET /crm/team (بند 30) */
    public function showTeam(array $params = []): array
    {
        $body = $this->renderView('crm/team', ['crmActive' => 'team']);

        $script = '<script src="' . asset_v('/assets/js/crm/team.js') . '"></script>';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.team.title'), $this->tr('crm.team.subtitle'), $body, $script);
        exit;
    }
}
