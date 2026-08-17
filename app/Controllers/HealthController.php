<?php

/**
 * Tourfecto - Health Controller
 * فحص حالة الموقع وقاعدة البيانات (Health Check)
 * @version 1.0.0
 */

class HealthController extends Controller
{
    /** GET /ping و GET /api/ping */
    public function ping(array $params = []): array
    {
        return $this->success(['pong' => true, 'time' => date('c')]);
    }

    /** GET /health */
    public function webCheck(array $params = []): array
    {
        return $this->check($params);
    }

    /** GET /api/health */
    public function check(array $params = []): array
    {
        $dbOk = false;
        try {
            $dbOk = $this->db->isConnected();
        } catch (Exception $e) {
            $dbOk = false;
        }

        $healthy = $dbOk;

        return [
            'success' => $healthy,
            'message' => $healthy ? 'الخدمة تعمل بشكل طبيعي' : 'هناك مشكلة في أحد الخدمات',
            'data' => [
                'database' => $dbOk ? 'ok' : 'down',
                'php_version' => phpversion(),
                'time' => date('c'),
            ],
            'code' => $healthy ? 200 : 503,
        ];
    }

    /** GET /api/health/detailed (يتطلب تسجيل دخول) */
    public function detailed(array $params = []): array
    {
        $dbOk = false;
        $dbStats = [];
        try {
            $dbOk = $this->db->isConnected();
            $dbStats = $this->db->getQueryStats();
        } catch (Exception $e) {
            $dbOk = false;
        }

        return $this->success([
            'database' => ['connected' => $dbOk, 'stats' => $dbStats],
            'memory_usage' => memory_get_usage(true),
            'php_version' => phpversion(),
            'app_env' => defined('APP_ENV') ? APP_ENV : 'unknown',
            'time' => date('c'),
        ]);
    }
}
