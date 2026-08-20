<?php

/**
 * Tourfecto - SEO Proxy Controller (Server-Side Edge)
 * @version 1.0.0
 *
 * نقطة الدخول العامة (من غير AuthMiddleware) اللي بتخدم صفحة موقع خارجي
 * مربوط بالمنصة بعد إعادة كتابتها Server-Side. العميل بيشير DNS بتاعه
 * (CNAME) ناحية سيرفرنا، أو بيستخدم مسار الاختبار /s/{token}/...، والروبت
 * أو الزائر بيشوف النسخة المحسّنة مباشرة - مش مجرد حقن مؤقت بالـ embed.js.
 *
 * ده الجزء اللي بيحوّل "التنفيذ التلقائي" لشيء محركات البحث (جوجل/بينج/
 * ChatGPT/Perplexity) تشوفه فعليًا وقت الزحف، مش بس المتصفح.
 */
class SeoProxyController extends Controller
{
    /**
     * GET /s/{token}[/{path}...]  (عام - بدون AuthMiddleware)
     * بيسحب المسار الحقيقي من REQUEST_URI لأنه ممكن يكون متعدد المقاطع
     * (المسار بيوصلنا عبر Passthrough في index.php مش عبر Router عادي).
     */
    public function serve(array $params = []): void
    {
        $token = (string) ($params['token'] ?? '');
        if ($token === '' || !preg_match('/^emb_[a-f0-9]{24}$/', $token)) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Tourfecto proxy: invalid token';
            exit;
        }

        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $prefix = '/s/' . $token;
        $path = $requestPath === $prefix ? '/' : substr($requestPath, strlen($prefix));
        if ($path === '' || $path[0] !== '/') {
            $path = '/';
        }

        try {
            $service = new SeoProxyService($this->db);
            $result = $service->render($token, $path);
        } catch (Exception $e) {
            Logger::error('SeoProxy Serve Error', ['message' => $e->getMessage()]);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Tourfecto proxy: internal error';
            exit;
        }

        http_response_code($result['status']);
        header('Content-Type: ' . $result['content_type']);
        header('Cache-Control: public, max-age=60, s-maxage=300');
        echo $result['body'];
        exit;
    }

    /**
     * التحقق من إن الـ Host header الحالي بيطابق موقع مربوط (وضع CNAME).
     * بيُستخدم في index.php كبوابة قبل ما نسلم الرد للـ CNAME passthrough،
     * عشان منكسرش مسارات لوحة التحكم نفسها (هترجع false لـ host اللوحة).
     */
    public function shouldHandleCname(): bool
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return false;
        }
        try {
            $service = new SeoProxyService($this->db);
            return $service->findByHost($host) !== null;
        } catch (Exception $e) {
            Logger::error('SeoProxy CNAME Check Error', ['host' => $host, 'message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * CNAME Passthrough (بدون /s/{token}) - الطلب بيوصل هنا لما العميل يشير
     * DNS بتاعه ناحية سيرفرنا والـ Host header بيبقى دومينه هو.
     */
    public function serveCname(array $params = []): void
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        try {
            $service = new SeoProxyService($this->db);
            $site = $service->findByHost($host);
            if ($site === null) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Tourfecto proxy: host not connected';
                exit;
            }
            $result = $service->renderSite($site, $requestPath);
        } catch (Exception $e) {
            Logger::error('SeoProxy CNAME Error', ['host' => $host, 'message' => $e->getMessage()]);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Tourfecto proxy: internal error';
            exit;
        }

        http_response_code($result['status']);
        header('Content-Type: ' . $result['content_type']);
        header('Cache-Control: public, max-age=60, s-maxage=300');
        echo $result['body'];
        exit;
    }
}
