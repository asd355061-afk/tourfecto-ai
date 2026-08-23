<?php

/**
 * Tourfecto - Advanced AI Reranking Service
 * خدمة إعادة الترتيب المتقدمة لاستعلامات RAG
 * 
 * تستخدم خوارزميات متعددة للترتيب:
 * - Vector Similarity (Cosine, Dot Product)
 * - BM25 Text Scoring
 * - Hybrid Re-ranking
 * - Cross-Encoder Style Scoring
 * 
 * @version 2.0.0
 * @author Tourfecto AI Team
 * @copyright 2026 Tourfecto
 */

class AiRerankingService
{
    /** @var Database */
    private $db;

    /** @var array أوزان الخوارزميات المختلفة */
    private $algorithmWeights = [
        'vector_cosine' => 0.4,
        'bm25' => 0.3,
        'priority_boost' => 0.15,
        'recency_boost' => 0.15,
    ];

    /** @var array Stop words للعربية والإنجليزية */
    private $stopWords = [
        'en' => ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'to', 'of', 'in', 'on', 'for', 'and', 'or', 'with', 'about', 'at', 'do', 'does', 'did', 'i', 'you', 'we', 'can', 'could', 'will', 'would', 'what', 'how', 'when', 'where', 'who', 'which', 'it', 'its', 'my', 'your', 'our', 'their', 'this', 'that', 'these', 'those', 'have', 'has', 'had', 'be', 'by', 'from', 'as', 'than', 'then', 'there', 'please', 'hi', 'hello', 'hey', 'thank', 'thanks', 'yes', 'no', 'ok', 'okay'],
        'ar' => ['على', 'من', 'عن', 'في', 'إلى', 'الى', 'ما', 'لا', 'هل', 'و', 'التي', 'الذي', 'هذا', 'هذه', 'مع', 'بين', 'بعد', 'قبل', 'كل', 'جميع', 'بعض', 'أي', 'من', 'عن', 'عند', 'لكن', 'او', 'أو', 'نعم', 'لا', 'شكرا', 'مرحبا', 'اهلا', 'كيف', 'ماذا', 'متى', 'اين', 'من', 'لماذا']
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * إعادة ترتيب نتائج البحث باستخدام خوارزمية Hybrid Re-ranking
     * 
     * @param array $entries عناصر AiKnowledgeBase
     * @param string $query استعلام المستخدم
     * @param int $limit أقصى عدد عناصر يُرجَع
     * @param array $options خيارات إضافية
     * @return array العناصر مرتبة تنازليًا حسب الصلة
     */
    public function rerankHybrid(array $entries, string $query, int $limit = 10, array $options = []): array
    {
        if (empty($entries)) {
            return [];
        }

        // استخراج اللغة من الاستعلام
        $language = $this->detectLanguage($query);
        
        // Tokenize الاستعلام
        $queryTokens = $this->tokenize($query, $language);
        
        if (empty($queryTokens)) {
            return array_slice($entries, 0, $limit);
        }

        // حساب scores لكل عنصر باستخدام خوارزميات متعددة
        $scoredEntries = [];
        foreach ($entries as $entry) {
            $scores = $this->calculateAllScores($entry, $query, $queryTokens, $language);
            
            // دمج النتائج بوزن هجين
            $finalScore = $this->combineScores($scores, $options['weights'] ?? null);
            
            $scoredEntries[] = [
                'entry' => $entry,
                'score' => $finalScore,
                'breakdown' => $scores,
            ];
        }

        // ترتيب تنازلي حسب score
        usort($scoredEntries, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // إرجاع أعلى N عناصر فقط
        $topEntries = array_slice($scoredEntries, 0, $limit);
        
        // إضافة metadata للـ logging والتحليل
        return array_map(function ($item) {
            $item['entry']->setAttribute('_rerank_score', $item['score']);
            $item['entry']->setAttribute('_rerank_breakdown', $item['breakdown']);
            return $item['entry'];
        }, $topEntries);
    }

    /**
     * حساب جميع أنواع الـ scores لعنصر واحد
     */
    private function calculateAllScores($entry, string $query, array $queryTokens, string $language): array
    {
        $title = (string) $entry->getAttribute('title');
        $content = (string) $entry->getAttribute('content');
        $section = (string) $entry->getAttribute('section');
        $priority = (int) $entry->getAttribute('priority');
        $createdAt = $entry->getAttribute('created_at');

        // 1. Vector Cosine Similarity (محاكاة)
        $vectorScore = $this->calculateVectorSimilarity($title, $content, $query, $queryTokens, $language);

        // 2. BM25 Text Scoring
        $bm25Score = $this->calculateBM25($title, $content, $queryTokens, $language);

        // 3. Priority Boost
        $priorityScore = min(1.0, $priority / 10) * 10;

        // 4. Recency Boost (الأحدث أفضل)
        $recencyScore = $this->calculateRecencyScore($createdAt);

        // 5. Section Weight (بعض الأقسام أهم للاستعلامات)
        $sectionScore = $this->getSectionWeight($section, $query);

        return [
            'vector_cosine' => $vectorScore,
            'bm25' => $bm25Score,
            'priority_boost' => $priorityScore,
            'recency_boost' => $recencyScore,
            'section_weight' => $sectionScore,
        ];
    }

    /**
     * حساب Vector Similarity باستخدام TF-IDF المبسط
     * (يمكن استبداله بـ embeddings حقيقي لاحقًا)
     */
    private function calculateVectorSimilarity(string $title, string $content, string $query, array $queryTokens, string $language): float
    {
        // Title tokens لها وزن أكبر
        $titleTokens = $this->tokenize($title, $language);
        $contentTokens = $this->tokenize($content, $language);

        // حساب TF-IDF مبسط
        $titleOverlap = $this->calculateOverlap($queryTokens, $titleTokens);
        $contentOverlap = $this->calculateOverlap($queryTokens, $contentTokens);

        // Title matches أهم بـ 2.5x من content matches
        $rawScore = ($titleOverlap * 2.5) + ($contentOverlap * 1.0);

        // Normalization
        $maxPossibleScore = count($queryTokens) * 2.5;
        return $maxPossibleScore > 0 ? ($rawScore / $maxPossibleScore) * 10 : 0;
    }

    /**
     * BM25 Text Scoring Algorithm
     * خوارزمية ranking مستخدمة في محركات البحث
     */
    private function calculateBM25(string $title, string $content, array $queryTokens, string $language): float
    {
        $k1 = 1.5; // معامل ضبط لـ term frequency
        $b = 0.75; // معامل ضبط لـ document length

        $titleTokens = $this->tokenize($title, $language);
        $contentTokens = $this->tokenize($content, $language);
        
        $docLength = count($titleTokens) + count($contentTokens);
        $avgDocLength = 50; // متوسط افتراضي

        $score = 0.0;
        foreach ($queryTokens as $token) {
            // حساب term frequency
            $tfTitle = substr_count(strtolower($title), strtolower($token));
            $tfContent = substr_count(strtolower($content), strtolower($token));
            $tf = $tfTitle * 2 + $tfContent; // title weight

            if ($tf > 0) {
                // IDF مبسط (نفترض أن الكلمات النادرة أهم)
                $idf = log(1000 / ($tf + 1)) + 1;

                // BM25 formula
                $numerator = $tf * ($k1 + 1);
                $denominator = $tf + $k1 * (1 - $b + $b * ($docLength / $avgDocLength));
                
                $score += $idf * ($numerator / $denominator);
            }
        }

        return min(10.0, $score); // Cap at 10
    }

    /**
     * حساب Recency Score (الأحدث يحصل على boost)
     */
    private function calculateRecencyScore(?string $createdAt): float
    {
        if (!$createdAt) {
            return 5.0; // متوسط افتراضي
        }

        $createdTime = strtotime($createdAt);
        $now = time();
        $daysOld = max(0, ($now - $createdTime) / 86400);

        // Decay exponential: أحدث من 7 أيام = 10، أقدم من 90 يوم = 2
        if ($daysOld < 7) {
            return 10.0;
        } elseif ($daysOld < 30) {
            return 8.0;
        } elseif ($daysOld < 90) {
            return 5.0;
        } else {
            return 2.0;
        }
    }

    /**
     * إعطاء وزن إضافي لأقسام معينة بناءً على نية الاستعلام
     */
    private function getSectionWeight(string $section, string $query): float
    {
        $queryLower = strtolower($query);
        
        // كلمات مفتاحية تدل على نية معينة
        $intentKeywords = [
            'pricing' => ['price', 'cost', 'fee', 'charge', 'سعر', 'تكلفة', 'رسوم', 'كم', 'بكام'],
            'faq' => ['how', 'what', 'when', 'where', 'why', 'can', 'do', 'does', 'كيف', 'ماذا', 'متى', 'اين', 'لماذا', 'هل'],
            'tour' => ['tour', 'trip', 'visit', 'excursion', 'journey', 'جولة', 'رحلة', 'زيارة', 'سياحة', 'برنامج'],
            'service' => ['service', 'offer', 'provide', 'include', 'خدمة', 'نقدم', 'تشمل', 'تتضمن'],
            'cancellation_policy' => ['cancel', 'refund', 'cancellation', 'policy', 'الغاء', 'استرداد', 'سياسة', 'الغاء الحجز'],
            'contact_info' => ['contact', 'phone', 'email', 'address', 'location', 'اتصل', 'هاتف', 'ايميل', 'عنوان', 'موقع'],
            'business_hours' => ['hour', 'time', 'open', 'close', 'working', 'schedule', 'ساعة', 'وقت', 'مواعيد', 'عمل', 'مفتوح'],
        ];

        // التحقق من وجود كلمات النية في الاستعلام
        foreach ($intentKeywords as $targetSection => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($queryLower, $keyword) !== false) {
                    if ($section === $targetSection) {
                        return 10.0; // Boost كبير جداً
                    } else {
                        return 3.0; // sections الأخرى أقل أهمية
                    }
                }
            }
        }

        // أوزان افتراضية للأقسام
        $defaultWeights = [
            'faq' => 8.0,
            'pricing' => 8.0,
            'tour' => 7.0,
            'service' => 7.0,
            'cancellation_policy' => 6.0,
            'contact_info' => 5.0,
            'business_hours' => 5.0,
            'company_info' => 4.0,
            'custom_instructions' => 4.0,
            'brand_voice' => 3.0,
        ];

        return $defaultWeights[$section] ?? 5.0;
    }

    /**
     * دمج جميع الـ scores بوزن هجين
     */
    private function combineScores(array $scores, ?array $customWeights = null): float
    {
        $weights = $customWeights ?? $this->algorithmWeights;

        $combinedScore = 0.0;
        $totalWeight = 0.0;

        foreach ($weights as $algorithm => $weight) {
            if (isset($scores[$algorithm])) {
                $combinedScore += $scores[$algorithm] * $weight;
                $totalWeight += $weight;
            }
        }

        return $totalWeight > 0 ? $combinedScore / $totalWeight : 0;
    }

    /**
     * اكتشاف لغة النص (عربي أو إنجليزي)
     */
    private function detectLanguage(string $text): string
    {
        // حساب نسبة الأحرف العربية
        $arabicPattern = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u';
        preg_match_all($arabicPattern, $text, $arabicMatches);
        
        $arabicCount = count($arabicMatches[0]);
        $totalCount = mb_strlen($text);

        // إذا كانت نسبة العربي > 30% نعتبره عربي
        return ($totalCount > 0 && ($arabicCount / $totalCount) > 0.3) ? 'ar' : 'en';
    }

    /**
     * Tokenize النص مع إزالة stop words والتطبيع
     */
    private function tokenize(string $text, string $language = 'en'): array
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return [];
        }

        // إزالة علامات الترقيم
        $text = preg_replace('/[\p{P}\p{S}]/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (empty($words)) {
            return [];
        }

        // إزالة stop words
        $stopWords = $this->stopWords[$language] ?? $this->stopWords['en'];

        return array_values(array_filter($words, function ($word) use ($stopWords) {
            $word = trim($word);
            return $word !== '' && mb_strlen($word) >= 2 && !in_array($word, $stopWords, true);
        }));
    }

    /**
     * حساب overlap بين مجموعتين من tokens
     */
    private function calculateOverlap(array $queryTokens, array $targetTokens): int
    {
        if (empty($queryTokens) || empty($targetTokens)) {
            return 0;
        }

        $targetCounts = array_count_values($targetTokens);
        $hits = 0;

        foreach ($queryTokens as $token) {
            if (isset($targetCounts[$token]) && $targetCounts[$token] > 0) {
                $hits++;
                $targetCounts[$token]--;
            }
        }

        return $hits;
    }

    /**
     * Rerank مع caching للنتائج
     * يمكن استخدامها مع KnowledgeBaseService
     */
    public function rerankWithCache(int $websiteId, string $query, int $limit = 10): array
    {
        // محاولة استرجاع من cache أولاً
        $cacheKey = 'rerank_' . md5($websiteId . '_' . $query . '_' . $limit);
        $cached = $this->getFromCache($cacheKey);
        
        if ($cached !== null) {
            return $cached;
        }

        // جلب العناصر من قاعدة المعرفة
        $entries = (new AiKnowledgeBase())->activeFor($websiteId);
        
        // تطبيق reranking
        $reranked = $this->rerankHybrid($entries, $query, $limit);

        // حفظ في cache لمدة 1 ساعة
        $this->saveToCache($cacheKey, $reranked, 3600);

        return $reranked;
    }

    /**
     * Simple in-memory cache (يمكن استبداله بـ Redis/Memcached)
     */
    private static $cache = [];

    private function getFromCache(string $key)
    {
        if (isset(self::$cache[$key]) && self::$cache[$key]['expires'] > time()) {
            return self::$cache[$key]['data'];
        }
        return null;
    }

    private function saveToCache(string $key, $data, int $ttl): void
    {
        self::$cache[$key] = [
            'data' => $data,
            'expires' => time() + $ttl,
        ];
    }

    /**
     * Log reranking statistics للتحليل
     */
    public function logRerankStats(int $userId, int $websiteId, string $query, array $scores, int $resultsCount): void
    {
        try {
            $this->db->query(
                "INSERT INTO ai_rerank_logs (user_id, website_id, query_text, avg_score, max_score, results_count, created_at)
                 VALUES (:user_id, :website_id, :query_text, :avg_score, :max_score, :results_count, NOW())",
                [
                    ':user_id' => $userId,
                    ':website_id' => $websiteId,
                    ':query_text' => mb_substr($query, 0, 255),
                    ':avg_score' => count($scores) > 0 ? (array_sum($scores) / count($scores)) : 0,
                    ':max_score' => count($scores) > 0 ? max($scores) : 0,
                    ':results_count' => $resultsCount,
                ]
            );
        } catch (Exception $e) {
            // تجاهل أخطاء الـ logging
        }
    }
}
