<?php

/**
 * Tourfecto - Base Integration Service
 * @version 1.0.0
 *
 * أساس مشترك لكل خدمات الطرف الثالث (Algolia, Slack, Zapier, HubSpot,
 * Zoom, Mixpanel, OneSignal, Calendly). بيوفّر helper موحّد لطلبات HTTP
 * (JSON) وتسجيل الأخطاء، بحيث كل Service يركّز على منطق الـ API الخاص به.
 *
 * كل Service بيعمل implement لـ IntegrationInterface عشان يشتغل مع
 * IntegrationManager لو احتجناه لاحقًا، لكنه كمان قابل للاستخدام مباشرة.
 */

abstract class BaseIntegrationService implements IntegrationInterface
{
    /** الاسم الفريد للمنصة */
    abstract public function key(): string;

    /** هل الإعدادات الأساسية موجودة في .env؟ */
    abstract public function isConfigured(): bool;

    public function authType(): string
    {
        return 'api_key';
    }

    /**
     * تنفيذ طلب HTTP عام (JSON) - يدعم GET/POST/PUT/DELETE.
     * @return array ['success'=>bool, 'data'=>mixed, 'error'=>?string, 'http_code'=>int]
     */
    protected function httpJson(string $method, string $url, array $headers = [], ?array $body = null): array
    {
        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];

        if ($body !== null) {
            $curlOpts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, $curlOpts);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            $this->log('error', "cURL error [{$this->key()}]: {$curlError}");
            return ['success' => false, 'data' => null, 'error' => $curlError, 'http_code' => 0];
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            $this->log('warning', "API error [{$this->key()}] HTTP {$httpCode}", ['response' => $response]);
            return ['success' => false, 'data' => $decoded, 'error' => "HTTP {$httpCode}", 'http_code' => $httpCode];
        }

        return ['success' => true, 'data' => $decoded, 'error' => null, 'http_code' => $httpCode];
    }

    protected function httpForm(string $method, string $url, array $headers = [], array $fields = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            $this->log('error', "cURL error [{$this->key()}]: {$curlError}");
            return ['success' => false, 'data' => null, 'error' => $curlError, 'http_code' => 0];
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            $this->log('warning', "API error [{$this->key()}] HTTP {$httpCode}", ['response' => $response]);
            return ['success' => false, 'data' => $decoded, 'error' => "HTTP {$httpCode}", 'http_code' => $httpCode];
        }

        return ['success' => true, 'data' => $decoded, 'error' => null, 'http_code' => $httpCode];
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        if (class_exists('Logger')) {
            try {
                Logger::{$level}($message, $context);
            } catch (Throwable $e) {
                error_log("[integrations][{$level}] {$message}");
            }
        } else {
            error_log("[integrations][{$level}] {$message}");
        }
    }

    /** ربط متغير بيئة بأمان (ثابت أو env) */
    protected function conf(string $const, string $envKey): string
    {
        if (defined($const)) {
            return (string) constant($const);
        }
        return (string) (env($envKey) ?: '');
    }
}
