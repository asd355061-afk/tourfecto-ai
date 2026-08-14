<?php
/**
 * Tourfecto - AI Chat Platform
 * خدمة قاعدة معرفة الشركة (بند 4): إدارة المحتوى + تجميعه في نص Context
 * جاهز لحقنه في System Prompt الخاص بـ AI Conversation Engine، مع قاعدة
 * صارمة: الـAI يعتمد فقط على ما هو مكتوب هنا، ولا يخترع أسعارًا أو
 * معلومات غير موجودة.
 *
 * @version 1.0.0
 */

class KnowledgeBaseService {

    /** @var AiKnowledgeBase */
    private $model;

    /** @var array أسماء الأقسام القابلة للعرض بشكل مقروء داخل الـPrompt */
    private $sectionLabels = [
        'company_info' => 'Company Information',
        'service' => 'Services',
        'tour' => 'Tours',
        'destination' => 'Destinations',
        'pricing' => 'Pricing',
        'faq' => 'Frequently Asked Questions',
        'policy' => 'Policies',
        'cancellation_policy' => 'Cancellation Policy',
        'contact_info' => 'Contact Information',
        'business_hours' => 'Business Hours',
        'custom_instructions' => 'Custom Instructions From The Company',
        'brand_voice' => 'Brand Voice',
    ];

    public function __construct() {
        $this->model = new AiKnowledgeBase();
    }

    /**
     * إضافة عنصر جديد لقاعدة المعرفة.
     * @param int $websiteId
     * @param array $data ['section','title','content','structured_data','language','tone','priority','created_by_user_id']
     * @return AiKnowledgeBase|null
     */
    public function addEntry(int $websiteId, array $data): ?AiKnowledgeBase {
        if (empty($data['section']) || !isset($this->sectionLabels[$data['section']])) {
            throw new InvalidArgumentException('Invalid knowledge base section: ' . ($data['section'] ?? ''));
        }

        $entry = new AiKnowledgeBase();
        $entry->fill([
            'website_id' => $websiteId,
            'section' => $data['section'],
            'title' => $data['title'] ?? null,
            'content' => $data['content'] ?? null,
            'structured_data' => isset($data['structured_data']) ? json_encode($data['structured_data'], JSON_UNESCAPED_UNICODE) : null,
            'language' => $data['language'] ?? 'ar',
            'tone' => $data['tone'] ?? null,
            'priority' => $data['priority'] ?? 0,
            'is_active' => 1,
            'created_by_user_id' => $data['created_by_user_id'] ?? null,
        ]);

        return $entry->save() !== false ? $entry : null;
    }

    /**
     * تحديث عنصر موجود، مع التأكد أنه يخص نفس الموقع (عزل بيانات - بند 26).
     * @param int $entryId
     * @param int $websiteId
     * @param array $data
     * @return bool
     */
    public function updateEntry(int $entryId, int $websiteId, array $data): bool {
        $entry = $this->model->find($entryId);
        if (!$entry || (int) $entry->getAttribute('website_id') !== $websiteId) {
            return false;
        }

        $updatable = ['title', 'content', 'language', 'tone', 'priority', 'is_active'];
        $payload = array_intersect_key($data, array_flip($updatable));

        if (isset($data['structured_data'])) {
            $payload['structured_data'] = json_encode($data['structured_data'], JSON_UNESCAPED_UNICODE);
        }

        $entry->fill($payload);
        return $entry->save() !== false;
    }

    /**
     * حذف منطقي (Soft delete) لعنصر من قاعدة المعرفة.
     * @param int $entryId
     * @param int $websiteId
     * @return bool
     */
    public function deleteEntry(int $entryId, int $websiteId): bool {
        return $this->updateEntry($entryId, $websiteId, ['is_active' => 0]);
    }

    /**
     * كل عناصر قاعدة المعرفة لموقع معيّن مجمّعة حسب القسم (للعرض في لوحة الإدارة).
     * @param int $websiteId
     * @return array
     */
    public function getGroupedByCompany(int $websiteId): array {
        $entries = $this->model->activeFor($websiteId);
        $grouped = [];
        foreach ($entries as $entry) {
            $section = $entry->getAttribute('section');
            $grouped[$section][] = $entry;
        }
        return $grouped;
    }

    /**
     * بناء إعدادات Brand Voice الحالية للموقع (بند 13)، أو قيمة افتراضية
     * "professional" لو لم يحددها صاحب الشركة بعد.
     * @param int $websiteId
     * @return array ['tone' => string, 'custom_instructions' => string|null]
     */
    public function getBrandVoice(int $websiteId): array {
        $entries = $this->model->forSection($websiteId, 'brand_voice');
        $tone = 'professional';
        $customInstructions = null;

        if (!empty($entries)) {
            $tone = $entries[0]->getAttribute('tone') ?: 'professional';
            $customInstructions = $entries[0]->getAttribute('content');
        }

        return ['tone' => $tone, 'custom_instructions' => $customInstructions];
    }

    /**
     * تجميع كل قاعدة المعرفة الفعّالة في نص واحد جاهز للحقن داخل System
     * Prompt الخاص بـ AI Conversation Engine. هذه هي نقطة الالتقاء
     * الأساسية بين الوحدة الحالية (Knowledge Base) وبين بند 4 و5 و9
     * (عدم اختلاق معلومات، وطلب تحويل لموظف عند نقص المعرفة).
     *
     * @param int $websiteId
     * @param string|null $language لو محدد، يُفضَّل محتوى بنفس اللغة أولاً
     * @return string
     */
    public function buildContextForPrompt(int $websiteId, ?string $language = null): string {
        $entries = $this->model->activeFor($websiteId, $language);

        // لو لا يوجد محتوى بنفس اللغة، استخدم كل المحتوى المتاح كاحتياط
        if (empty($entries) && $language) {
            $entries = $this->model->activeFor($websiteId);
        }

        if (empty($entries)) {
            return "No company knowledge base has been configured yet. "
                . "You must NOT invent any company information, services, prices, or policies. "
                . "If the customer asks anything beyond a generic greeting, tell them you need to "
                . "check with the team and offer to connect them with a human agent.";
        }

        $bySection = [];
        foreach ($entries as $entry) {
            $section = $entry->getAttribute('section');
            if ($section === 'brand_voice') {
                continue; // الـBrand Voice يُحقن بشكل منفصل في الـSystem Prompt
            }
            $label = $this->sectionLabels[$section] ?? $section;
            $line = '- ' . trim(($entry->getAttribute('title') ? $entry->getAttribute('title') . ': ' : '') . $entry->getAttribute('content'));

            $structured = $entry->getAttribute('structured_data');
            if (!empty($structured)) {
                $decoded = json_decode($structured, true);
                if (is_array($decoded)) {
                    $line .= ' (' . http_build_query($decoded, '', ', ') . ')';
                }
            }

            $bySection[$label][] = $line;
        }

        $output = "### COMPANY KNOWLEDGE BASE (this is the ONLY source of truth - "
            . "never invent prices, services, or policies not listed here) ###\n";

        foreach ($bySection as $label => $lines) {
            $output .= "\n[{$label}]\n" . implode("\n", $lines) . "\n";
        }

        return $output;
    }
}
