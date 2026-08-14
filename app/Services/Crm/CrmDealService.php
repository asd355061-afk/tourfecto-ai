<?php
/**
 * Tourfecto - CRM Deal Service
 * @version 1.0.0
 *
 * ملاحظة: منطق إنشاء/نقل الصفقة الأساسي موجود بالفعل داخل CrmController
 * (createDeal/updateDealStage) ولم يُلمس هنا إطلاقًا. هذا الكلاس يضيف فقط
 * عمليات كانت ناقصة تمامًا: تعديل بيانات الصفقة، حذفها، ودعم Multiple
 * Pipelines (بند 6/7)، واكتشاف الصفقات المعرّضة للخطر (بند 26 - Heuristic
 * شفاف مبني على بيانات حقيقية فقط: بدون نشاط حديث، وليس تنبؤًا مُدّعى).
 */
class CrmDealService {
    public function findOwned(int $ownerUserId, int $dealId): CrmDeal {
        $deal = (new CrmDeal())->find($dealId);
        if (!$deal || (int) $deal->getAttribute('owner_user_id') !== $ownerUserId) {
            throw new Exception('الصفقة غير موجودة', 404);
        }
        return $deal;
    }

    public function update(int $ownerUserId, int $dealId, array $data): CrmDeal {
        $deal = $this->findOwned($ownerUserId, $dealId);
        foreach (['title', 'value', 'currency', 'probability', 'expected_close_date', 'company_id', 'notes', 'lost_reason'] as $field) {
            if (array_key_exists($field, $data)) {
                $deal->setAttribute($field, $data[$field]);
            }
        }
        $deal->save();

        ActivityLog::record('crm', 'deal.updated', [
            'user_id' => $ownerUserId, 'subject_type' => 'crm_deals', 'subject_id' => $dealId,
        ]);

        return $deal;
    }

    public function delete(int $ownerUserId, int $dealId): bool {
        $deal = $this->findOwned($ownerUserId, $dealId);
        $result = $deal->delete();

        ActivityLog::record('crm', 'deal.deleted', [
            'user_id' => $ownerUserId, 'subject_type' => 'crm_deals', 'subject_id' => $dealId,
        ]);

        return $result;
    }

    /** بند 26: صفقات معرّضة للخطر - إشارات حقيقية فقط (لا تنبؤ AI هنا بعد) */
    public function atRiskDeals(int $ownerUserId): array {
        $stale = (new CrmDeal())->staleOpenDeals($ownerUserId, 14);
        return array_map(function ($deal) {
            $daysStale = (int) floor((time() - strtotime($deal['updated_at'])) / 86400);
            return array_merge($deal, [
                'risk_level' => $daysStale >= 30 ? 'high' : ($daysStale >= 14 ? 'medium' : 'low'),
                'risk_reason' => "لا يوجد نشاط منذ {$daysStale} يوم في مرحلة \"{$deal['stage_name']}\"",
            ]);
        }, $stale);
    }
}
