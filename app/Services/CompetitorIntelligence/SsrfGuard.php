<?php
/**
 * Tourfecto - Competitor Intelligence: SSRF Guard
 * @version 1.0.0
 *
 * الموديول ده بيتعامل مع URLs خارجية أدخلها المستخدم (مواقع منافسين)
 * ويعمل عليها HTTP requests من السيرفر - ده بالتعريف سطح هجوم SSRF
 * كلاسيكي (URL يشاور على 127.0.0.1 / 169.254.169.254 / شبكة داخلية).
 *
 * نفس منطق الفحص الموجود بالفعل في WebsiteController::isPubliclyRoutableHost()
 * (لحماية فحص ملكية الموقع)، اتنقل هنا لكلاس مشترك قابل لإعادة الاستخدام
 * بدل تكراره، ومُوسَّع بفحص الـ scheme ومنع منافذ غير http/https.
 */
class SsrfGuard {
    /** @var string[] Metadata / link-local endpoints يجب منعها دائمًا حتى لو عدّت فحص الـ IP range العام */
    private const BLOCKED_HOSTS = [
        'metadata.google.internal',
        'localhost',
    ];

    /**
     * فحص شامل: هل الـ URL آمن نطلبه من السيرفر؟
     * @param string $url
     * @return array ['safe' => bool, 'reason' => string|null, 'host' => string|null]
     */
    public static function validateUrl(string $url): array {
        $url = trim($url);

        if ($url === '') {
            return ['safe' => false, 'reason' => 'empty_url', 'host' => null];
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return ['safe' => false, 'reason' => 'unparseable_url', 'host' => null];
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['safe' => false, 'reason' => 'unsupported_scheme', 'host' => $parts['host']];
        }

        $host = strtolower($parts['host']);

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return ['safe' => false, 'reason' => 'blocked_host', 'host' => $host];
        }

        if (!self::isPubliclyRoutableHost($host)) {
            return ['safe' => false, 'reason' => 'private_or_unresolvable_host', 'host' => $host];
        }

        // منع منافذ غير قياسية اللي غالبًا بتستخدم للوصول لخدمات داخلية
        // (مثال: 22 SSH، 3306 MySQL، 6379 Redis...) - نسمح فقط بالمنافذ
        // القياسية لـ HTTP/HTTPS أو من غير منفذ محدد أصلاً.
        if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443, 8080, 8443], true)) {
            return ['safe' => false, 'reason' => 'blocked_port', 'host' => $host];
        }

        return ['safe' => true, 'reason' => null, 'host' => $host];
    }

    public static function isSafe(string $url): bool {
        return self::validateUrl($url)['safe'];
    }

    /**
     * بنفس منطق WebsiteController::isPubliclyRoutableHost() - بيحل اسم
     * الدومين لـ IP فعلي ويتأكد إنه مش private/reserved/loopback range،
     * عشان محدش يقدر يستخدم "أضف منافس" كوسيلة يخلي السيرفر يطلب من
     * شبكته الداخلية أو Cloud metadata endpoint.
     */
    public static function isPubliclyRoutableHost(string $host): bool {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
        } else {
            $resolved = gethostbyname($host);
            if ($resolved === $host) {
                return false;
            }
            $ip = $resolved;
        }

        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * تطبيع URL لصفحة فرعية (مثال: إضافة /pricing) مع الحفاظ على نفس
     * الـ scheme/host الأساسي للمنافس، لمنع أي محاولة لتمرير URL كامل
     * مختلف (host مختلف) عبر باراميتر page_type.
     */
    public static function buildSubPageUrl(string $baseUrl, string $path): ?string {
        $base = parse_url($baseUrl);
        if (!$base || empty($base['host']) || empty($base['scheme'])) {
            return null;
        }
        $port = isset($base['port']) ? ':' . $base['port'] : '';
        return $base['scheme'] . '://' . $base['host'] . $port . '/' . ltrim($path, '/');
    }
}
