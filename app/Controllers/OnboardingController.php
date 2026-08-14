<?php
/**
 * Tourfecto - Onboarding Controller
 * Phase 16. الـWizard الموجود فعليًا كان Checklist تذكير بعد التسجيل بس
 * (5 خطوات على الـDashboard) - مش المعالج البنيوي اللي §23 بتطلبه (7 خطوات
 * بيانات + بدء تحليل تلقائي + "خطة نموك جاهزة"). الكنترولر ده بيعمل بالظبط
 * كده: بياخد كل بيانات الخطوات السبعة في نداء واحد، وبيربطهم فعليًا بكل
 * الـAgents الموجودة (مش بس بيحفظ بيانات فاضية):
 *
 *   بيانات الأعمال → إنشاء/تحديث Website (نفس نمط WebsiteController::store())
 *   → إضافة المنافسين فعليًا لنظام Competitor Intelligence (Phase 7)
 *   → تشغيل تدقيق SEO حقيقي تلقائيًا (Website Optimizer - Phase 5)
 *   → محاولة توليد خطة نمو 90 يوم (SEO Strategy Agent - Phase 14، اختياري
 *     - العميل ميتعطلش لو فشل، الأساسيات الأهم هي الموقع + الأودت)
 *   → "خطتك جاهزة"
 * @version 1.0.0
 */
class OnboardingController extends Controller {

    /**
     * POST /api/onboarding/complete
     * { business_name, main_url, industry, target_country, target_language?,
     *   target_customers, main_services, competitors: [{name, domain}, ...] (حتى 3) }
     */
    public function complete(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $userId = (int) $this->user['id'];

        if (!$this->validate(['main_url' => 'required', 'business_name' => 'required'])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        $competitorsInput = $this->get('competitors', []);
        if (!is_array($competitorsInput)) $competitorsInput = [];
        $competitorsInput = array_slice($competitorsInput, 0, 3);

        try {
            // ============ خطوات 1-6: إنشاء الموقع بكل بيانات الـWizard ============
            $website = new Website([
                'user_id' => $userId,
                'main_url' => $this->get('main_url'),
                'company_name' => $this->get('business_name'),
                'industry' => $this->get('industry', 'tourism'),
                'target_language' => $this->get('target_language', 'ar'),
                'target_country' => $this->get('target_country'),
                'target_customers' => $this->get('target_customers'),
                'main_services' => $this->get('main_services'),
                'competitor_1_url' => $competitorsInput[0]['domain'] ?? null,
                'competitor_2_url' => $competitorsInput[1]['domain'] ?? null,
                'competitor_3_url' => $competitorsInput[2]['domain'] ?? null,
                'is_verified' => 0,
                'onboarding_completed_at' => date('Y-m-d H:i:s'),
            ]);
            $websiteId = $website->save();
            if (!$websiteId) return $this->error('تعذر إنشاء الموقع', 500);

            // ============ خطوة 7: المنافسين - تسجيل حقيقي في نظام Competitor Intelligence (Phase 7) ============
            $addedCompetitors = [];
            if (class_exists('CompetitorAnalysisService')) {
                $competitorService = new CompetitorAnalysisService();
                foreach ($competitorsInput as $c) {
                    if (empty($c['domain'])) continue;
                    try {
                        $comp = $competitorService->addCompetitor($userId, $websiteId, (string) ($c['name'] ?? $c['domain']), (string) $c['domain']);
                        $addedCompetitors[] = $comp->toArray();
                    } catch (Exception $e) {
                        // منافس واحد فشل مايوقفش باقي الـWizard
                    }
                }
            }

            // ============ "ابدأ AI Audit" - تشغيل تدقيق SEO حقيقي تلقائيًا (Phase 5) ============
            $auditResult = null;
            if (class_exists('WebsiteOptimizerController')) {
                // WebsiteOptimizerController::runAudit() بيقرا website_id من
                // $this->get() اللي بيتحمل وقت الـconstructor من $_GET/$_POST -
                // بنحط القيمة قبل ما ننشئ الكائن عشان نفس آلية قراءة الإدخال
                // المستخدمة في المشروع كله (مفيش Dependency Injection framework هنا).
                $originalGet = $_GET;
                $_GET = ['website_id' => $websiteId];
                try {
                    $optimizer = new WebsiteOptimizerController();
                    $auditResult = $optimizer->runAudit([]);
                } catch (Exception $e) {
                    Logger::error('Onboarding Auto-Audit Error', ['message' => $e->getMessage()]);
                } finally {
                    $_GET = $originalGet;
                }
            }

            // ============ "خطتك جاهزة" - محاولة توليد خطة 90 يوم (Phase 14، اختياري) ============
            $strategyResult = null;
            if (($auditResult['success'] ?? false) && class_exists('SeoStrategyController')) {
                $originalGet2 = $_GET;
                $_GET = ['website_id' => $websiteId];
                try {
                    $strategyController = new SeoStrategyController();
                    $strategyGenResult = $strategyController->generate([]);
                    if ($strategyGenResult['success'] ?? false) {
                        $strategyResult = $strategyGenResult['data'];
                    }
                } catch (Exception $e) {
                    // خطة النمو "بونص" - فشلها (مثلاً رصيد AI منتهي) ميعطلش اكتمال الـOnboarding نفسه
                    Logger::error('Onboarding Auto-Strategy Error', ['message' => $e->getMessage()]);
                } finally {
                    $_GET = $originalGet2;
                }
            }

            $this->log('Onboarding Completed', ['website_id' => $websiteId, 'competitors_added' => count($addedCompetitors)]);

            return $this->success([
                'website' => (new Website())->find($websiteId)->toArray(),
                'competitors_added' => $addedCompetitors,
                'audit' => $auditResult['success'] ?? false ? $auditResult['data'] : null,
                'audit_error' => $auditResult['success'] ?? false ? null : ($auditResult['error'] ?? null),
                'growth_plan' => $strategyResult,
                'ready' => true,
            ], 'خطتك جاهزة! 🎉');

        } catch (Exception $e) {
            Logger::error('Onboarding Complete Error', ['message' => $e->getMessage()]);
            $debugMsg = (defined('APP_DEBUG') && APP_DEBUG) ? 'تعذر إكمال الإعداد: ' . $e->getMessage() : 'تعذر إكمال الإعداد';
            return $this->error($debugMsg, 500);
        }
    }

    /** GET /api/onboarding/status?website_id=X (اختياري - بدونه بيرجع حالة كل مواقع الحساب) */
    public function status(array $params = []): array {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);

        $websiteId = $this->get('website_id') ? (int) $this->get('website_id') : null;

        try {
            $sql = "SELECT id, main_url, company_name, onboarding_completed_at FROM websites WHERE user_id = ?";
            $bindings = [$this->user['id']];
            if ($websiteId) { $sql .= " AND id = ?"; $bindings[] = $websiteId; }
            $rows = $this->db->query($sql, $bindings);

            return $this->success([
                'websites' => array_map(fn($r) => [
                    'id' => (int) $r['id'],
                    'main_url' => $r['main_url'],
                    'company_name' => $r['company_name'],
                    'onboarding_completed' => $r['onboarding_completed_at'] !== null,
                ], $rows),
            ]);
        } catch (Exception $e) {
            Logger::error('Onboarding Status Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب حالة الإعداد', 500);
        }
    }
}
