<?php
/**
 * Tourfecto - Action Center Controller
 * Phase 12. Endpoint واحد Read-Only بيجمع "ماذا أفعل الآن؟" من كل الـAgents.
 * @version 1.0.0
 */
class ActionCenterController extends Controller {

    /** GET /api/action-center?website_id=X (اختياري) */
    public function list(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        $websiteId = $this->get('website_id') ? (int) $this->get('website_id') : null;

        if (!class_exists('ActionCenterService')) return $this->error('الخدمة غير متاحة', 500);

        try {
            $service = new ActionCenterService();
            $items = $service->getActionItems($this->db, (int) $this->user['id'], $websiteId);
        } catch (Exception $e) {
            Logger::error('Action Center Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب قائمة الإجراءات', 500);
        }

        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($items as $item) {
            if (isset($counts[$item['priority']])) $counts[$item['priority']]++;
        }

        return $this->success([
            'items' => $items,
            'total' => count($items),
            'counts_by_priority' => $counts,
        ]);
    }
}
