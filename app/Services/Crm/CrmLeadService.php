<?php
/**
 * Tourfecto - CRM Lead Service
 * @version 1.1.0
 *
 * تحديث: الدوال الأصلية (createContact/createLead/updateStatus) لم تُعدَّل
 * إطلاقًا - تمت فقط إضافة عمليات ناقصة (list/get/assign/delete) بدون لمس
 * المنطق الحالي، حفاظًا على "لا تعمل Refactor شامل" (بند 40).
 */
class CrmLeadService {
    public function createContact(int $userId, array $data): CrmContact {
        $contact = new CrmContact([
            'user_id' => $userId,
            'agency_id' => $data['agency_id'] ?? null,
            'company_id' => $data['company_id'] ?? null,
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'country' => $data['country'] ?? null,
            'language' => $data['language'] ?? null,
            'source' => $data['source'] ?? 'manual',
            'status' => 'active',
            'notes' => $data['notes'] ?? null,
        ]);
        $contact->save();

        ActivityLog::record('crm', 'contact.created', [
            'user_id' => $userId, 'subject_type' => 'crm_contacts', 'subject_id' => (int) $contact->getAttribute('id'),
        ]);

        return $contact;
    }

    public function createLead(int $contactId, ?int $ownerUserId = null): CrmLead {
        $lead = new CrmLead([
            'contact_id' => $contactId,
            'owner_user_id' => $ownerUserId,
            'status' => 'new',
            'score' => 0,
        ]);
        $lead->save();

        // إضافة المرحلة 3 (بند 12/36): سطر واحد فقط لإطلاق محرك الـAutomation -
        // بدون أي تغيير في منطق إنشاء الـLead نفسه. القواعد مربوطة بحساب
        // الـTenant (صاحب جهة الاتصال) وليس بـowner_user_id (ممكن يكون فاضي
        // لسه لو الـLead لسه غير معيّن لمندوب).
        $tenantUserId = (int) ((new CrmContact())->find($contactId)?->getAttribute('user_id') ?? 0);
        (new CrmAutomationService())->trigger('lead.created', $tenantUserId, [
            'lead_id' => (int) $lead->getAttribute('id'), 'contact_id' => $contactId,
        ]);

        return $lead;
    }

    /** نسخة موسّعة من createLead تدعم المصدر/القيمة/الاهتمام/الملاحظات (بند 3) */
    public function createLeadWithData(int $contactId, ?int $ownerUserId, array $data): CrmLead {
        $lead = new CrmLead([
            'contact_id' => $contactId,
            'owner_user_id' => $ownerUserId,
            'source' => $data['source'] ?? null,
            'interest' => $data['interest'] ?? null,
            'value' => $data['value'] ?? null,
            'currency' => $data['currency'] ?? 'USD',
            'status' => $data['status'] ?? 'new',
            'next_follow_up_at' => $data['next_follow_up_at'] ?? null,
            'score' => 0,
        ]);
        $lead->save();

        ActivityLog::record('crm', 'lead.created', [
            'user_id' => $ownerUserId, 'subject_type' => 'crm_leads', 'subject_id' => (int) $lead->getAttribute('id'),
        ]);

        $tenantUserId = (int) ((new CrmContact())->find($contactId)?->getAttribute('user_id') ?? 0);
        (new CrmAutomationService())->trigger('lead.created', $tenantUserId, [
            'lead_id' => (int) $lead->getAttribute('id'), 'contact_id' => $contactId,
        ]);

        return $lead;
    }

    public function updateStatus(int $leadId, string $status): CrmLead {
        $lead = (new CrmLead())->find($leadId);
        if (!$lead) {
            throw new Exception('Lead غير موجود');
        }
        $before = $lead->getAttribute('status');
        $lead->setAttribute('status', $status);
        $lead->setAttribute('last_engagement_at', date('Y-m-d H:i:s'));
        $lead->save();

        ActivityLog::record('crm', 'lead.status_changed', [
            'subject_type' => 'crm_leads', 'subject_id' => $leadId,
            'meta' => ['before' => $before, 'after' => $status],
        ]);

        // إضافة المرحلة 3: سطر واحد لإطلاق Automation عند تغيّر حالة الـLead
        // (يغطي مثلًا "WHEN: Lead qualified" عبر condition على status الجديدة).
        $tenantUserId = (int) ((new CrmContact())->find((int) $lead->getAttribute('contact_id'))?->getAttribute('user_id') ?? 0);
        (new CrmAutomationService())->trigger('lead.status_changed', $tenantUserId, [
            'lead_id' => $leadId, 'status' => $status, 'previous_status' => $before,
        ]);

        return $lead;
    }

    /** GET قائمة كاملة مع بيانات جهة الاتصال (Tenant-scoped) */
    public function listForUser(int $userId, int $limit = 200): array {
        return (new CrmLead())->allForUser($userId, $limit);
    }

    public function find(int $leadId): ?CrmLead {
        return (new CrmLead())->find($leadId);
    }

    /** تعيين مسؤول مبيعات لـLead (بند 3: Assign) */
    public function assignOwner(int $leadId, int $ownerUserId): CrmLead {
        $lead = $this->find($leadId);
        if (!$lead) {
            throw new Exception('Lead غير موجود');
        }
        $lead->setAttribute('owner_user_id', $ownerUserId);
        $lead->save();

        ActivityLog::record('crm', 'lead.assigned', [
            'subject_type' => 'crm_leads', 'subject_id' => $leadId, 'meta' => ['owner_user_id' => $ownerUserId],
        ]);

        return $lead;
    }

    /** أرشفة/حذف Lead (بند 3: Archive) - حذف ناعم عبر status، بدون فقد بيانات */
    public function archive(int $leadId): CrmLead {
        return $this->updateStatus($leadId, 'disqualified');
    }

    /** تحويل Lead لصفقة (بند 3: Convert) - ينشئ Deal في أول مرحلة من المسار الافتراضي */
    public function convertToDeal(int $leadId, int $ownerUserId, ?int $stageId = null, ?float $value = null): CrmDeal {
        $lead = $this->find($leadId);
        if (!$lead) {
            throw new Exception('Lead غير موجود');
        }

        if (!$stageId) {
            $firstStage = (new CrmPipelineStage())->forPipeline(null);
            if (empty($firstStage)) {
                throw new Exception('لا توجد مراحل مسار مُهيّأة');
            }
            $stageId = (int) $firstStage[0]['id'];
        }

        $deal = new CrmDeal([
            'owner_user_id' => $ownerUserId,
            'lead_id' => $leadId,
            'contact_id' => $lead->getAttribute('contact_id'),
            'stage_id' => $stageId,
            'title' => 'صفقة من: ' . ($lead->getAttribute('interest') ?: ('Lead #' . $leadId)),
            'value' => $value ?? ($lead->getAttribute('value') ?: 0),
            'currency' => $lead->getAttribute('currency') ?: 'USD',
            'status' => 'open',
        ]);
        $deal->save();

        $lead->setAttribute('status', 'converted');
        $lead->save();

        ActivityLog::record('crm', 'lead.converted', [
            'user_id' => $ownerUserId, 'subject_type' => 'crm_leads', 'subject_id' => $leadId,
            'meta' => ['deal_id' => (int) $deal->getAttribute('id')],
        ]);

        return $deal;
    }
}
