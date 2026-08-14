<?php
/** Tourfecto - CRM Company Service @version 1.0.0 */
class CrmCompanyService {
    public function create(int $userId, array $data): CrmCompany {
        if (empty($data['name'])) {
            throw new Exception('اسم الشركة مطلوب');
        }
        $company = new CrmCompany([
            'user_id' => $userId,
            'name' => $data['name'],
            'industry' => $data['industry'] ?? null,
            'website' => $data['website'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'country' => $data['country'] ?? null,
            'company_size' => $data['company_size'] ?? null,
            'notes' => $data['notes'] ?? null,
            'tags' => isset($data['tags']) ? json_encode($data['tags'], JSON_UNESCAPED_UNICODE) : null,
        ]);
        $company->save();

        ActivityLog::record('crm', 'company.created', [
            'user_id' => $userId, 'subject_type' => 'crm_companies', 'subject_id' => (int) $company->getAttribute('id'),
        ]);

        return $company;
    }

    public function findOwned(int $userId, int $companyId): CrmCompany {
        $company = (new CrmCompany())->find($companyId);
        if (!$company || (int) $company->getAttribute('user_id') !== $userId) {
            throw new Exception('الشركة غير موجودة', 404);
        }
        return $company;
    }

    public function update(int $userId, int $companyId, array $data): CrmCompany {
        $company = $this->findOwned($userId, $companyId);
        foreach (['name', 'industry', 'website', 'phone', 'email', 'address', 'city', 'country', 'company_size', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $company->setAttribute($field, $data[$field]);
            }
        }
        $company->save();

        ActivityLog::record('crm', 'company.updated', [
            'user_id' => $userId, 'subject_type' => 'crm_companies', 'subject_id' => $companyId,
        ]);

        return $company;
    }

    public function listForUser(int $userId, int $limit = 200): array {
        return (new CrmCompany())->allForUser($userId, $limit);
    }
}
