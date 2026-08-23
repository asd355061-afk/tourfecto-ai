<?php

namespace App\Services\Social;

/**
 * Social Listening Service
 * 
 * مراقبة أحاديث السوشيال ميديا وتحليل المشاعر واكتشاف الاتجاهات
 */
class SocialListeningService
{
    /**
     * @var array المنصات المدعومة
     */
    private array $platforms = [
        'twitter' => ['name' => 'Twitter/X', 'enabled' => true],
        'facebook' => ['name' => 'Facebook', 'enabled' => true],
        'instagram' => ['name' => 'Instagram', 'enabled' => true],
        'linkedin' => ['name' => 'LinkedIn', 'enabled' => true],
        'tiktok' => ['name' => 'TikTok', 'enabled' => true],
        'youtube' => ['name' => 'YouTube', 'enabled' => true],
        'reddit' => ['name' => 'Reddit', 'enabled' => true],
    ];

    /**
     * @var array كلمات المراقبة
     */
    private array $monitoringKeywords = [];

    /**
     * @var array المنشورات المكتشفة
     */
    private array $discoveredPosts = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->loadMonitoringData();
    }

    /**
     * تحميل بيانات المراقبة
     */
    private function loadMonitoringData(): void
    {
        $dataFile = '/workspace/storage/social_listening.json';
        
        if (file_exists($dataFile)) {
            $data = json_decode(file_get_contents($dataFile), true) ?? [];
            $this->monitoringKeywords = $data['keywords'] ?? [];
            $this->discoveredPosts = $data['posts'] ?? [];
        } else {
            // كلمات مراقبة افتراضية
            $this->monitoringKeywords = [
                ['keyword' => 'tourism', 'category' => 'industry'],
                ['keyword' => 'travel', 'category' => 'industry'],
                ['keyword' => 'vacation', 'category' => 'industry'],
                ['keyword' => 'hotel', 'category' => 'accommodation'],
                ['keyword' => 'booking', 'category' => 'service'],
                ['keyword' => 'tour package', 'category' => 'product'],
            ];
        }
    }

    /**
     * حفظ بيانات المراقبة
     */
    private function saveMonitoringData(): void
    {
        $dataFile = '/workspace/storage/social_listening.json';
        file_put_contents($dataFile, json_encode([
            'keywords' => $this->monitoringKeywords,
            'posts' => $this->discoveredPosts,
        ], JSON_PRETTY_PRINT));
    }

    /**
     * إضافة كلمة للمراقبة
     */
    public function addKeyword(string $keyword, string $category = 'general'): bool
    {
        $this->monitoringKeywords[] = [
            'keyword' => $keyword,
            'category' => $category,
            'added_at' => date('Y-m-d H:i:s'),
        ];
        
        $this->saveMonitoringData();
        return true;
    }

    /**
     * الحصول على كلمات المراقبة
     */
    public function getKeywords(): array
    {
        return $this->monitoringKeywords;
    }

    /**
     * البحث عن منشورات بكلمة معينة
     * 
     * @param string $keyword الكلمة المطلوبة
     * @param array $platforms المنصات للبحث فيها
     * @return array المنشورات المكتشفة
     */
    public function searchPosts(string $keyword, array $platforms = []): array
    {
        if (empty($platforms)) {
            $platforms = array_keys(array_filter($this->platforms, fn($p) => $p['enabled']));
        }

        $results = [];

        foreach ($platforms as $platform) {
            if (!isset($this->platforms[$platform])) {
                continue;
            }

            // محاكاة البحث في كل منصة
            $platformPosts = $this->simulatePlatformSearch($platform, $keyword);
            $results = array_merge($results, $platformPosts);
        }

        // حفظ النتائج
        foreach ($results as $post) {
            $this->discoveredPosts[] = $post;
        }
        $this->saveMonitoringData();

        return $results;
    }

    /**
     * محاكاة البحث في منصة
     */
    private function simulatePlatformSearch(string $platform, string $keyword): array
    {
        $posts = [];
        $postCount = rand(5, 20);

        $sampleContents = [
            "Just booked an amazing trip! #travel #{$keyword}",
            "Looking for recommendations for {$keyword} - any suggestions?",
            "Best {$keyword} experience ever! Highly recommend 👍",
            "Disappointed with my recent {$keyword} booking. Not worth the price.",
            "Planning my next vacation around {$keyword}. So excited!",
            "Top 10 {$keyword} destinations you must visit in 2024",
            "Great deals on {$keyword} packages right now!",
            "Warning: Avoid this {$keyword} provider. Terrible service.",
            "Amazing photos from my {$keyword} trip! Check them out 📸",
            "Question: What's the best time to book {$keyword}?",
        ];

        for ($i = 0; $i < $postCount; $i++) {
            $sentimentScore = rand(-100, 100) / 100;
            $sentimentLabel = $sentimentScore > 0.2 ? 'positive' : ($sentimentScore < -0.2 ? 'negative' : 'neutral');

            $posts[] = [
                'id' => $platform . '_' . bin2hex(random_bytes(8)),
                'platform' => $platform,
                'content' => $sampleContents[array_rand($sampleContents)],
                'author' => 'user_' . rand(1000, 9999),
                'author_followers' => rand(100, 50000),
                'posted_at' => date('Y-m-d H:i:s', strtotime('-' . rand(0, 30) . ' days')),
                'engagement' => [
                    'likes' => rand(0, 5000),
                    'shares' => rand(0, 500),
                    'comments' => rand(0, 200),
                ],
                'sentiment' => [
                    'label' => $sentimentLabel,
                    'score' => round($sentimentScore, 2),
                    'confidence' => round(rand(60, 95) / 100, 2),
                ],
                'mentions_keyword' => true,
                'language' => rand(0, 10) > 3 ? 'en' : 'ar', // 70% English, 30% Arabic
                'is_influencer' => rand(0, 100) > 90, // 10% influencers
            ];
        }

        return $posts;
    }

    /**
     * تحليل المشاعر لمنشور معين
     */
    public function analyzePostSentiment(string $content): array
    {
        $positiveWords = ['amazing', 'great', 'best', 'love', 'excellent', 'wonderful', 'fantastic', 'awesome', 'perfect', 'recommend'];
        $negativeWords = ['terrible', 'worst', 'hate', 'bad', 'awful', 'disappointing', 'poor', 'avoid', 'warning', 'horrible'];

        $words = str_word_count(strtolower($content), 1);
        
        $positiveCount = 0;
        $negativeCount = 0;

        foreach ($words as $word) {
            if (in_array($word, $positiveWords)) {
                $positiveCount++;
            } elseif (in_array($word, $negativeWords)) {
                $negativeCount++;
            }
        }

        $total = count($words);
        $score = ($positiveCount - $negativeCount) / max($total, 1);

        $label = 'neutral';
        if ($score > 0.1) {
            $label = 'positive';
        } elseif ($score < -0.1) {
            $label = 'negative';
        }

        return [
            'label' => $label,
            'score' => round($score, 2),
            'positive_words' => $positiveCount,
            'negative_words' => $negativeCount,
            'confidence' => round(min(abs($score) * 2 + 0.5, 0.95), 2),
        ];
    }

    /**
     * اكتشاف المؤثرين (Influencers)
     */
    public function discoverInfluencers(string $topic, int $minFollowers = 10000): array
    {
        $posts = $this->searchPosts($topic);
        
        $influencers = [];
        $authorStats = [];

        foreach ($posts as $post) {
            if ($post['author_followers'] >= $minFollowers) {
                $author = $post['author'];
                
                if (!isset($authorStats[$author])) {
                    $authorStats[$author] = [
                        'author' => $author,
                        'platform' => $post['platform'],
                        'followers' => $post['author_followers'],
                        'posts_count' => 0,
                        'total_engagement' => 0,
                        'avg_sentiment' => 0,
                        'topics' => [],
                    ];
                }

                $authorStats[$author]['posts_count']++;
                $authorStats[$author]['total_engagement'] += 
                    $post['engagement']['likes'] + 
                    $post['engagement']['shares'] + 
                    $post['engagement']['comments'];
                $authorStats[$author]['topics'][] = $topic;
            }
        }

        // حساب المتوسطات والترتيب
        foreach ($authorStats as &$influencer) {
            $influencer['avg_engagement'] = round($influencer['total_engagement'] / $influencer['posts_count'], 1);
            $influencer['influence_score'] = round(
                ($influencer['followers'] * 0.4 + $influencer['avg_engagement'] * 0.6) / 1000, 
                2
            );
            $influencer['topics'] = array_unique($influencer['topics']);
        }

        // ترتيب حسب درجة التأثير
        usort($influencers, function($a, $b) {
            return $b['influence_score'] <=> $a['influence_score'];
        });

        return array_values($influencers);
    }

    /**
     * تتبع الاتجاهات (Trends)
     */
    public function trackTrends(string $category = 'all'): array
    {
        $trendingTopics = [];

        // مواضيع شائعة في السياحة
        $potentialTrends = [
            ['topic' => 'sustainable tourism', 'velocity' => rand(50, 200)],
            ['topic' => 'digital nomad', 'velocity' => rand(30, 150)],
            ['topic' => 'staycation', 'velocity' => rand(40, 180)],
            ['topic' => 'adventure travel', 'velocity' => rand(60, 220)],
            ['topic' => 'wellness retreat', 'velocity' => rand(35, 160)],
            ['topic' => 'budget travel', 'velocity' => rand(45, 190)],
            ['topic' => 'luxury escapes', 'velocity' => rand(25, 140)],
            ['topic' => 'cultural immersion', 'velocity' => rand(30, 170)],
            ['topic' => 'solo travel', 'velocity' => rand(55, 200)],
            ['topic' => 'eco-friendly hotels', 'velocity' => rand(40, 185)],
        ];

        // تصفية حسب الفئة
        if ($category !== 'all') {
            $categoryFilters = [
                'budget' => ['budget travel', 'staycation'],
                'luxury' => ['luxury escapes', 'wellness retreat'],
                'adventure' => ['adventure travel', 'solo travel'],
                'sustainable' => ['sustainable tourism', 'eco-friendly hotels'],
                'lifestyle' => ['digital nomad', 'cultural immersion'],
            ];

            $allowedTopics = $categoryFilters[$category] ?? [];
            $potentialTrends = array_filter($potentialTrends, fn($t) => in_array($t['topic'], $allowedTopics));
        }

        // ترتيب حسب السرعة
        usort($potentialTrends, function($a, $b) {
            return $b['velocity'] <=> $a['velocity'];
        });

        // إضافة معلومات إضافية
        foreach ($potentialTrends as &$trend) {
            $trend['rank'] = array_search($trend, $potentialTrends) + 1;
            $trend['volume_24h'] = rand(1000, 50000);
            $trend['growth_percentage'] = round($trend['velocity'] / 2, 1);
            $trend['related_keywords' => $this->getRelatedKeywords($trend['topic']),
        }

        return [
            'category' => $category,
            'trends' => array_values($potentialTrends),
            'total_trends' => count($potentialTrends),
            'tracked_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * الحصول على كلمات ذات صلة
     */
    private function getRelatedKeywords(string $topic): array
    {
        $relatedMap = [
            'sustainable tourism' => ['eco-tourism', 'green travel', 'responsible tourism', 'carbon offset'],
            'digital nomad' => ['remote work', 'work from anywhere', 'coworking', 'nomad life'],
            'staycation' => ['local travel', 'home vacation', 'nearby getaway', 'city break'],
            'adventure travel' => ['extreme sports', 'hiking', 'backpacking', 'outdoor activities'],
            'wellness retreat' => ['spa', 'meditation', 'yoga', 'detox', 'mindfulness'],
            'budget travel' => ['cheap flights', 'hostels', 'travel hacks', 'discount deals'],
            'luxury escapes' => ['five-star', 'premium', 'exclusive', 'vip experience'],
            'cultural immersion' => ['local experiences', 'traditional', 'authentic', 'heritage'],
            'solo travel' => ['single traveler', 'independent', 'self-discovery', 'freedom'],
            'eco-friendly hotels' => ['green hotels', 'sustainable accommodation', 'eco-resort'],
        ];

        return $relatedMap[$topic] ?? ['travel', 'tourism', 'vacation'];
    }

    /**
     * إنشاء تقرير المراقبة
     */
    public function generateReport(string $period = '7days'): array
    {
        $startDate = date('Y-m-d', strtotime('-' . intval($period) . ' days'));
        
        // تجميع البيانات
        $postsInPeriod = array_filter($this->discoveredPosts, function($post) use ($startDate) {
            return strtotime($post['posted_at']) >= strtotime($startDate);
        });

        $totalPosts = count($postsInPeriod);
        
        if ($totalPosts === 0) {
            return ['message' => 'No data available for this period'];
        }

        // إحصائيات المشاعر
        $sentimentCounts = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        foreach ($postsInPeriod as $post) {
            $sentiment = $post['sentiment']['label'] ?? 'neutral';
            $sentimentCounts[$sentiment]++;
        }

        // إحصائيات المنصات
        $platformCounts = [];
        foreach ($postsInPeriod as $post) {
            $platform = $post['platform'];
            $platformCounts[$platform] = ($platformCounts[$platform] ?? 0) + 1;
        }

        // إجمالي التفاعل
        $totalEngagement = array_sum(array_map(function($post) {
            return $post['engagement']['likes'] + 
                   $post['engagement']['shares'] + 
                   $post['engagement']['comments'];
        }, $postsInPeriod));

        return [
            'period' => $period,
            'start_date' => $startDate,
            'end_date' => date('Y-m-d H:i:s'),
            'summary' => [
                'total_mentions' => $totalPosts,
                'sentiment_distribution' => $sentimentCounts,
                'positive_percentage' => round(($sentimentCounts['positive'] / $totalPosts) * 100, 2),
                'neutral_percentage' => round(($sentimentCounts['neutral'] / $totalPosts) * 100, 2),
                'negative_percentage' => round(($sentimentCounts['negative'] / $totalPosts) * 100, 2),
                'total_engagement' => $totalEngagement,
                'avg_engagement_per_post' => round($totalEngagement / $totalPosts, 1),
                'platforms_breakdown' => $platformCounts,
                'top_platform' => !empty($platformCounts) ? array_key_first($platformCounts) : null,
            ],
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * تنبيه عند ذكر سلبي كبير
     */
    public function checkForCrisis(int $threshold = 10): array
    {
        $recentPosts = array_slice($this->discoveredPosts, -100); // آخر 100 منشور
        
        $negativePosts = array_filter($recentPosts, function($post) {
            return ($post['sentiment']['label'] ?? '') === 'negative';
        });

        $negativeCount = count($negativePosts);
        
        $crisisDetected = $negativeCount >= $threshold;

        return [
            'crisis_detected' => $crisisDetected,
            'negative_mentions_count' => $negativeCount,
            'threshold' => $threshold,
            'severity' => $crisisDetected ? ($negativeCount >= $threshold * 2 ? 'critical' : 'high') : 'normal',
            'recommended_actions' => $crisisDetected ? [
                'Activate crisis communication plan',
                'Monitor mentions hourly',
                'Prepare official statement',
                'Engage PR team',
                'Respond to key negative posts',
            ] : [
                'Continue regular monitoring',
                'Address individual concerns',
            ],
            'checked_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * الحصول على المنصات المدعومة
     */
    public function getPlatforms(): array
    {
        return $this->platforms;
    }

    /**
     * تمكين/تعطيل منصة
     */
    public function togglePlatform(string $platform, bool $enabled): bool
    {
        if (!isset($this->platforms[$platform])) {
            return false;
        }

        $this->platforms[$platform]['enabled'] = $enabled;
        return true;
    }

    /**
     * مسح البيانات المكتشفة
     */
    public function clearDiscoveredPosts(): bool
    {
        $this->discoveredPosts = [];
        $this->saveMonitoringData();
        return true;
    }
}
