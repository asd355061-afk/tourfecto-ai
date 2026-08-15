<?php
/**
 * Tourfecto - CRM API Controller (إضافي)
 * @version 1.0.0
 *
 * كل نقاط الـAPI الجديدة لموديول AI CRM التي لم تكن موجودة إطلاقًا في
 * CrmController الأصلي (Contacts, Companies, Tasks, Notes, Appointments,
 * Customer 360, Dashboard/Reports, Global Search, Multiple Pipelines,
 * Import/Export). CrmController.php الأصلي لم يُلمس أو يُعدَّل بأي شكل -
 * هذا Controller منفصل تمامًا للحفاظ على "لا تعمل Refactor شامل" (بند 40)
 * وللفصل الواضح بين الخدمات (بند 35).
 *
 * كل Endpoint هنا مربوط بحساب المستخدم الحالي فقط (Tenant Isolation - بند 31):
 * إما عبر user_id مباشرة أو owner_user_id، بنفس نمط CrmController الأصلي.
 */
class CrmApiController extends Controller {
    private $contactService;
    private $companyService;
    private $leadService;
    private $dealService;
    private $taskService;
    private $noteService;
    private $appointmentService;
    private $customer360Service;
    private $dashboardService;
    private $searchService;
    private $importExportService;
    private $permissionService;
    private $teamService;

    public function __construct() {
        parent::__construct();
        $this->contactService = new CrmContactService();
        $this->companyService = new CrmCompanyService();
        $this->leadService = new CrmLeadService();
        $this->dealService = new CrmDealService();
        $this->taskService = new CrmTaskService();
        $this->noteService = new CrmNoteService();
        $this->appointmentService = new CrmAppointmentService();
        $this->customer360Service = new CrmCustomer360Service();
        $this->dashboardService = new CrmDashboardService();
        $this->searchService = new CrmSearchService();
        $this->importExportService = new CrmImportExportService();
        $this->permissionService = new CrmPermissionService();
        $this->teamService = new CrmTeamService();
    }

    /** المستخدم المسجّل دخوله فعليًا (Actor الحقيقي - يُستخدم لحقول "مين اللي عمل ده") */
    private function uid(): int {
        return (int) ($this->user['id'] ?? 0);
    }

    /**
     * الحساب (Tenant) الفعلي اللي بيانات CRM تخصّه - نفس uid() لو المستخدم
     * صاحب الحساب، أو حساب صاحب الفريق لو المستخدم عضو مُضاف (بند 30، 31).
     * يُستخدم في كل استعلامات القراءة/العزل بدل uid() مباشرة، لكل الموديولات
     * المبنية بالكامل في هذا الملف (Contacts/Companies/Tasks/Notes/
     * Appointments/Automation/Communication...) - Leads/Deals لسه بتستخدم
     * uid() القديم مباشرة لأنها بتُدار من CrmController الأصلي غير المعدَّل
     * (راجع تعليق CrmPermissionService للتفصيل الكامل).
     */
    private function tenantId(): int {
        return $this->permissionService->resolveTenantId($this->uid());
    }

    /** بوابة صلاحية بسيطة - تُستخدم في بداية أي Endpoint حساس (Delete/Export/Manage Settings) */
    private function requirePermission(string $permission) {
        if (!$this->permissionService->can($this->uid(), $permission)) {
            return $this->error('ليس لديك صلاحية كافية لتنفيذ هذا الإجراء (' . $permission . ')', 403);
        }
        return null;
    }

    private function handleException(Exception $e, string $logLabel) {
        $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
        Logger::error($logLabel . ' Error', ['message' => $e->getMessage()]);
        return $this->error($e->getMessage() ?: 'حدث خطأ غير متوقع', $code);
    }

    // ============================================================
    // Companies (بند 1، 2)
    // ============================================================

    /** GET /api/crm/companies */
    public function listCompanies(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $companies = $this->companyService->listForUser($this->tenantId());
            return $this->success(['companies' => array_map(fn($c) => $c->toArray(), $companies)]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listCompanies');
        }
    }

    /** GET /api/crm/companies/search - Filters + Pagination (بند 29، 37) */
    public function searchCompanies(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $filters = array_filter([
                'industry' => $this->get('industry'), 'country' => $this->get('country'), 'search' => $this->get('search'),
            ], fn($v) => $v !== null && $v !== '');
            return $this->success($this->companyService->search($this->tenantId(), $filters, (int) $this->get('page', 1), (int) $this->get('per_page', 25)));
        } catch (Exception $e) {
            return $this->handleException($e, 'searchCompanies');
        }
    }

    /** POST /api/crm/companies */
    public function createCompany(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('create')) return $denied;
        if (!$this->validate(['name' => 'required'])) return $this->error('اسم الشركة مطلوب', 422);
        try {
            $company = $this->companyService->create($this->tenantId(), $this->data);
            return $this->success(['company' => $company->toArray()], 'تم إنشاء الشركة', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createCompany');
        }
    }

    /** PUT /api/crm/companies/{id} */
    public function updateCompany(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('edit')) return $denied;
        try {
            $company = $this->companyService->update($this->tenantId(), (int) ($params['id'] ?? 0), $this->data);
            return $this->success(['company' => $company->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateCompany');
        }
    }

    // ============================================================
    // Contacts (بند 1، 2، 21، 22)
    // ============================================================

    /** GET /api/crm/contacts */
    public function listContacts(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $contacts = $this->contactService->listForUser($this->tenantId());
            return $this->success(['contacts' => array_map(fn($c) => $c->toArray(), $contacts)]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listContacts');
        }
    }

    /** GET /api/crm/contacts/search - نسخة بـFilters + Pagination حقيقي (بند 29، 37) */
    public function searchContacts(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $filters = [
                'status' => $this->get('status'), 'source' => $this->get('source'),
                'country' => $this->get('country'), 'company_id' => $this->get('company_id'),
                'tag' => $this->get('tag'), 'search' => $this->get('search'),
                'created_from' => $this->get('created_from'), 'created_to' => $this->get('created_to'),
                'last_activity_before_days' => $this->get('last_activity_before_days'),
                'min_lead_score' => $this->get('min_lead_score'), 'max_lead_score' => $this->get('max_lead_score'),
                'has_open_deal' => $this->get('has_open_deal'), 'min_deal_value' => $this->get('min_deal_value'),
            ];
            $filters = array_filter($filters, fn($v) => $v !== null && $v !== '');
            $result = $this->contactService->search(
                $this->tenantId(), $filters, (int) $this->get('page', 1), (int) $this->get('per_page', 25)
            );
            return $this->success($result);
        } catch (Exception $e) {
            return $this->handleException($e, 'searchContacts');
        }
    }

    /** GET /api/crm/segments (بند 19) */
    public function listSegments(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            return $this->success(['segments' => (new CrmSegmentService())->listForUser($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listSegments');
        }
    }

    /** POST /api/crm/segments {name, filters} */
    public function createSegment(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('manage_settings')) return $denied;
        if (!$this->validate(['name' => 'required', 'filters' => 'required'])) return $this->error('بيانات ناقصة', 422);
        try {
            $filters = $this->get('filters');
            $filters = is_array($filters) ? $filters : (json_decode((string) $filters, true) ?: []);
            $segment = (new CrmSegmentService())->create($this->tenantId(), (string) $this->get('name'), $filters);
            return $this->success(['segment' => $segment->toArray()], 'تم إنشاء القطاع', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createSegment');
        }
    }

    /** DELETE /api/crm/segments/{id} */
    public function deleteSegment(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('manage_settings')) return $denied;
        try {
            (new CrmSegmentService())->delete($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تم الحذف');
        } catch (Exception $e) {
            return $this->handleException($e, 'deleteSegment');
        }
    }

    /** GET /api/crm/segments/{id}/run */
    public function runSegment(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $result = (new CrmSegmentService())->run(
                $this->tenantId(), (int) ($params['id'] ?? 0), (int) $this->get('page', 1), (int) $this->get('per_page', 25)
            );
            return $this->success($result);
        } catch (Exception $e) {
            return $this->handleException($e, 'runSegment');
        }
    }

    /** GET /api/crm/contacts/{id} */
    public function getContact(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $contact = $this->contactService->findOwned($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success(['contact' => $contact->toArray()]);
        } catch (Exception $e) {
            return $this->handleException($e, 'getContact');
        }
    }

    /** POST /api/crm/contacts */
    public function createContact(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('create')) return $denied;
        if (!$this->validate(['name' => 'required'])) return $this->error('اسم جهة الاتصال مطلوب', 422);
        try {
            $contact = $this->contactService->create($this->tenantId(), $this->data);
            return $this->success(['contact' => $contact->toArray()], 'تم الإنشاء', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createContact');
        }
    }

    /** PUT /api/crm/contacts/{id} */
    public function updateContact(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('edit')) return $denied;
        try {
            $contact = $this->contactService->update($this->tenantId(), (int) ($params['id'] ?? 0), $this->data);
            return $this->success(['contact' => $contact->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateContact');
        }
    }

    /** GET /api/crm/contacts/{id}/duplicates */
    public function contactDuplicates(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $contact = $this->contactService->findOwned($this->tenantId(), (int) ($params['id'] ?? 0));
            $dupes = $this->contactService->duplicateCandidates(
                $this->tenantId(), $contact->getAttribute('email'), $contact->getAttribute('phone'), (int) $params['id']
            );
            return $this->success(['duplicates' => $dupes]);
        } catch (Exception $e) {
            return $this->handleException($e, 'contactDuplicates');
        }
    }

    /** POST /api/crm/contacts/merge {primary_id, duplicate_id} */
    public function mergeContacts(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('delete')) return $denied;
        if (!$this->validate(['primary_id' => 'required', 'duplicate_id' => 'required'])) return $this->error('بيانات ناقصة', 422);
        try {
            $contact = $this->contactService->merge($this->tenantId(), (int) $this->get('primary_id'), (int) $this->get('duplicate_id'));
            return $this->success(['contact' => $contact->toArray()], 'تم الدمج');
        } catch (Exception $e) {
            return $this->handleException($e, 'mergeContacts');
        }
    }

    // ============================================================
    // Customer 360 (بند 2)
    // ============================================================

    /** GET /api/crm/contacts/{id}/360 */
    public function customer360(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $profile = $this->customer360Service->build($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success($profile);
        } catch (Exception $e) {
            return $this->handleException($e, 'customer360');
        }
    }

    // ============================================================
    // Leads - عمليات إضافية (assign/convert/archive) فوق CrmController الحالي
    // ============================================================

    /** GET /api/crm/leads/search - Filters + Pagination (بند 29، 37) */
    public function searchLeads(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $filters = array_filter([
                'status' => $this->get('status'), 'source' => $this->get('source'),
                'priority' => $this->get('priority'), 'owner_user_id' => $this->get('owner_user_id'),
                'min_score' => $this->get('min_score'), 'search' => $this->get('search'),
            ], fn($v) => $v !== null && $v !== '');
            return $this->success($this->leadService->search($this->tenantId(), $filters, (int) $this->get('page', 1), (int) $this->get('per_page', 25)));
        } catch (Exception $e) {
            return $this->handleException($e, 'searchLeads');
        }
    }

    /** POST /api/crm/leads/{id}/assign {owner_user_id} */
    public function assignLead(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('assign')) return $denied;
        if (!$this->validate(['owner_user_id' => 'required'])) return $this->error('المسؤول مطلوب', 422);
        try {
            $lead = $this->leadService->assignOwner((int) ($params['id'] ?? 0), (int) $this->get('owner_user_id'), $this->tenantId());
            return $this->success(['lead' => $lead->toArray()], 'تم التعيين');
        } catch (Exception $e) {
            return $this->handleException($e, 'assignLead');
        }
    }

    /** POST /api/crm/leads/{id}/convert - تحويل لصفقة */
    public function convertLead(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $deal = $this->leadService->convertToDeal(
                (int) ($params['id'] ?? 0), $this->tenantId(), $this->get('stage_id') ? (int) $this->get('stage_id') : null,
                $this->get('value') !== null ? (float) $this->get('value') : null
            );
            return $this->success(['deal' => $deal->toArray()], 'تم التحويل لصفقة', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'convertLead');
        }
    }

    /** POST /api/crm/leads/{id}/archive */
    public function archiveLead(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('delete')) return $denied;
        try {
            $lead = $this->leadService->archive((int) ($params['id'] ?? 0), $this->tenantId());
            return $this->success(['lead' => $lead->toArray()], 'تمت الأرشفة');
        } catch (Exception $e) {
            return $this->handleException($e, 'archiveLead');
        }
    }

    // ============================================================
    // Deals - عمليات إضافية (update/delete/at-risk) فوق CrmController الحالي
    // ============================================================

    /** GET /api/crm/deals/search - Filters + Pagination (بند 29، 37) */
    public function searchDeals(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $filters = array_filter([
                'status' => $this->get('status'), 'stage_id' => $this->get('stage_id'),
                'pipeline_id' => $this->get('pipeline_id'), 'min_value' => $this->get('min_value'),
                'max_value' => $this->get('max_value'), 'search' => $this->get('search'),
            ], fn($v) => $v !== null && $v !== '');
            return $this->success($this->dealService->search($this->tenantId(), $filters, (int) $this->get('page', 1), (int) $this->get('per_page', 25)));
        } catch (Exception $e) {
            return $this->handleException($e, 'searchDeals');
        }
    }

    /** PUT /api/crm/deals/{id} */
    public function updateDeal(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $deal = $this->dealService->update($this->tenantId(), (int) ($params['id'] ?? 0), $this->data);
            return $this->success(['deal' => $deal->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateDeal');
        }
    }

    /** DELETE /api/crm/deals/{id} */
    public function deleteDeal(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $this->dealService->delete($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تم الحذف');
        } catch (Exception $e) {
            return $this->handleException($e, 'deleteDeal');
        }
    }

    /** GET /api/crm/deals/at-risk (بند 26) */
    public function dealsAtRisk(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            return $this->success(['deals' => $this->dealService->atRiskDeals($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'dealsAtRisk');
        }
    }

    // ============================================================
    // Pipelines متعددة (بند 6)
    // ============================================================

    /** GET /api/crm/pipelines */
    public function listPipelines(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $pipelines = (new CrmPipeline())->availableForUser($this->tenantId());
            return $this->success(['pipelines' => $pipelines]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listPipelines');
        }
    }

    /** POST /api/crm/pipelines {name} */
    public function createPipeline(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!$this->validate(['name' => 'required'])) return $this->error('اسم المسار مطلوب', 422);
        try {
            $pipeline = new CrmPipeline([
                'user_id' => $this->tenantId(),
                'name' => $this->get('name'),
                'pipeline_key' => strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $this->get('name'))) . '_' . time(),
                'is_default' => 0,
                'sort_order' => 0,
            ]);
            $pipeline->save();
            return $this->success(['pipeline' => $pipeline->toArray()], 'تم إنشاء المسار', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createPipeline');
        }
    }

    /** GET /api/crm/pipelines/{id}/stages */
    public function pipelineStages(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $stages = (new CrmPipelineStage())->forPipeline((int) ($params['id'] ?? 0));
            return $this->success(['stages' => $stages]);
        } catch (Exception $e) {
            return $this->handleException($e, 'pipelineStages');
        }
    }

    /** POST /api/crm/pipelines/{id}/stages - مرحلة مخصصة جديدة (بند 6: Admin يقدر ينشئ مراحل مخصصة) */
    public function createPipelineStage(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!$this->validate(['name' => 'required'])) return $this->error('اسم المرحلة مطلوب', 422);
        try {
            $stage = new CrmPipelineStage([
                'pipeline_id' => (int) ($params['id'] ?? 0),
                'name' => $this->get('name'),
                'slug' => strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $this->get('name'))),
                'sort_order' => (int) $this->get('sort_order', 0),
                'win_probability' => (int) $this->get('win_probability', 0),
                'is_won' => $this->get('is_won') ? 1 : 0,
                'is_lost' => $this->get('is_lost') ? 1 : 0,
                'color' => $this->get('color', '#6366f1'),
            ]);
            $stage->save();
            return $this->success(['stage' => $stage->toArray()], 'تمت الإضافة', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createPipelineStage');
        }
    }

    // ============================================================
    // Tasks / Follow-ups (بند 11، 13)
    // ============================================================

    /** GET /api/crm/tasks */
    public function listTasks(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $tasks = $this->taskService->listForUser($this->tenantId());
            return $this->success(['tasks' => array_map(fn($t) => $t->toArray(), $tasks)]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listTasks');
        }
    }

    /** GET /api/crm/tasks/search - Filters + Pagination (بند 29، 37) */
    public function searchTasks(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $filters = array_filter([
                'status' => $this->get('status'), 'priority' => $this->get('priority'),
                'related_type' => $this->get('related_type'), 'due_before' => $this->get('due_before'),
                'due_after' => $this->get('due_after'), 'search' => $this->get('search'),
            ], fn($v) => $v !== null && $v !== '');
            return $this->success($this->taskService->search($this->tenantId(), $filters, (int) $this->get('page', 1), (int) $this->get('per_page', 25)));
        } catch (Exception $e) {
            return $this->handleException($e, 'searchTasks');
        }
    }

    /** GET /api/crm/tasks/overdue */
    public function overdueTasks(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            return $this->success(['tasks' => $this->taskService->overdue($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'overdueTasks');
        }
    }

    /** POST /api/crm/tasks */
    public function createTask(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('create')) return $denied;
        if (!$this->validate(['title' => 'required'])) return $this->error('عنوان المهمة مطلوب', 422);
        try {
            $task = $this->taskService->create($this->tenantId(), $this->data, $this->uid());
            return $this->success(['task' => $task->toArray()], 'تم إنشاء المهمة', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createTask');
        }
    }

    /** POST /api/crm/tasks/{id}/status {status} */
    public function updateTaskStatus(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('edit')) return $denied;
        if (!$this->validate(['status' => 'required'])) return $this->error('الحالة مطلوبة', 422);
        try {
            $task = $this->taskService->updateStatus($this->tenantId(), (int) ($params['id'] ?? 0), (string) $this->get('status'));
            return $this->success(['task' => $task->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateTaskStatus');
        }
    }

    // ============================================================
    // Notes (بند 1)
    // ============================================================

    /** POST /api/crm/notes */
    public function createNote(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('create')) return $denied;
        if (!$this->validate(['body' => 'required'])) return $this->error('نص الملاحظة مطلوب', 422);
        try {
            $note = $this->noteService->create($this->tenantId(), $this->data, $this->uid());
            return $this->success(['note' => $note->toArray()], 'تمت الإضافة', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createNote');
        }
    }

    // ============================================================
    // Appointments (بند 18)
    // ============================================================

    /** GET /api/crm/appointments */
    public function listAppointments(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $meetings = $this->appointmentService->listForUser($this->tenantId());
            return $this->success(['appointments' => array_map(fn($m) => $m->toArray(), $meetings)]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listAppointments');
        }
    }

    /** GET /api/crm/appointments/search - Filters + Pagination (بند 29، 37) */
    public function searchAppointments(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $filters = array_filter([
                'status' => $this->get('status'), 'from' => $this->get('from'),
                'to' => $this->get('to'), 'search' => $this->get('search'),
            ], fn($v) => $v !== null && $v !== '');
            return $this->success($this->appointmentService->search($this->tenantId(), $filters, (int) $this->get('page', 1), (int) $this->get('per_page', 25)));
        } catch (Exception $e) {
            return $this->handleException($e, 'searchAppointments');
        }
    }

    /** POST /api/crm/appointments */
    public function createAppointment(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('create')) return $denied;
        if (!$this->validate(['title' => 'required', 'starts_at' => 'required'])) return $this->error('بيانات ناقصة', 422);
        try {
            $meeting = $this->appointmentService->create($this->tenantId(), $this->data, $this->uid());
            return $this->success(['appointment' => $meeting->toArray()], 'تم حجز الموعد', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createAppointment');
        }
    }

    /** POST /api/crm/appointments/{id}/status {status} */
    public function updateAppointmentStatus(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('edit')) return $denied;
        if (!$this->validate(['status' => 'required'])) return $this->error('الحالة مطلوبة', 422);
        try {
            $meeting = $this->appointmentService->updateStatus($this->tenantId(), (int) ($params['id'] ?? 0), (string) $this->get('status'));
            return $this->success(['appointment' => $meeting->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateAppointmentStatus');
        }
    }

    // ============================================================
    // Dashboard / Reports (بند 23، 24)
    // ============================================================

    /** GET /api/crm/dashboard/stats */
    public function dashboardStats(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            return $this->success($this->dashboardService->stats($this->tenantId()));
        } catch (Exception $e) {
            return $this->handleException($e, 'dashboardStats');
        }
    }

    // ============================================================
    // Global Search (بند 28)
    // ============================================================

    /** GET /api/crm/search?q= */
    public function globalSearch(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $q = trim((string) $this->get('q', ''));
        if (strlen($q) < 2) return $this->error('أدخل حرفين على الأقل للبحث', 422);
        try {
            return $this->success($this->searchService->search($this->tenantId(), $q));
        } catch (Exception $e) {
            return $this->handleException($e, 'globalSearch');
        }
    }

    // ============================================================
    // Lead Sources قابلة للتخصيص (بند 4)
    // ============================================================

    /** GET /api/crm/lead-sources */
    public function listLeadSources(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $sources = (new CrmLeadSource())->availableForUser($this->tenantId());
            return $this->success(['sources' => $sources]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listLeadSources');
        }
    }

    /** POST /api/crm/lead-sources {name} */
    public function createLeadSource(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!$this->validate(['name' => 'required'])) return $this->error('اسم المصدر مطلوب', 422);
        try {
            $source = new CrmLeadSource([
                'user_id' => $this->tenantId(),
                'name' => $this->get('name'),
                'source_key' => strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $this->get('name'))),
                'is_active' => 1,
            ]);
            $source->save();
            return $this->success(['source' => $source->toArray()], 'تمت الإضافة', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createLeadSource');
        }
    }

    // ============================================================
    // Import / Export (بند 20، 21)
    // ============================================================

    /** POST /api/crm/contacts/import/preview {csv_content, field_mapping} */
    public function importPreview(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('create')) return $denied;
        if (!$this->validate(['csv_content' => 'required', 'field_mapping' => 'required'])) return $this->error('بيانات ناقصة', 422);
        try {
            $mapping = $this->get('field_mapping');
            $mapping = is_array($mapping) ? $mapping : json_decode((string) $mapping, true);
            $preview = $this->importExportService->preview($this->tenantId(), (string) $this->get('csv_content'), $mapping ?? []);
            return $this->success($preview);
        } catch (Exception $e) {
            return $this->handleException($e, 'importPreview');
        }
    }

    /** POST /api/crm/contacts/import/commit {rows: [...], skip_duplicates} */
    public function importCommit(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('create')) return $denied;
        if (!$this->validate(['rows' => 'required'])) return $this->error('لا توجد صفوف للاستيراد', 422);
        try {
            $rows = $this->get('rows');
            $rows = is_array($rows) ? $rows : json_decode((string) $rows, true);
            $result = $this->importExportService->commit($this->tenantId(), $rows ?? [], $this->get('skip_duplicates', true) ? true : false);
            return $this->success($result, 'تم الاستيراد');
        } catch (Exception $e) {
            return $this->handleException($e, 'importCommit');
        }
    }

    /**
     * POST /api/crm/contacts/import/commit-async {rows, skip_duplicates} (بند 37)
     * بديل Background للاستيراد الكبير - يرجّع batch_id فورًا بدل ما ينتظر
     * المستخدم لحد ما الاستيراد يخلص بالكامل.
     */
    public function importCommitAsync(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('create')) return $denied;
        if (!$this->validate(['rows' => 'required'])) return $this->error('لا توجد صفوف للاستيراد', 422);
        try {
            $rows = $this->get('rows');
            $rows = is_array($rows) ? $rows : json_decode((string) $rows, true);
            $batch = $this->importExportService->commitAsync($this->tenantId(), $rows ?? [], $this->get('skip_duplicates', true) ? true : false);
            return $this->success(['batch' => $batch->toArray()], 'بدأ الاستيراد في الخلفية', 202);
        } catch (Exception $e) {
            return $this->handleException($e, 'importCommitAsync');
        }
    }

    /** GET /api/crm/contacts/import/status/{id} (بند 37) */
    public function importBatchStatus(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $batch = $this->importExportService->importBatchStatus($this->tenantId(), (int) ($params['id'] ?? 0));
            if (!$batch) return $this->error('الدفعة غير موجودة', 404);
            return $this->success(['batch' => $batch->toArray()]);
        } catch (Exception $e) {
            return $this->handleException($e, 'importBatchStatus');
        }
    }

    /** GET /api/crm/contacts/export */
    public function exportContacts(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('export')) return $denied;
        try {
            $csv = $this->importExportService->exportContactsCsv($this->tenantId());
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="crm_contacts.csv"');
            echo "\xEF\xBB\xBF" . $csv; // BOM لدعم العربي في Excel
            exit;
        } catch (Exception $e) {
            Logger::error('exportContacts Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التصدير', 500);
        }
    }

    /** GET /api/crm/deals/export (بند 20 - استكمال المرحلة 9) */
    public function exportDeals(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('export')) return $denied;
        try {
            $csv = $this->importExportService->exportDealsCsv($this->tenantId());
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="crm_deals.csv"');
            echo "\xEF\xBB\xBF" . $csv;
            exit;
        } catch (Exception $e) {
            Logger::error('exportDeals Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التصدير', 500);
        }
    }

    /** GET /api/crm/tasks/export (بند 20) */
    public function exportTasks(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('export')) return $denied;
        try {
            $csv = $this->importExportService->exportTasksCsv($this->tenantId());
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="crm_tasks.csv"');
            echo "\xEF\xBB\xBF" . $csv;
            exit;
        } catch (Exception $e) {
            Logger::error('exportTasks Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التصدير', 500);
        }
    }

    /** GET /api/crm/leads/export (بند 20) */
    public function exportLeads(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('export')) return $denied;
        try {
            $csv = $this->importExportService->exportLeadsCsv($this->tenantId());
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="crm_leads.csv"');
            echo "\xEF\xBB\xBF" . $csv;
            exit;
        } catch (Exception $e) {
            Logger::error('exportLeads Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التصدير', 500);
        }
    }

    // ============================================================
    // AI CRM - المرحلة 2 (بند 8، 9، 10، 25، 26، 27)
    // كل الخدمات هنا Rule-based شفافة فيما عدا assistantAsk/contactAiSummary
    // اللي بتستخدم GeminiClient الموحّد فقط لصياغة نص من بيانات حقيقية
    // (راجع تعليقات كل Service للتفاصيل الكاملة).
    // ============================================================

    /** POST /api/crm/leads/{id}/score - يحسب ويحفظ AI Lead Score (بند 8) */
    public function scoreLead(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $lead = (new CrmLeadScoringService())->scoreLead((int) ($params['id'] ?? 0));
            return $this->success(['lead' => $lead->toArray()], 'تم حساب التقييم');
        } catch (Exception $e) {
            return $this->handleException($e, 'scoreLead');
        }
    }

    /** GET /api/crm/leads/{id}/next-best-action (بند 10) */
    public function leadNextBestAction(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            return $this->success((new CrmNextBestActionService())->forLead((int) ($params['id'] ?? 0)));
        } catch (Exception $e) {
            return $this->handleException($e, 'leadNextBestAction');
        }
    }

    /** GET /api/crm/deals/{id}/next-best-action (بند 10) */
    public function dealNextBestAction(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            return $this->success((new CrmNextBestActionService())->forDeal((int) ($params['id'] ?? 0), $this->tenantId()));
        } catch (Exception $e) {
            return $this->handleException($e, 'dealNextBestAction');
        }
    }

    /** GET /api/crm/forecast (بند 25، 26) */
    public function forecast(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            return $this->success((new CrmForecastService())->forecast($this->tenantId()));
        } catch (Exception $e) {
            return $this->handleException($e, 'forecast');
        }
    }

    /** POST /api/crm/assistant/ask {question} (بند 9) - يستهلك AI Credits (استدعاء GeminiClient فعلي) */
    public function assistantAsk(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (!$this->validate(['question' => 'required'])) return $this->error('السؤال مطلوب', 422);
        try {
            $result = (new CrmAiAssistantService())->ask($this->tenantId(), (string) $this->get('question'));
            return $this->success($result);
        } catch (Exception $e) {
            return $this->handleException($e, 'assistantAsk');
        }
    }

    /** GET /api/crm/contacts/{id}/ai-summary (بند 27) - يستهلك AI Credits */
    public function contactAiSummary(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $result = (new CrmAiSummaryService())->summarizeContact($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success($result);
        } catch (Exception $e) {
            return $this->handleException($e, 'contactAiSummary');
        }
    }

    // ============================================================
    // AI CRM - المرحلة 3 (بند 12، 15، 16، 17، 36)
    // Automation Workflows + WhatsApp/Email Communication
    // ============================================================

    /** GET /api/crm/automation/rules */
    public function listAutomationRules(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $rules = (new CrmAutomationService())->listForUser($this->tenantId());
            return $this->success(['rules' => array_map(fn($r) => $r->toArray(), $rules)]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listAutomationRules');
        }
    }

    /** GET /api/crm/automation/templates - أمثلة جاهزة مطابقة للطلب الأصلي (بند 12) */
    public function automationTemplates(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        return $this->success(['templates' => CrmAutomationService::TEMPLATES]);
    }

    /** GET /api/crm/automation/schema - مصدر الحقيقة لأدوات الـVisual Builder (بند 12) */
    public function automationSchema(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        return $this->success(CrmAutomationService::SCHEMA);
    }

    /** POST /api/crm/automation/rules {name, trigger_event, conditions?, actions} */
    public function createAutomationRule(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('manage_settings')) return $denied;
        if (!$this->validate(['name' => 'required', 'trigger_event' => 'required', 'actions' => 'required'])) {
            return $this->error('بيانات القاعدة ناقصة', 422);
        }
        try {
            $rule = (new CrmAutomationService())->create($this->tenantId(), $this->data);
            return $this->success(['rule' => $rule->toArray()], 'تم إنشاء القاعدة', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createAutomationRule');
        }
    }

    /** PUT /api/crm/automation/rules/{id} {name, trigger_event, conditions?, actions} */
    public function updateAutomationRule(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('manage_settings')) return $denied;
        if (!$this->validate(['name' => 'required', 'trigger_event' => 'required', 'actions' => 'required'])) {
            return $this->error('بيانات القاعدة ناقصة', 422);
        }
        try {
            $rule = (new CrmAutomationService())->update($this->tenantId(), (int) ($params['id'] ?? 0), $this->data);
            return $this->success(['rule' => $rule->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateAutomationRule');
        }
    }

    /** POST /api/crm/automation/rules/from-template {template_key} */
    public function createAutomationRuleFromTemplate(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('manage_settings')) return $denied;
        if (!$this->validate(['template_key' => 'required'])) return $this->error('حدّد القالب', 422);
        try {
            $rule = (new CrmAutomationService())->createFromTemplate($this->tenantId(), (string) $this->get('template_key'));
            return $this->success(['rule' => $rule->toArray()], 'تم إنشاء القاعدة من القالب', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createAutomationRuleFromTemplate');
        }
    }

    /** POST /api/crm/automation/rules/{id}/toggle */
    public function toggleAutomationRule(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('manage_settings')) return $denied;
        try {
            $rule = (new CrmAutomationService())->toggle($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success(['rule' => $rule->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            return $this->handleException($e, 'toggleAutomationRule');
        }
    }

    /** DELETE /api/crm/automation/rules/{id} */
    public function deleteAutomationRule(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('manage_settings')) return $denied;
        try {
            (new CrmAutomationService())->delete($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تم الحذف');
        } catch (Exception $e) {
            return $this->handleException($e, 'deleteAutomationRule');
        }
    }

    /** GET /api/crm/conversations */
    public function listConversations(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            return $this->success(['conversations' => (new CrmConversation())->allForUser($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listConversations');
        }
    }

    /** GET /api/crm/conversations/{id}/messages */
    public function conversationMessages(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $conversation = (new CrmConversation())->find((int) ($params['id'] ?? 0));
            if (!$conversation || (int) $conversation->getAttribute('user_id') !== $this->tenantId()) {
                return $this->error('المحادثة غير موجودة', 404);
            }
            return $this->success(['messages' => (new CrmMessage())->forConversation((int) $conversation->getAttribute('id'))]);
        } catch (Exception $e) {
            return $this->handleException($e, 'conversationMessages');
        }
    }

    /** POST /api/crm/contacts/{id}/send-whatsapp {text} (بند 16) */
    public function sendWhatsApp(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('create')) return $denied;
        if (!$this->validate(['text' => 'required'])) return $this->error('نص الرسالة مطلوب', 422);
        try {
            $result = (new CrmWhatsAppService())->sendToContact($this->tenantId(), (int) ($params['id'] ?? 0), (string) $this->get('text'));
            return !empty($result['success']) ? $this->success($result, 'تم الإرسال') : $this->error($result['error'] ?? 'فشل الإرسال', 422);
        } catch (Exception $e) {
            return $this->handleException($e, 'sendWhatsApp');
        }
    }

    /** POST /api/crm/contacts/{id}/send-email {subject, body} (بند 17) */
    public function sendEmail(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('create')) return $denied;
        if (!$this->validate(['subject' => 'required', 'body' => 'required'])) return $this->error('بيانات ناقصة', 422);
        try {
            $result = (new CrmEmailService())->sendToContact(
                $this->tenantId(), (int) ($params['id'] ?? 0), (string) $this->get('subject'), (string) $this->get('body')
            );
            return !empty($result['success']) ? $this->success($result, 'تم الإرسال') : $this->error($result['error'] ?? 'فشل الإرسال', 422);
        } catch (Exception $e) {
            return $this->handleException($e, 'sendEmail');
        }
    }

    /** POST /api/crm/contacts/{id}/send-sms {text} (بند 15) */
    public function sendSms(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('create')) return $denied;
        if (!$this->validate(['text' => 'required'])) return $this->error('نص الرسالة مطلوب', 422);
        try {
            $result = (new CrmSmsService())->sendToContact($this->tenantId(), (int) ($params['id'] ?? 0), (string) $this->get('text'));
            return !empty($result['success']) ? $this->success($result, 'تم الإرسال') : $this->error($result['error'] ?? 'فشل الإرسال', 422);
        } catch (Exception $e) {
            return $this->handleException($e, 'sendSms');
        }
    }

    /** GET /api/crm/communication/status - هل واتساب/إيميل/SMS مفعّلين فعليًا؟ */
    public function communicationStatus(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        return $this->success([
            'whatsapp_configured' => (new CrmWhatsAppService())->isConfigured(),
            'email_configured' => (new CrmEmailService())->isConfigured(),
            'sms_configured' => (new CrmSmsService())->isConfigured(),
        ]);
    }

    // ============================================================
    // AI CRM - المرحلة 5 (بند 30) - Team & Roles/Permissions
    // ============================================================

    /** GET /api/crm/team - قائمة الفريق + دوري وصلاحياتي في هذا الحساب */
    public function listTeam(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            return $this->success([
                'members' => $this->teamService->listForTenant($this->tenantId()),
                'my_role' => $this->permissionService->roleFor($this->uid()),
                'my_permissions' => $this->permissionService->permissionsFor($this->uid()),
                'is_tenant_owner' => $this->tenantId() === $this->uid(),
            ]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listTeam');
        }
    }

    /** POST /api/crm/team {email, role} - إضافة عضو (لازم يكون له حساب Tourfecto بالفعل) */
    public function addTeamMember(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('manage_settings')) return $denied;
        if (!$this->validate(['email' => 'required', 'role' => 'required'])) return $this->error('البريد والدور مطلوبان', 422);
        try {
            $member = $this->teamService->addMember($this->tenantId(), $this->uid(), (string) $this->get('email'), (string) $this->get('role'));
            return $this->success(['member' => $member->toArray()], 'تمت الإضافة', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'addTeamMember');
        }
    }

    /** PUT /api/crm/team/{id} {role} */
    public function updateTeamMemberRole(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('manage_settings')) return $denied;
        if (!$this->validate(['role' => 'required'])) return $this->error('الدور مطلوب', 422);
        try {
            $member = $this->teamService->updateRole($this->tenantId(), (int) ($params['id'] ?? 0), (string) $this->get('role'));
            return $this->success(['member' => $member->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateTeamMemberRole');
        }
    }

    /** DELETE /api/crm/team/{id} */
    public function removeTeamMember(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($denied = $this->requirePermission('manage_settings')) return $denied;
        try {
            $this->teamService->removeMember($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تمت الإزالة');
        } catch (Exception $e) {
            return $this->handleException($e, 'removeTeamMember');
        }
    }
}
