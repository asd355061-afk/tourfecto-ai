<?php
/**
 * Tourfecto - AI Chat Platform
 * AI Analytics Dashboard (بند 18).
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class AiAnalyticsController extends Controller {

    /** @var AiAnalyticsService */
    private $analytics;

    public function __construct() {
        parent::__construct();
        $this->analytics = new AiAnalyticsService();
    }

    /**
     * GET /api/ai-chat/websites/{id}/analytics
     * Query: since (Y-m-d, اختياري - افتراضيًا آخر 30 يوم)
     */
    public function index(array $params = []): array {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $since = $this->get('since');
        if ($since && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            return $this->error('since must be in Y-m-d format', 422);
        }

        return $this->success([
            'dashboard' => $this->analytics->getDashboard((int) $website->getAttribute('id'), $since),
        ]);
    }

    /**
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
}
