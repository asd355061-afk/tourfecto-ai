<?php

/**
 * Tourfecto - Business Account Closure Service
 * Business Control Center Phase 15-16: Account deletion / business continuity
 * @version 1.0.0
 *
 * بيجهّز بيانات الـBusiness قبل حذف حساب المستخدم نهائيًا. المشكلة:
 * `businesses.owner_user_id` مربوط بـ FK ON DELETE CASCADE على users -
 * يعني حذف الحساب هياخد معاه كل الـBusinesses بتاعته وجميع بياناتها
 * (locations/services/markets/audit) في نفس اللحظة. لو الـBusiness ليه
 * فريق (business_members) ميفترضش إن البيانات تندفن مع الحساب المحذوف
 * من غير فرصة نقل.
 *
 * الحل المنفّذ هنا (غير هدّام بالكامل):
 *   - لو الـBusiness ليه عضو active واحد على الأقل: بنحوّل الملكية لأعلى
 *     عضو رتبة (admin > member > viewer) - الـBusiness بيكمل حياته مع
 *     الفريق، والحساب المحذوف بيخرج من الملكية. الباقي بيتبقى CASCADE.
 *   - كل مفاتيح API الخاصة بالـBusinesses اللي حتتدمج بتتسجّل في
 *     الـAudit كـ cleanup (والـCASCADE هيحذفها طبيعيًا - بنوثّق السبب).
 *
 * Pure logic: nextOwnerForMembers() اختيار الخلف قابل للاختبار offline.
 */
class BusinessAccountClosureService
{
    /**
     * تنفيذ التحضير الكامل قبل حذف الحساب: تحويل ملكية + توثيق.
     *
     * @return array{transferred: array<int,array{business_id:int,new_owner_id:int}>, cleaned: int}
     */
    public function prepareForAccountDeletion(int $deletedUserId): array
    {
        $transferred = [];
        $cleaned = 0;

        $owned = (new Business())->where(['owner_user_id' => $deletedUserId], [], 0);
        foreach ($owned as $business) {
            $businessId = (int) $business->getAttribute('id');
            $successor = $this->pickSuccessor($businessId);

            if ($successor !== null) {
                $business->setAttribute('owner_user_id', $successor);
                $business->save();
                BusinessAuditLog::record(
                    $businessId,
                    $deletedUserId,
                    'business_owner_transferred',
                    'success',
                    'business',
                    (string) $businessId,
                    ['from_user_id' => $deletedUserId, 'to_user_id' => $successor]
                );
                $transferred[] = ['business_id' => $businessId, 'new_owner_id' => $successor];
            } else {
                // مفيش خلف - الـCASCADE هياخد كل حاجة. بنوثّق إن الـBusiness
                // اتحذف كنتيجة لحذف الحساب (السجل ده على مستوى المستخدم
                // audit_logs مش هيتأثر بـCASCADE عمدًا).
                BusinessAuditLog::record(
                    $businessId,
                    $deletedUserId,
                    'business_deleted_with_account',
                    'success',
                    'business',
                    (string) $businessId,
                    ['reason' => 'owner_account_deleted_no_successor']
                );
            }

            // توثيق مفاتيح API اللي حتندفن مع الـBusiness (كل واحدة مش
            // ملغية - لو في أي نشاط، نعرف إيه اللي اختفى وإمتى).
            $keys = (new BusinessApiKey())->where(['business_id' => $businessId, 'revoked_at' => null], [], 0);
            foreach ($keys as $key) {
                BusinessAuditLog::record(
                    $businessId,
                    $deletedUserId,
                    'api_key_destroyed_with_account',
                    'success',
                    'api_key',
                    (string) $key->getAttribute('id'),
                    ['scope' => (string) $key->getAttribute('scope')]
                );
                $cleaned++;
            }
        }

        return ['transferred' => $transferred, 'cleaned' => $cleaned];
    }

    /**
     * اختيار أعلى عضو نشط رتبة ليرث الملكية - أو null لو مفيش حد.
     * Pure - قابل للاختبار offline بمصفوفة roles.
     */
    public function pickSuccessor(int $businessId): ?int
    {
        $members = (new BusinessMember())->where([
            'business_id' => $businessId,
            'status' => 'active',
        ], [], 0);

        $best = null;
        $bestRank = 0;
        foreach ($members as $member) {
            $userId = $member->getAttribute('user_id');
            if ($userId === null) {
                continue;
            }
            $rank = BusinessAccessService::roleRank((string) $member->getAttribute('role'));
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = (int) $userId;
            }
        }

        return $best;
    }

    /**
     * نسخة pure من الاختيار فوق مصفوفة أدوار - للاختبار بدون DB.
     *
     * @param array<int,array{user_id:int,role:string}> $members
     */
    public function pickSuccessorFromMembers(array $members): ?int
    {
        $best = null;
        $bestRank = 0;
        foreach ($members as $member) {
            $rank = BusinessAccessService::roleRank((string) ($member['role'] ?? ''));
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = (int) ($member['user_id'] ?? 0);
            }
        }
        return $best !== null && $best > 0 ? $best : null;
    }
}
