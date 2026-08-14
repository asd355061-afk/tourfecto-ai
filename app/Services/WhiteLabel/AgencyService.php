<?php
/**
 * Tourfecto - Agency Service (White-Label)
 * الوكالة = مساحة عمل مملوكة لمستخدم users حقيقي، وعملاؤها = مستخدمون
 * حقيقيون تانيين مربوطين بجدول agency_clients. لا يوجد نظام دخول أو
 * جدول مستخدمين منفصل (خلافًا للموديول الأصلي ai-white-label-hub).
 * @version 1.0.0
 */
class AgencyService {
    public function createAgency(int $ownerUserId, string $name): Agency {
        $slug = $this->uniqueSlug($name);

        $agency = new Agency([
            'owner_user_id' => $ownerUserId,
            'name' => $name,
            'slug' => $slug,
            'status' => 'active',
            'plan_seats' => 5,
        ]);
        $agency->save();

        // إنشاء إعدادات هوية بصرية افتراضية فورًا (بدل شاشة فاضية لحد ما يعدّل)
        $branding = new AgencyBranding([
            'agency_id' => (int) $agency->getAttribute('id'),
            'primary_color' => '#4F46E5',
            'secondary_color' => '#0EA5E9',
        ]);
        $branding->save();

        ActivityLog::record('white_label', 'agency.created', [
            'user_id' => $ownerUserId, 'agency_id' => (int) $agency->getAttribute('id'),
            'subject_type' => 'agencies', 'subject_id' => (int) $agency->getAttribute('id'),
        ]);

        return $agency;
    }

    public function addClient(int $agencyId, int $clientUserId): AgencyClient {
        $agency = (new Agency())->find($agencyId);
        if (!$agency) {
            throw new Exception('الوكالة غير موجودة');
        }

        $existing = (new AgencyClient())->where(['agency_id' => $agencyId, 'client_user_id' => $clientUserId]);
        if (!empty($existing)) {
            throw new Exception('هذا العميل مضاف بالفعل لهذه الوكالة');
        }

        $currentCount = count((new AgencyClient())->where(['agency_id' => $agencyId]));
        if ($currentCount >= (int) $agency->getAttribute('plan_seats')) {
            throw new Exception('تم الوصول للحد الأقصى لعدد العملاء المسموح به في باقة الوكالة الحالية');
        }

        $client = new AgencyClient([
            'agency_id' => $agencyId,
            'client_user_id' => $clientUserId,
            'status' => 'active',
        ]);
        $client->save();

        ActivityLog::record('white_label', 'agency.client_added', [
            'agency_id' => $agencyId, 'subject_type' => 'agency_clients', 'subject_id' => (int) $client->getAttribute('id'),
        ]);

        return $client;
    }

    private function uniqueSlug(string $name): string {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $base = $base ?: 'agency';
        $slug = $base;
        $i = 1;
        while (!empty((new Agency())->where(['slug' => $slug]))) {
            $slug = $base . '-' . (++$i);
        }
        return $slug;
    }
}
