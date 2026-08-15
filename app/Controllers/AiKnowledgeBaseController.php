<?php
/**
 * Tourfecto - AI Chat Platform
 * إدارة قاعدة معرفة الشركة الخاصة بـ AI Chat (بند 4 + 13 Brand Voice).
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class AiKnowledgeBaseController extends Controller {

    /** @var KnowledgeBaseService */
    private $knowledgeBase;

    /** @var AiKnowledgeBase */
    private $model;

    public function __construct() {
        parent::__construct();
        $this->knowledgeBase = new KnowledgeBaseService();
        $this->model = new AiKnowledgeBase();
    }

    /**
     * كل عناصر قاعدة المعرفة لموقع معيّن، مجمّعة حسب القسم.
     * GET /api/ai-chat/websites/{id}/knowledge-base
     */
    public function index(array $params = []): array {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $grouped = $this->knowledgeBase->getGroupedByCompany((int) $website->getAttribute('id'));
        $result = [];
        foreach ($grouped as $section => $entries) {
            $result[$section] = array_map(function (AiKnowledgeBase $entry) {
                return $this->serialize($entry);
            }, $entries);
        }

        return $this->success([
            'sections' => $result,
            'brand_voice' => $this->knowledgeBase->getBrandVoice((int) $website->getAttribute('id')),
        ]);
    }

    /**
     * إضافة عنصر جديد لقاعدة المعرفة.
     * POST /api/ai-chat/websites/{id}/knowledge-base
     */
    public function store(array $params = []): array {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $section = trim((string) $this->get('section', ''));
        if ($section === '') {
            return $this->error('section is required', 422);
        }

        try {
            $entry = $this->knowledgeBase->addEntry((int) $website->getAttribute('id'), [
                'section' => $section,
                'title' => $this->get('title'),
                'content' => $this->get('content'),
                'structured_data' => $this->get('structured_data'),
                'language' => $this->get('language', 'en'),
                'tone' => $this->get('tone'),
                'priority' => (int) $this->get('priority', 0),
                'created_by_user_id' => $this->user['id'],
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        if (!$entry) {
            return $this->error('Failed to save knowledge base entry', 500);
        }

        return $this->success(['entry' => $this->serialize($entry)], 'Knowledge base entry added', 201);
    }

    /**
     * تحديث عنصر موجود.
     * PUT /api/ai-chat/websites/{id}/knowledge-base/{entryId}
     */
    public function update(array $params = []): array {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $entryId = (int) ($params['entryId'] ?? 0);
        $updated = $this->knowledgeBase->updateEntry($entryId, (int) $website->getAttribute('id'), $this->all());

        if (!$updated) {
            return $this->error('Entry not found or does not belong to this website', 404);
        }

        return $this->success([], 'Knowledge base entry updated');
    }

    /**
     * حذف (منطقي) لعنصر من قاعدة المعرفة.
     * DELETE /api/ai-chat/websites/{id}/knowledge-base/{entryId}
     */
    public function destroy(array $params = []): array {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $entryId = (int) ($params['entryId'] ?? 0);
        $deleted = $this->knowledgeBase->deleteEntry($entryId, (int) $website->getAttribute('id'));

        if (!$deleted) {
            return $this->error('Entry not found or does not belong to this website', 404);
        }

        return $this->success([], 'Knowledge base entry deleted');
    }

    /**
     * معاينة النص الفعلي الذي سيُحقن في الـAI System Prompt (مفيد للتشخيص
     * والتأكد أن المعلومات المحفوظة صحيحة قبل تفعيل AI Chat).
     * GET /api/ai-chat/websites/{id}/knowledge-base/preview
     */
    public function preview(array $params = []): array {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $language = $this->get('language');
        $context = $this->knowledgeBase->buildContextForPrompt((int) $website->getAttribute('id'), $language);

        return $this->success(['context_preview' => $context]);
    }

    /**
     * التحقق من ملكية الموقع للمستخدم الحالي (عزل بيانات - بند 26).
     * @param int $websiteId
     * @return Website|null
     */
    private function authorizedWebsite(int $websiteId): ?Website {
        if ($websiteId <= 0) {
            return null;
        }
        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return null;
        }
        return $website;
    }

    /**
     * @param AiKnowledgeBase $entry
     * @return array
     */
    private function serialize(AiKnowledgeBase $entry): array {
        return [
            'id' => $entry->getAttribute('id'),
            'section' => $entry->getAttribute('section'),
            'title' => $entry->getAttribute('title'),
            'content' => $entry->getAttribute('content'),
            'structured_data' => $entry->getAttribute('structured_data') ? json_decode($entry->getAttribute('structured_data'), true) : null,
            'language' => $entry->getAttribute('language'),
            'tone' => $entry->getAttribute('tone'),
            'priority' => $entry->getAttribute('priority'),
            'created_at' => $entry->getAttribute('created_at'),
            'updated_at' => $entry->getAttribute('updated_at'),
        ];
    }
}
