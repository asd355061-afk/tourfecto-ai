<?php

/**
 * Tourfecto - SEO Server-Side Proxy (Edge Rewriting)
 * @version 1.0.0
 *
 * التنفيذ التلقائي الحقيقي Server-Side: بدل الاعتماد على embed.js اللي
 * بيشتغل في المتصفح بس (واللي روبوتات الزحف ممكن تتجاهله)، الخدمة دي
 * بتجيب صفحة الموقع الأصلي وتعيد كتابة الـ HTML على مستوى السيرفر قبل
 * ما ترجع للمستخدم/للروبوت. النتيجة: جوجل وChatGPT وكل الزوّار بيشوفوا
 * النسخة المحسّنة مباشرة - مش مجرد حقن مؤقت في المتصفح.
 *
 * ده نفس اللي بتعمله Cloudflare (HTML Rewriter) وSearchPilot (SEO A/B)،
 * بس معمول للمواقع الخارجية من غير ما العميل يلمس سيرفر الاستضافة.
 */
class SeoProxyService
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * عرض صفحة موقع مربوط بالمنصة بعد إعادة كتابتها Server-Side.
     *
     * @param string $embedToken توكن السكربت العام (من connectWebsite)
     * @param string $path المسار المطلوب (افتراضي '/')
     * @return array ['status'=>int, 'content_type'=>string, 'body'=>string]
     */
    public function render(string $embedToken, string $path = '/'): array
    {
        $sites = $this->db->query(
            "SELECT id, main_url, company_name, auto_pilot_mode, indexnow_key FROM websites
              WHERE embed_token = ? AND is_connected = 1 LIMIT 1",
            [$embedToken]
        );
        if (empty($sites)) {
            return ['status' => 404, 'content_type' => 'text/plain', 'body' => 'Tourfecto: site not connected'];
        }

        return $this->renderSite($sites[0], $path);
    }

    /**
     * خدمة صفحة/ملف لموقع معيّن بعد إعادة كتابته Server-Side.
     * بيُستخدم من render() (عبر embed_token) ومن وضع CNAME (عبر Host header).
     */
    public function renderSite(array $site, string $path = '/'): array
    {
        $origin = rtrim((string) $site['main_url'], '/');

        // ملف مفتاح IndexNow (بروتوكول IndexNow بيطلب وجود الملف على جذر
        // الدومين عشان يتحقق من ملكية المفتاح): بنخدمه Server-Side هنا.
        if (!empty($site['indexnow_key']) && $path === '/' . $site['indexnow_key'] . '.txt') {
            return ['status' => 200, 'content_type' => 'text/plain; charset=utf-8', 'body' => (string) $site['indexnow_key']];
        }

        // ملفات خاصة بيتم خدمتها Server-Side من غير ما نتعدى على الأصل
        if ($path === '/robots.txt' || $path === '/llms.txt' || $path === '/llms-full.txt' || $path === '/sitemap.xml') {
            return $this->serveAuxFile($site, $path, $origin);
        }

        $originUrl = $origin . $path;
        $fetched = $this->fetch($originUrl);
        if ($fetched['body'] === null) {
            return ['status' => $fetched['code'] ?: 502, 'content_type' => 'text/html', 'body' => 'Tourfecto proxy: origin unreachable (' . ($fetched['error'] ?: "HTTP {$fetched['code']}") . ')'];
        }

        $html = $this->rewrite($site['id'], (string) $fetched['body'], $originUrl, $_SERVER['HTTP_USER_AGENT'] ?? null);

        return ['status' => 200, 'content_type' => 'text/html; charset=utf-8', 'body' => $html];
    }

    /**
     * إعادة كتابة الـ HTML Server-Side بناء على الإصلاحات المعتمدة.
     *
     * @param string|null $userAgent لتوزيع نسخ تجارب SEO A/B بشكل حتمي
     */
    public function rewrite(int $websiteId, string $html, string $pageUrl, ?string $userAgent = null): string
    {
        $fixes = $this->db->query(
            "SELECT field_name, injected_code FROM auto_seo_applied_fixes
              WHERE website_id = ? AND is_active = 1",
            [$websiteId]
        );

        // SEO A/B Testing: أي تجربة نشطة على حقل معيّن بتاخد أولوية على
        // القيمة الثابتة من auto_seo_applied_fixes (توزيع حتمي لكل صفحة).
        $abService = new SeoAbTestService($this->db);

        foreach ($fixes as $fix) {
            $field = (string) $fix['field_name'];
            $code  = (string) $fix['injected_code'];

            $variant = $abService->pickVariant($websiteId, $field, $pageUrl, $userAgent);
            if ($variant !== null) {
                $code = $variant['value'];
            }

            $html = $this->applyRewrite($html, $field, $code, $pageUrl);
        }

        // علامة صغيرة بتوضح إن الصفحة معدّلة Server-Side (للتصحيح مش أكتر)
        if (stripos($html, 'tourfecto-seo-proxy') === false) {
            $html = str_ireplace(
                '</head>',
                "<!-- tourfecto-seo-proxy: optimized server-side -->\n</head>",
                $html
            );
        }

        return $html;
    }

    /**
     * البحث عن موقع مربوط من خلال الـ Host header (وضع CNAME الحقيقي).
     * العميل بيشير CNAME من subdomain بتاعه لسيرفرنا، فالطلب بيوصل من غير
     * مسار /s/{token} والـ Host header بيبقى دومينه هو.
     */
    public function findByHost(string $host): ?array
    {
        $host = strtolower(trim($host));
        if (($pos = strpos($host, ':')) !== false) {
            $host = substr($host, 0, $pos);
        }
        if ($host === '') {
            return null;
        }

        $sites = $this->db->query(
            "SELECT id, main_url, company_name, auto_pilot_mode, indexnow_key FROM websites WHERE is_connected = 1",
            []
        );
        foreach ($sites as $site) {
            $domain = parse_url((string) $site['main_url'], PHP_URL_HOST);
            if (is_string($domain) && strtolower($domain) === $host) {
                return $site;
            }
        }
        return null;
    }

    /** تطبيق إعادة كتابة واحدة على الـ HTML */
    private function applyRewrite(string $html, string $field, string $code, string $pageUrl): string
    {
        switch ($field) {
            case 'seo_title':
                $title = trim(strip_tags($code));
                if ($title !== '') {
                    if (preg_match('/<title[^>]*>.*?<\/title>/is', $html)) {
                        $html = preg_replace('/<title[^>]*>.*?<\/title>/is', '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>', $html, 1);
                    } else {
                        $html = str_ireplace('<head>', "<head>\n<title>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>', $html);
                    }
                }
                break;

            case 'seo_description':
                $desc = mb_substr(trim(strip_tags($code)), 0, 160);
                if ($desc !== '') {
                    $safe = htmlspecialchars($desc, ENT_QUOTES, 'UTF-8');
                    if (preg_match('/<meta\s+name=["\']description["\'][^>]*>/i', $html)) {
                        $html = preg_replace('/<meta\s+name=["\']description["\'][^>]*>/i', '<meta name="description" content="' . $safe . '">', $html, 1);
                    } else {
                        $html = str_ireplace('<head>', "<head>\n<meta name=\"description\" content=\"{$safe}\">", $html);
                    }
                }
                break;

            case 'canonical_url':
                $canonical = trim($code) !== '' ? trim($code) : $pageUrl;
                $safe = htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8');
                if (preg_match('/<link\s+[^>]*rel=["\']canonical["\'][^>]*>/i', $html)) {
                    $html = preg_replace('/<link\s+[^>]*rel=["\']canonical["\'][^>]*>/i', '<link rel="canonical" href="' . $safe . '">', $html, 1);
                } else {
                    $html = str_ireplace('<head>', "<head>\n<link rel=\"canonical\" href=\"{$safe}\">", $html);
                }
                break;

            case 'viewport':
                if (preg_match('/<meta\s+[^>]*name=["\']viewport["\'][^>]*>/i', $html)) {
                    $html = preg_replace('/<meta\s+[^>]*name=["\']viewport["\'][^>]*>/i', '<meta name="viewport" content="width=device-width, initial-scale=1">', $html, 1);
                } else {
                    $html = str_ireplace('<head>', '<head>' . "\n" . '<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
                }
                break;

            case 'robots_meta':
                if (preg_match('/<meta\s+[^>]*name=["\']robots["\'][^>]*>/i', $html)) {
                    $html = preg_replace('/<meta\s+[^>]*name=["\']robots["\'][^>]*>/i', '<meta name="robots" content="' . htmlspecialchars(trim(strip_tags($code)), ENT_QUOTES, 'UTF-8') . '">', $html, 1);
                } else {
                    $html = str_ireplace('<head>', '<head>' . "\n" . '<meta name="robots" content="' . htmlspecialchars(trim(strip_tags($code)), ENT_QUOTES, 'UTF-8') . '">', $html);
                }
                break;

            case 'json_ld':
            case 'faq_schema':
            case 'speakable':
                $json = $this->normalizeJsonLd($code);
                if ($json !== '') {
                    $block = "\n<script type=\"application/ld+json\">\n" . $json . "\n</script>\n";
                    $html = str_ireplace('<head>', "<head>{$block}", $html);
                }
                break;

            case 'og_tags':
                // code بيحتوي على وسوم <meta property="og:..."> جاهزة
                if (trim(strip_tags($code)) === trim($code) && trim($code) !== '') {
                    // نص عادي مش HTML - نتجاهل (مش تنسيق OG صحيح)
                    break;
                }
                $html = str_ireplace('<head>', "<head>\n" . $code . "\n", $html);
                break;

            case 'image_alt':
                // Server-side: إضافة alt للصور من غير alt (تقريبية)
                $html = preg_replace_callback('/<img\s+([^>]*?)>/i', function ($m) {
                    $tag = $m[0];
                    if (preg_match('/alt=/i', $tag)) {
                        return $tag;
                    }
                    return str_replace('<img', '<img alt=""', $tag);
                }, $html);
                break;

            default:
                break;
        }

        return $html;
    }

    /** تحويل الكود المحفوظ لـ JSON-LD نظيف */
    private function normalizeJsonLd(string $code): string
    {
        $code = trim($code);
        // لو الكود جاي كـ <script type="application/ld+json">...</script>
        if (preg_match('/<script[^>]*>([\s\S]*?)<\/script>/i', $code, $m)) {
            $code = trim($m[1]);
        }
        $decoded = json_decode($code, true);
        if ($decoded !== null) {
            return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        return $code;
    }

    /** خدمة ملفات robots.txt / llms.txt / sitemap من إصلاحاتنا (لو موجودة) */
    private function serveAuxFile(array $site, string $path, string $origin): array
    {
        $fieldMap = [
            '/robots.txt' => 'robots_txt',
            '/llms.txt' => 'llms_txt',
            '/sitemap.xml' => 'sitemap',
        ];
        $field = $fieldMap[$path] ?? null;

        if ($field !== null) {
            $rows = $this->db->query(
                "SELECT injected_code FROM auto_seo_applied_fixes
                  WHERE website_id = ? AND field_name = ? AND is_active = 1
                  ORDER BY id DESC LIMIT 1",
                [$site['id'], $field]
            );
            if (!empty($rows) && trim((string) $rows[0]['injected_code']) !== '') {
                $ct = $path === '/sitemap.xml' ? 'application/xml; charset=utf-8' : 'text/plain; charset=utf-8';
                return ['status' => 200, 'content_type' => $ct, 'body' => (string) $rows[0]['injected_code']];
            }
        }

        // لو مفيش نسخة محسّنة، نجيب الأصل
        $res = $this->fetch($origin . $path);
        if ($res['body'] !== null && $res['code'] < 400) {
            $ct = $path === '/sitemap.xml' ? 'application/xml; charset=utf-8' : 'text/plain; charset=utf-8';
            return ['status' => 200, 'content_type' => $ct, 'body' => (string) $res['body']];
        }

        return ['status' => 404, 'content_type' => 'text/plain', 'body' => 'Not found'];
    }

    /** جلب صفحة من السيرفر الأصلي */
    private function fetch(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => $_SERVER['HTTP_USER_AGENT'] ?? 'TourfectoSeoProxy/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return ['body' => $body === false ? null : $body, 'code' => $code, 'error' => $error];
    }
}
