<?php
/**
 * Tourfecto - Business Location Controller
 * Business Control Center - Phase 3
 * @version 1.0.0
 */
class BusinessLocationController extends Controller {

    private function currentUser(): ?User {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        $model = new User();
        return $model->find($id);
    }

    /**
     * يتأكد إن الـBusiness موجود ومتاح للمستخدم الحالي (RBAC عبر
     * BusinessAccessService - Phase 10-11) - نفس مبدأ IDOR-safety.
     */
    private function loadOwnedBusiness(int $businessId, int $userId): ?Business {
        return (new BusinessAccessService())->getAccessibleBusiness($businessId, $userId);
    }

    /**
     * يحمّل Location ويتأكد إن الـBusiness بتاعها متاح للمستخدم الحالي
     * مع صلاحية التعديل (canEdit). فحص على مستويين (location -> business
     * -> role) - مش كفاية نتأكد إن الـLocation موجودة، لازم نتأكد إن
     * الـBusiness اللي بتتبعها متاح للمستخدم مع صلاحية الكتابة فعلًا
     * (viewer يشوف بس). مستخدمة في عمليات التعديل/الحذف بس.
     */
    private function loadOwnedLocation(int $locationId, int $userId): ?BusinessLocation {
        $location = (new BusinessLocation())->find($locationId);
        if (!$location) {
            return null;
        }
        $businessId = (int) $location->getAttribute('business_id');
        $access = new BusinessAccessService();
        if (!$access->canEdit($businessId, $userId)) {
            return null;
        }
        return $location;
    }

    /** GET /api/business/{businessId}/locations */
    public function index(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $business = $this->loadOwnedBusiness((int) ($params['businessId'] ?? 0), (int) $user->getAttribute('id'));
        if (!$business) {
            return $this->error('Business Profile غير موجود', 404);
        }

        $locations = (new BusinessLocation())->where(
            ['business_id' => (int) $business->getAttribute('id')],
            ['is_primary' => 'DESC', 'id' => 'ASC']
        );

        return $this->success(['locations' => array_map(fn($l) => $l->toArray(), $locations)]);
    }

    /** POST /api/business/{businessId}/locations */
    public function store(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        $userId = (int) $user->getAttribute('id');

        $business = $this->loadOwnedBusiness((int) ($params['businessId'] ?? 0), $userId);
        if (!$business) {
            return $this->error('Business Profile غير موجود', 404);
        }
        if (!(new BusinessAccessService())->canEdit((int) $business->getAttribute('id'), $userId)) {
            return $this->error('ليست لديك صلاحية تعديل البيانات', 403);
        }

        $validationError = $this->validateLocationInput();
        if ($validationError !== null) {
            return $validationError;
        }

        try {
            $service = new BusinessLocationService();
            $location = $service->create((int) $business->getAttribute('id'), $this->all());
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('BusinessLocation create failed: ' . $e->getMessage());
            }
            return $this->error('تعذر إنشاء الموقع', 500);
        }

        (new BusinessContextService())->invalidate((int) $business->getAttribute('id'));

        BusinessAuditLog::record((int) $business->getAttribute('id'), $userId, 'location_created', 'success', 'location', (string) $location->getAttribute('id'));

        return $this->success(['location' => $location->toArray()], 'تم إنشاء الموقع', 201);
    }

    /** PUT /api/business/locations/{id} */
    public function update(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $location = $this->loadOwnedLocation((int) ($params['id'] ?? 0), (int) $user->getAttribute('id'));
        if (!$location) {
            return $this->error('الموقع غير موجود', 404);
        }

        $validationError = $this->validateLocationInput();
        if ($validationError !== null) {
            return $validationError;
        }

        try {
            $service = new BusinessLocationService();
            if ($service->update($location, $this->all()) === false) {
                return $this->error('تعذر تحديث الموقع', 500);
            }
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('BusinessLocation update failed: ' . $e->getMessage());
            }
            return $this->error('تعذر تحديث الموقع', 500);
        }

        (new BusinessContextService())->invalidate((int) $location->getAttribute('business_id'));

        BusinessAuditLog::record((int) $location->getAttribute('business_id'), (int) $user->getAttribute('id'), 'location_updated', 'success', 'location', (string) $location->getAttribute('id'));

        return $this->success(['location' => $location->toArray()], 'تم تحديث الموقع');
    }

    /** DELETE /api/business/locations/{id} */
    public function destroy(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $location = $this->loadOwnedLocation((int) ($params['id'] ?? 0), (int) $user->getAttribute('id'));
        if (!$location) {
            return $this->error('الموقع غير موجود', 404);
        }

        $businessId = (int) $location->getAttribute('business_id'); // لازم قبل delete() - بتفضّي attributes الموديل بعد النجاح

        $service = new BusinessLocationService();
        if ($service->delete($location) === false) {
            return $this->error('تعذر حذف الموقع', 500);
        }

        (new BusinessContextService())->invalidate($businessId);

        BusinessAuditLog::record($businessId, (int) $user->getAttribute('id'), 'location_deleted', 'success', 'location', (string) $location->getAttribute('id'));

        return $this->success([], 'تم حذف الموقع');
    }

    private function validateLocationInput(): ?array {
        $rules = [
            'name' => 'max_length:255',
            'city' => 'max_length:150',
            'address' => 'max_length:500',
            'postal_code' => 'max_length:20',
            'phone' => 'max_length:50',
            'email' => 'email|max_length:255',
        ];

        if (!$this->validate($rules)) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        if ($this->has('country_code') && $this->get('country_code') !== '') {
            if (!preg_match('/^[A-Za-z]{2}$/', (string) $this->get('country_code'))) {
                return $this->error('كود الدولة غير صحيح', 422, ['country_code' => ['يجب أن يكون كود ISO 3166-1 من حرفين']]);
            }
        }

        foreach (['latitude', 'longitude'] as $coord) {
            if ($this->has($coord) && $this->get($coord) !== '' && $this->get($coord) !== null) {
                if (!is_numeric($this->get($coord))) {
                    return $this->error('إحداثيات غير صحيحة', 422, [$coord => ['يجب أن يكون رقم']]);
                }
            }
        }

        return null;
    }
}
