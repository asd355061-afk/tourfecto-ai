<?php
/**
 * Tourfecto - Business AI Context Controller
 * Business Control Center - Phase 6
 * @version 1.0.0
 */
class BusinessAiContextController extends Controller {

    private function currentUser(): ?User {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        $model = new User();
        return $model->find($id);
    }

    private function loadOwnedBusiness(int $businessId, int $userId): ?Business {
        return (new BusinessAccessService())->getAccessibleBusiness($businessId, $userId);
    }

    /** GET /api/business/{businessId}/ai-context */
    public function show(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $business = $this->loadOwnedBusiness((int) ($params['businessId'] ?? 0), (int) $user->getAttribute('id'));
        if (!$business) {
            return $this->error('Business Profile غير موجود', 404);
        }

        $rows = (new BusinessAiContext())->where(['business_id' => (int) $business->getAttribute('id')], [], 1);
        if (empty($rows)) {
            return $this->success(['ai_context' => null]);
        }

        return $this->success(['ai_context' => $rows[0]->toArray()]);
    }

    /**
     * GET /api/business/{businessId}/ai-context/full
     * السياق الكامل المُجمّع (Business + Locations + Services + Markets
     * + AI Context) عبر BusinessContextService - نفس البيانات اللي أي
     * AI Module في المنصة هيستخدمها فعليًا، مش نسخة تانية.
     */
    public function full(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $businessId = (int) ($params['businessId'] ?? 0);
        $business = $this->loadOwnedBusiness($businessId, (int) $user->getAttribute('id'));
        if (!$business) {
            return $this->error('Business Profile غير موجود', 404);
        }

        $context = (new BusinessContextService())->getContext($businessId);

        return $this->success(['context' => $context]);
    }

    /** PUT /api/business/{businessId}/ai-context */
    public function upsert(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $businessId = (int) ($params['businessId'] ?? 0);
        $business = $this->loadOwnedBusiness($businessId, (int) $user->getAttribute('id'));
        if (!$business) {
            return $this->error('Business Profile غير موجود', 404);
        }
        if (!(new BusinessAccessService())->canEdit($businessId, (int) $user->getAttribute('id'))) {
            return $this->error('ليست لديك صلاحية تعديل البيانات', 403);
        }

        if (!$this->validate([
            'business_summary' => 'max_length:2000',
            'brand_description' => 'max_length:2000',
            'target_audience' => 'max_length:2000',
            'brand_voice' => 'max_length:50',
            'preferred_tone' => 'max_length:100',
            'important_notes' => 'max_length:2000',
        ])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        // Brand Voice (Phase 7 - Brand Settings): قبل ما القائمة الرسمية
        // كانت موجودة، أي نص لحد 50 حرف كان مقبول. دلوقتي بعد ما
        // BusinessAiContext::allowedBrandVoicePresets() اتعرّفت، نتحقق
        // فعليًا - قيمة عشوائية زي "طنطاوي" كانت هتتقبل قبل كده بصمت.
        if ($this->has('brand_voice') && $this->get('brand_voice') !== '') {
            if (!in_array($this->get('brand_voice'), BusinessAiContext::allowedBrandVoicePresets(), true)) {
                return $this->error('Brand Voice غير معروف', 422, ['brand_voice' => ['يجب اختيار قيمة من القائمة المعتمدة، أو custom مع تفاصيل في Brand Settings']]);
            }
        }

        $arrayFields = ['unique_selling_points', 'forbidden_claims', 'preferred_keywords', 'business_goals', 'seo_goals', 'content_goals', 'competitors'];
        foreach ($arrayFields as $field) {
            if ($this->has($field) && !is_array($this->get($field))) {
                return $this->error('بيانات غير صحيحة', 422, [$field => ['يجب أن تكون قائمة (Array)']]);
            }
        }

        // competitors شكلها خاص: مصفوفة {name, url} objects مش نصوص
        // بسيطة زي باقي الحقول - نتحقق من الشكل قبل التخزين.
        if ($this->has('competitors')) {
            foreach ($this->get('competitors') as $competitor) {
                if (!is_array($competitor) || !isset($competitor['name'])) {
                    return $this->error('بيانات المنافسين غير صحيحة', 422, ['competitors' => ['كل عنصر لازم يحتوي على الأقل name']]);
                }
            }
        }

        $existing = (new BusinessAiContext())->where(['business_id' => $businessId], [], 1);
        $record = !empty($existing) ? $existing[0] : new BusinessAiContext();
        if (empty($existing)) {
            $record->setAttribute('business_id', $businessId);
        }

        foreach (['business_summary', 'brand_description', 'target_audience', 'brand_voice', 'preferred_tone', 'important_notes'] as $field) {
            if ($this->has($field)) {
                $record->setAttribute($field, trim((string) $this->get($field)));
            }
        }
        foreach ($arrayFields as $field) {
            if ($this->has($field)) {
                $record->setAttribute($field, json_encode(array_values($this->get($field))));
            }
        }

        if ($record->save() === false) {
            return $this->error('تعذر حفظ الـAI Business Context', 500);
        }

        // أهم سطر في الدالة دي: بدون invalidate() هنا، أي AI Module هيفضل
        // شايف نسخة قديمة من الـContext لحد ما ينتهي الـCache TTL (ساعة).
        (new BusinessContextService())->invalidate($businessId);

        return $this->success(['ai_context' => $record->toArray()], 'تم حفظ الـAI Business Context');
    }
}
