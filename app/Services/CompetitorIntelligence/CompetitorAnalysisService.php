<?php

/**
 * Tourfecto - Competitor Analysis Service
 * يسدّ فجوة /ai/competitors و/ai/keywords الحقيقية (كانتا صفحتين
 * "قريبًا" فاضيتين). يعيد استخدام GeminiClient الموحّد لتحليل نصي
 * (مش سحب ترتيب فعلي من Google Search Console - ده يحتاج تكامل منفصل
 * لاحقًا، موضّح بوضوح في المخرجات).
 * @version 1.2.0 - Phase 7 (Competitor Intelligence): بقى بيعمل Crawl
 * حقيقي لصفحة كل موقع (مش بس بيقارن أسماء الدومينز) قبل ما يسأل الذكاء
 * الاصطناعي - يعني التوصيات دلوقتي مبنية على حقائق حقيقية من الصفحتين
 * (Title/Meta/H1/Schema/عدد الكلمات) مش تخمين. وبيحسب Competitor Score
 * حتمي (deterministic) مبني على نفس الحقائق دي - مش رقم من عند الذكاء
 * الاصطناعي ممكن يتغير كل مرة بنفس المدخلات.
 *
 * تصحيح (2026-07-14): جدول competitors كان موجودًا بالفعل بأعمدة
 * competitor_domain/competitor_name (تأكيد من تصدير phpMyAdmin حقيقي)،
 * تم تعديل كل الاستخدامات هنا لتطابق الأعمدة الحقيقية بدل name/url.
 */
class CompetitorAnalysisService
{
    /** @var mixed أي كائن عنده generateContent($prompt,$options):array بنفس شكل GeminiClient - عادة AIOrchestrator */
    private $ai;

    /**
     * Phase 7: الافتراضي بقى AIOrchestrator (Phase 3) بدل GeminiClient
     * مباشرة - عشان Failover التلقائي + Task Routing (task
     * 'competitor_analysis' كانت معرّفة من Phase 3 كـDeepSeek-first ومفيش
     * حد كان بينادي بيها لحد دلوقتي). لسه ممكن تحقن GeminiClient أو أي
     * كائن تاني بنفس الشكل يدويًا لو عايز (مثلاً في الاختبارات).
     */
    public function __construct($ai = null)
    {
        $this->ai = $ai ?? (class_exists('AIOrchestrator') ? new AIOrchestrator() : new GeminiClient());
    }

    public function addCompetitor(int $userId, int $websiteId, string $name, string $domain, string $notes = ''): Competitor
    {
        $competitor = new Competitor([
            'user_id' => $userId, 'website_id' => $websiteId,
            'competitor_name' => $name, 'competitor_domain' => $domain,
            'notes' => $notes ?: null, 'is_active' => 1,
        ]);
        $competitor->save();

        ActivityLog::record('seo', 'competitor.added', [
            'user_id' => $userId, 'subject_type' => 'competitors', 'subject_id' => (int) $competitor->getAttribute('id'),
        ]);

        return $competitor;
    }

    /**
     * تحليل مقارن حقيقي: بيعمل Crawl لصفحة كل موقع (بتاعي والمنافس)،
     * يحسب Competitor Score لكل واحد، وبعدين يسأل الذكاء الاصطناعي
     * يبني توصيات فعلية اعتمادًا على الحقائق المستخرجة فعليًا - مش أسماء
     * الدومينز بس زي ما كان قبل كده.
     */
    public function analyze(Competitor $competitor, string $myWebsiteDomain): array
    {
        $competitorDomain = $competitor->getAttribute('competitor_domain');
        $competitorName = $competitor->getAttribute('competitor_name') ?: $competitorDomain;

        $mySummary = $this->crawlDomainSummary($myWebsiteDomain);
        $competitorSummary = $this->crawlDomainSummary($competitorDomain);

        $myScore = $this->calculateOnPageScore($mySummary);
        $competitorScore = $this->calculateOnPageScore($competitorSummary);

        $prompt = $this->buildComparisonPrompt($myWebsiteDomain, $mySummary, $myScore, $competitorDomain, $competitorName, $competitorSummary, $competitorScore);

        $response = $this->ai->generateContent($prompt, [
            'maxOutputTokens' => 4096,
            'responseMimeType' => 'application/json',
            'task' => 'competitor_analysis',
        ]);

        if (!($response['success'] ?? false)) {
            throw new Exception($response['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي');
        }

        $raw = preg_replace('/^```(json)?|```$/m', '', trim((string) ($response['data'] ?? '')));
        $parsed = json_decode(trim($raw), true);

        if (!is_array($parsed) || empty($parsed['recommendations'])) {
            throw new Exception('تعذّر تحليل رد الذكاء الاصطناعي');
        }

        $saved = [];
        foreach ($parsed['recommendations'] as $rec) {
            $model = new CompetitorRecommendation([
                'competitor_id' => (int) $competitor->getAttribute('id'),
                'website_id' => (int) $competitor->getAttribute('website_id'),
                'recommendation' => (string) ($rec['text'] ?? ''),
                'priority' => in_array($rec['priority'] ?? '', ['low', 'medium', 'high'], true) ? $rec['priority'] : 'medium',
                'status' => 'open',
            ]);
            $model->save();
            $saved[] = $model;
        }

        // Phase 7: الأعمدة دي بقت موجودة فعليًا دلوقتي (Migration
        // 2026_08_08_000046) - نسجّل الدرجتين وتاريخ آخر تحليل حقيقي.
        try {
            $competitor->setAttribute('competitor_score', $competitorScore);
            $competitor->setAttribute('my_score', $myScore);
            $competitor->setAttribute('last_analyzed_at', date('Y-m-d H:i:s'));
            $competitor->save();
        } catch (Exception $e) {
            // Best-effort: لو الـMigration لسه ما اتطبقتش، منكسرش التحليل
            // نفسه عشان مشكلة في تسجيل الدرجة بس - التوصيات لسه اتحفظت.
        }

        return [
            'recommendations' => $saved,
            'my_score' => $myScore,
            'competitor_score' => $competitorScore,
            'my_summary' => $mySummary,
            'competitor_summary' => $competitorSummary,
        ];
    }

    /**
     * Crawl خفيف لصفحة رئيسية واحدة (مش تدقيق تقني كامل زي Website
     * Optimizer - غرض مختلف: لقطة سريعة كافية لمقارنة تنافسية، مش تشخيص
     * تقني شامل). بيرجع بيانات فاضية بأمان لو الموقع مش متاح، عشان
     * التحليل يكمل بدل ما يفشل بالكامل بسبب موقع منافس واحد مش راضي يرد.
     */
    public function crawlDomainSummary(string $domain): array
    {
        $url = preg_match('#^https?://#i', $domain) ? $domain : 'https://' . $domain;
        $html = $this->fetchHtml($url);

        if ($html === null) {
            return ['reachable' => false, 'title' => null, 'meta_description' => null, 'h1_count' => 0, 'word_count' => 0, 'has_schema' => false];
        }

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        $titleNode = $doc->getElementsByTagName('title')->item(0);
        $title = $titleNode ? trim($titleNode->textContent) : null;

        $metaDescription = null;
        foreach ($doc->getElementsByTagName('meta') as $meta) {
            if (strtolower((string) $meta->getAttribute('name')) === 'description') {
                $metaDescription = trim($meta->getAttribute('content'));
                break;
            }
        }

        $h1Count = $doc->getElementsByTagName('h1')->length;
        $bodyText = '';
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body) {
            $bodyText = preg_replace('/\s+/', ' ', trim($body->textContent));
        }
        $wordCount = $bodyText !== '' ? str_word_count($bodyText) : 0;

        $hasSchema = $xpath->query('//script[@type="application/ld+json"]')->length > 0;

        return [
            'reachable' => true,
            'title' => $title,
            'meta_description' => $metaDescription,
            'h1_count' => $h1Count,
            'word_count' => $wordCount,
            'has_schema' => $hasSchema,
        ];
    }

    /**
     * جلب HTML الصفحة - في method مستقل (بروتكتد) عشان الاختبارات تقدر
     * تعمل Override بسهولة بدون شبكة حقيقية.
     */
    protected function fetchHtml(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TourfectoBot/1.0; +https://tourfecto.com/bot)',
        ]);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($html !== false && $httpCode >= 200 && $httpCode < 400) ? $html : null;
    }

    /**
     * درجة حتمية 0-100 مبنية على وجود/غياب إشارات on-page SEO أساسية -
     * نفس المدخلات = نفس النتيجة دايمًا (عكس سؤال الذكاء الاصطناعي "اديني
     * رقم" اللي ممكن يختلف كل مرة بنفس البيانات).
     */
    private function calculateOnPageScore(array $summary): int
    {
        if (!$summary['reachable']) {
            return 0;
        }

        $score = 0;
        if (!empty($summary['title'])) {
            $score += 25;
        }
        if (!empty($summary['meta_description'])) {
            $score += 20;
        }
        if (($summary['h1_count'] ?? 0) === 1) {
            $score += 15;
        } // H1 واحد بالظبط هو الأفضل
        elseif (($summary['h1_count'] ?? 0) > 1) {
            $score += 5;
        } // أكتر من واحد أفضل من صفر بس مش مثالي
        if (($summary['word_count'] ?? 0) >= 300) {
            $score += 20;
        } elseif (($summary['word_count'] ?? 0) >= 100) {
            $score += 10;
        }
        if (!empty($summary['has_schema'])) {
            $score += 20;
        }

        return min(100, $score);
    }

    private function buildComparisonPrompt(string $myDomain, array $mySummary, int $myScore, string $competitorDomain, string $competitorName, array $competitorSummary, int $competitorScore): string
    {
        $fmt = fn (array $s) => sprintf(
            "Title: %s\nMeta Description: %s\nعدد H1: %d\nعدد الكلمات التقريبي: %d\nSchema markup: %s\nمتاح: %s",
            $s['title'] ?? '(مفقود)',
            $s['meta_description'] ?? '(مفقود)',
            $s['h1_count'] ?? 0,
            $s['word_count'] ?? 0,
            !empty($s['has_schema']) ? 'موجود' : 'مفقود',
            !empty($s['reachable']) ? 'نعم' : 'لا - تعذر الوصول للموقع'
        );

        return <<<PROMPT
أنت خبير SEO متخصص في قطاع السياحة. قارن بين هذين الموقعين بناءً على البيانات
الفعلية المستخرجة من الصفحة الرئيسية لكل منهما (مش افتراضات):

=== موقعي: {$myDomain} (Score: {$myScore}/100) ===
{$fmt($mySummary)}

=== المنافس: {$competitorDomain} ({$competitorName}) (Score: {$competitorScore}/100) ===
{$fmt($competitorSummary)}

بناءً على الفرق الفعلي بين الموقعين، رجّع JSON فقط بالشكل:
{"recommendations": [{"text":"توصية محددة قابلة للتنفيذ مبنية على الفجوة الفعلية أعلاه","priority":"high|medium|low"}]}
اكتب 4-6 توصيات كحد أقصى، وركّز على الحاجات اللي المنافس فعليًا أفضل فيها.
PROMPT;
    }
}
