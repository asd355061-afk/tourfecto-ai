<?php
/**
 * Tourfecto - Business Team Controller
 * Team Management + RBAC - Business Control Center Phase 10
 * @version 1.0.0
 *
 * API الفريق. كل الـEndpoints AuthMiddleware-protected وبتفحص الصلاحية
 * عبر BusinessAccessService (نقطة الفحص المركزية) مش isOwnedBy() متكررة.
 *
 * قواعد الدخول:
 *   - GET list: أي عضو (viewer فأعلى) - للعرض فقط.
 *   - POST invite / DELETE member / PUT role: canManageTeam (owner أو admin) -
 *     والقواعد الدقيقة (admin ميقدرش يمسّ admin تاني، تولية admin للـowner
 *     بس) محسومة جوه BusinessTeamService.
 *   - POST accept: أي مستخدم مسجل - بيقبل دعوة موجهة لبریده بالتوكن.
 */
class BusinessTeamController extends Controller {

    private function currentUser(): ?User {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        $model = new User();
        return $model->find($id);
    }

    private function access(): BusinessAccessService {
        return new BusinessAccessService();
    }

    /**
     * يحمّل Business للمستخدم الحالي مع فحص الوصول (view فأعلى).
     * بيرجع null لو مفيش وصول إطلاقًا - الـController بيميز الـ404
     * (مش مملوك/مش معروف) من الـ403 (viewer بيحاول يكتب).
     */
    private function loadAccessibleBusiness(int $businessId, int $userId): ?Business {
        return $this->access()->getAccessibleBusiness($businessId, $userId);
    }

    /** GET /api/business/{businessId}/team */
    public function index(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $business = $this->loadAccessibleBusiness((int) ($params['businessId'] ?? 0), (int) $user->getAttribute('id'));
        if (!$business) {
            return $this->error('Business غير موجود', 404);
        }

        $team = (new BusinessTeamService())->list((int) $business->getAttribute('id'));

        return $this->success(['team' => $team]);
    }

    /**
     * POST /api/business/{businessId}/team/invite
     * body: { email, role } - role من admin/member/viewer.
     */
    public function invite(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        $userId = (int) $user->getAttribute('id');
        $businessId = (int) ($params['businessId'] ?? 0);

        $business = $this->loadAccessibleBusiness($businessId, $userId);
        if (!$business) {
            return $this->error('Business غير موجود', 404);
        }
        if (!$this->access()->canManageTeam($businessId, $userId)) {
            return $this->error('ليست لديك صلاحية إدارة الفريق', 403);
        }

        if (!$this->validate(['email' => 'required|email|max_length:255', 'role' => 'required'])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }
        if (!in_array($this->get('role'), BusinessAccessService::allowedMemberRoles(), true)) {
            return $this->error('دور غير صالح', 422, ['role' => ['القيم المسموحة: ' . implode(', ', BusinessAccessService::allowedMemberRoles())]]);
        }

        $result = (new BusinessTeamService())->invite(
            $businessId,
            $userId,
            (string) $this->get('email'),
            (string) $this->get('role')
        );
        if (!$result['ok']) {
            return $this->error($result['error'], 409);
        }

        $message = $result['type'] === 'added' ? 'تمت إضافة العضو للفريق' : 'تم إنشاء الدعوة بنجاح';
        return $this->success(['member' => $result['member'], 'type' => $result['type'], 'invite_link' => $result['invite_link'] ?? null], $message, 201);
    }

    /**
     * POST /api/business/{businessId}/team/invite/{token}/accept
     * قبول دعوة معلقة - اللي بيدخل لازم يكون بريده هو بريد الدعوة.
     */
    public function acceptInvite(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $businessId = (int) ($params['businessId'] ?? 0);
        $token = (string) ($params['token'] ?? '');

        $result = (new BusinessTeamService())->acceptInvite(
            $businessId,
            $token,
            (int) $user->getAttribute('id'),
            (string) $user->getAttribute('email')
        );
        if (!$result['ok']) {
            return $this->error($result['error'], 422);
        }

        return $this->success(['member' => $result['member']], 'تم قبول الدعوة والانضمام للفريق');
    }

    /**
     * DELETE /api/business/{businessId}/team/members/{memberId}
     * حذف عضو - القاعدة الدقيقة جوه الـService.
     */
    public function remove(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        $userId = (int) $user->getAttribute('id');
        $businessId = (int) ($params['businessId'] ?? 0);

        $business = $this->loadAccessibleBusiness($businessId, $userId);
        if (!$business) {
            return $this->error('Business غير موجود', 404);
        }
        if (!$this->access()->canManageTeam($businessId, $userId)) {
            return $this->error('ليست لديك صلاحية إدارة الفريق', 403);
        }

        $actorRole = $this->access()->roleOf($businessId, $userId);
        $result = (new BusinessTeamService())->remove($businessId, (string) $actorRole, (int) ($params['memberId'] ?? 0));
        if (!$result['ok']) {
            return $this->error($result['error'], 403);
        }

        return $this->success([], 'تم حذف العضو من الفريق');
    }

    /**
     * PUT /api/business/{businessId}/team/members/{memberId}/role
     * body: { role } - القاعدة الدقيقة جوه الـService.
     */
    public function changeRole(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        $userId = (int) $user->getAttribute('id');
        $businessId = (int) ($params['businessId'] ?? 0);

        $business = $this->loadAccessibleBusiness($businessId, $userId);
        if (!$business) {
            return $this->error('Business غير موجود', 404);
        }
        if (!$this->access()->canManageTeam($businessId, $userId)) {
            return $this->error('ليست لديك صلاحية إدارة الفريق', 403);
        }

        if (!$this->validate(['role' => 'required'])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        $actorRole = $this->access()->roleOf($businessId, $userId);
        $result = (new BusinessTeamService())->changeRole(
            $businessId,
            (string) $actorRole,
            (int) ($params['memberId'] ?? 0),
            (string) $this->get('role')
        );
        if (!$result['ok']) {
            return $this->error($result['error'], 403);
        }

        return $this->success(['member' => $result['member']], 'تم تحديث دور العضو');
    }
}
