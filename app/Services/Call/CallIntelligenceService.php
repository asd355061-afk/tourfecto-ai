<?php

namespace App\Services\Call;

/**
 * Call Intelligence Service
 * 
 * تحليل مكالمات هاتفية ذكي مع تفريغ نصي، تحليل مشاعر، واستخراج رؤى
 */
class CallIntelligenceService
{
    /**
     * @var array سجل المكالمات
     */
    private array $calls = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->loadCalls();
    }

    /**
     * تحميل المكالمات من التخزين
     */
    private function loadCalls(): void
    {
        $callsFile = '/workspace/storage/calls.json';
        
        if (file_exists($callsFile)) {
            $this->calls = json_decode(file_get_contents($callsFile), true) ?? [];
        }
    }

    /**
     * حفظ المكالمات
     */
    private function saveCalls(): void
    {
        $callsFile = '/workspace/storage/calls.json';
        file_put_contents($callsFile, json_encode($this->calls, JSON_PRETTY_PRINT));
    }

    /**
     * تسجيل مكالمة جديدة
     * 
     * @param array $callData بيانات المكالمة
     * @return array المكالمة المسجلة
     */
    public function recordCall(array $callData): array
    {
        $callId = $this->generateUniqueId('call_');
        
        $call = [
            'id' => $callId,
            'phone_number' => $callData['phone_number'] ?? '',
            'contact_id' => $callData['contact_id'] ?? null,
            'contact_name' => $callData['contact_name'] ?? 'Unknown',
            'direction' => $callData['direction'] ?? 'inbound', // inbound/outbound
            'duration_seconds' => $callData['duration_seconds'] ?? 0,
            'start_time' => $callData['start_time'] ?? date('Y-m-d H:i:s'),
            'end_time' => $callData['end_time'] ?? date('Y-m-d H:i:s'),
            'status' => $callData['status'] ?? 'completed', // completed, missed, voicemail
            'recording_url' => $callData['recording_url'] ?? null,
            'transcript' => null,
            'sentiment' => null,
            'topics' => [],
            'action_items' => [],
            'score' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $this->calls[] = $call;
        $this->saveCalls();

        return $call;
    }

    /**
     * تفريغ المكالمة نصياً (Speech-to-Text)
     * 
     * @param string $callId معرف المكالمة
     * @param string $audioData البيانات الصوتية (محاكاة)
     * @return array نتيجة التفريغ
     */
    public function transcribeCall(string $callId, string $audioData = null): array
    {
        $call = $this->findCall($callId);
        
        if (!$call) {
            return [
                'success' => false,
                'error' => 'Call not found',
            ];
        }

        // محاكاة عملية التفريغ النصي
        $transcript = $this->generateSimulatedTranscript($call);

        $call['transcript'] = $transcript;
        $call['transcribed_at'] = date('Y-m-d H:i:s');
        
        $this->updateCall($callId, $call);

        return [
            'success' => true,
            'call_id' => $callId,
            'transcript' => $transcript,
            'word_count' => str_word_count($transcript),
            'duration_seconds' => $call['duration_seconds'],
            'words_per_minute' => round(($transcript ? str_word_count($transcript) : 0) / ($call['duration_seconds'] / 60), 1),
            'language_detected' => 'en', // يمكن تغييره للعربية
            'transcribed_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * توليد تفريغ نصي محاكى
     */
    private function generateSimulatedTranscript(array $call): string
    {
        $templates = [
            "Hello, this is {$call['contact_name']}. I'm calling regarding my recent booking inquiry.",
            "Thank you for calling. How can I help you today?",
            "I'd like to know more about your tour packages for next month.",
            "Certainly! We have several options available. What destination are you interested in?",
            "I'm considering Egypt or Turkey for a 7-day trip.",
            "Excellent choices! Let me provide you with details about our most popular packages.",
            "What's the price range for these tours?",
            "Our Egypt package starts at $1200 per person, and Turkey from $950.",
            "That sounds reasonable. What's included in the package?",
            "The package includes flights, accommodation, daily breakfast, guided tours, and airport transfers.",
            "Do you offer any discounts for group bookings?",
            "Yes, we offer 10% off for groups of 5 or more people.",
            "Great! I'll discuss this with my family and get back to you.",
            "Perfect! I'll send you a detailed brochure via email. Is there anything else?",
            "No, that's all. Thank you for your help!",
            "You're welcome! Have a great day!",
        ];

        return implode(" ", $templates);
    }

    /**
     * تحليل المشاعر في المكالمة
     * 
     * @param string $callId معرف المكالمة
     * @return array نتيجة التحليل
     */
    public function analyzeSentiment(string $callId): array
    {
        $call = $this->findCall($callId);
        
        if (!$call || !$call['transcript']) {
            return [
                'success' => false,
                'error' => 'Call not found or no transcript available',
            ];
        }

        // محاكاة تحليل المشاعر
        $sentimentAnalysis = $this->performSentimentAnalysis($call['transcript']);

        $call['sentiment'] = $sentimentAnalysis;
        $this->updateCall($callId, $call);

        return [
            'success' => true,
            'call_id' => $callId,
            'sentiment' => $sentimentAnalysis,
            'analyzed_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * تنفيذ تحليل المشاعر
     */
    private function performSentimentAnalysis(string $transcript): array
    {
        // كلمات إيجابية وسلبية للتحليل
        $positiveWords = ['excellent', 'great', 'perfect', 'thank', 'wonderful', 'amazing', 'good', 'happy', 'love', 'best'];
        $negativeWords = ['bad', 'terrible', 'worst', 'angry', 'disappointed', 'problem', 'issue', 'complaint', 'poor', 'hate'];

        $words = str_word_count(strtolower($transcript), 1);
        
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
        $overallScore = ($positiveCount - $negativeCount) / max($total, 1);

        $sentimentLabel = 'neutral';
        if ($overallScore > 0.05) {
            $sentimentLabel = 'positive';
        } elseif ($overallScore < -0.05) {
            $sentimentLabel = 'negative';
        }

        return [
            'overall' => $sentimentLabel,
            'score' => round($overallScore, 3),
            'positive_score' => round($positiveCount / max($total, 1), 3),
            'negative_score' => round($negativeCount / max($total, 1), 3),
            'neutral_score' => round(1 - ($positiveCount + $negativeCount) / max($total, 1), 3),
            'positive_words_found' => $positiveCount,
            'negative_words_found' => $negativeCount,
            'confidence' => round(rand(70, 95) / 100, 2),
            'emotions' => [
                'joy' => round(rand(0, 50) / 100, 2),
                'trust' => round(rand(20, 70) / 100, 2),
                'anticipation' => round(rand(10, 40) / 100, 2),
                'surprise' => round(rand(0, 20) / 100, 2),
                'fear' => round(rand(0, 15) / 100, 2),
                'anger' => round(rand(0, 20) / 100, 2),
                'sadness' => round(rand(0, 15) / 100, 2),
                'disgust' => round(rand(0, 10) / 100, 2),
            ],
        ];
    }

    /**
     * استخراج المواضيع من المكالمة
     * 
     * @param string $callId معرف المكالمة
     * @return array المواضيع المستخرجة
     */
    public function extractTopics(string $callId): array
    {
        $call = $this->findCall($callId);
        
        if (!$call || !$call['transcript']) {
            return [
                'success' => false,
                'error' => 'Call not found or no transcript available',
            ];
        }

        // مواضيع شائعة في مكالمات السياحة
        $topicKeywords = [
            'pricing' => ['price', 'cost', 'discount', 'offer', 'deal', 'cheap', 'expensive', 'budget'],
            'booking' => ['book', 'reservation', 'confirm', 'availability', 'dates'],
            'destination' => ['egypt', 'turkey', 'dubai', 'paris', 'london', 'beach', 'mountain', 'city'],
            'accommodation' => ['hotel', 'resort', 'room', 'suite', 'accommodation', 'stay'],
            'transportation' => ['flight', 'airport', 'transfer', 'bus', 'car', 'transport'],
            'activities' => ['tour', 'sightseeing', 'activity', 'excursion', 'visit', 'explore'],
            'payment' => ['pay', 'payment', 'credit card', 'invoice', 'refund', 'deposit'],
            'customer_service' => ['help', 'support', 'question', 'problem', 'issue', 'complaint'],
        ];

        $transcript = strtolower($call['transcript']);
        $foundTopics = [];

        foreach ($topicKeywords as $topic => $keywords) {
            $matchCount = 0;
            foreach ($keywords as $keyword) {
                if (strpos($transcript, $keyword) !== false) {
                    $matchCount++;
                }
            }

            if ($matchCount > 0) {
                $foundTopics[] = [
                    'topic' => $topic,
                    'relevance_score' => round(min($matchCount / count($keywords), 1), 2),
                    'keywords_matched' => $matchCount,
                ];
            }
        }

        // ترتيب حسب الأهمية
        usort($foundTopics, function($a, $b) {
            return $b['relevance_score'] <=> $a['relevance_score'];
        });

        $call['topics'] = $foundTopics;
        $this->updateCall($callId, $call);

        return [
            'success' => true,
            'call_id' => $callId,
            'topics' => $foundTopics,
            'total_topics' => count($foundTopics),
            'primary_topic' => $foundTopics[0]['topic'] ?? null,
            'extracted_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * استخراج بنود العمل (Action Items)
     * 
     * @param string $callId معرف المكالمة
     * @return array بنود العمل
     */
    public function extractActionItems(string $callId): array
    {
        $call = $this->findCall($callId);
        
        if (!$call || !$call['transcript']) {
            return [
                'success' => false,
                'error' => 'Call not found or no transcript available',
            ];
        }

        // أنماط لاقتراح بنود العمل
        $actionPatterns = [
            'send_email' => ['send email', 'email you', 'send brochure', 'send details', 'forward information'],
            'follow_up' => ['call back', 'follow up', 'get back to you', 'contact again'],
            'schedule_meeting' => ['schedule', 'arrange meeting', 'set up call', 'appointment'],
            'send_quote' => ['send quote', 'send proposal', 'provide estimate', 'send pricing'],
            'process_payment' => ['process payment', 'take payment', 'charge card', 'make payment'],
            'update_booking' => ['update booking', 'modify reservation', 'change dates', 'cancel booking'],
        ];

        $transcript = strtolower($call['transcript']);
        $actionItems = [];

        foreach ($actionPatterns as $actionType => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($transcript, $pattern) !== false) {
                    $actionItems[] = [
                        'type' => $actionType,
                        'description' => ucfirst(str_replace('_', ' ', $actionType)),
                        'trigger_phrase' => $pattern,
                        'priority' => 'medium',
                        'status' => 'pending',
                        'due_date' => date('Y-m-d', strtotime('+1 day')),
                    ];
                    break;
                }
            }
        }

        $call['action_items'] = $actionItems;
        $this->updateCall($callId, $call);

        return [
            'success' => true,
            'call_id' => $callId,
            'action_items' => $actionItems,
            'total_items' => count($actionItems),
            'extracted_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * حساب درجة جودة المكالمة
     * 
     * @param string $callId معرف المكالمة
     * @return array نتيجة التقييم
     */
    public function scoreCall(string $callId): array
    {
        $call = $this->findCall($callId);
        
        if (!$call) {
            return [
                'success' => false,
                'error' => 'Call not found',
            ];
        }

        $score = 0;
        $factors = [];

        // عامل: مدة المكالمة (مثالي 3-10 دقائق)
        $durationMinutes = $call['duration_seconds'] / 60;
        if ($durationMinutes >= 3 && $durationMinutes <= 10) {
            $score += 25;
            $factors['duration'] = ['score' => 25, 'reason' => 'Optimal call duration'];
        } elseif ($durationMinutes >= 1 && $durationMinutes < 3) {
            $score += 15;
            $factors['duration'] = ['score' => 15, 'reason' => 'Short but acceptable'];
        } elseif ($durationMinutes > 10) {
            $score += 20;
            $factors['duration'] = ['score' => 20, 'reason' => 'Long call but thorough'];
        } else {
            $score += 5;
            $factors['duration'] = ['score' => 5, 'reason' => 'Too short'];
        }

        // عامل: وجود تفريغ نصي
        if ($call['transcript']) {
            $score += 15;
            $factors['transcript'] = ['score' => 15, 'reason' => 'Transcript available'];
        }

        // عامل: تحليل المشاعر
        if ($call['sentiment']) {
            if ($call['sentiment']['overall'] === 'positive') {
                $score += 25;
                $factors['sentiment'] = ['score' => 25, 'reason' => 'Positive sentiment'];
            } elseif ($call['sentiment']['overall'] === 'neutral') {
                $score += 15;
                $factors['sentiment'] = ['score' => 15, 'reason' => 'Neutral sentiment'];
            } else {
                $score += 5;
                $factors['sentiment'] = ['score' => 5, 'reason' => 'Negative sentiment detected'];
            }
        }

        // عامل: بنود عمل محددة
        if (!empty($call['action_items'])) {
            $score += 20;
            $factors['action_items'] = ['score' => 20, 'reason' => 'Clear action items identified'];
        }

        // عامل: مواضيع واضحة
        if (!empty($call['topics'])) {
            $score += 15;
            $factors['topics'] = ['score' => 15, 'reason' => 'Topics clearly identified'];
        }

        $call['score'] = $score;
        $this->updateCall($callId, $call);

        return [
            'success' => true,
            'call_id' => $callId,
            'overall_score' => $score,
            'grade' => $this->getGrade($score),
            'factors' => $factors,
            'max_score' => 100,
            'scored_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * الحصول على الدرجة الحرفية
     */
    private function getGrade(int $score): string
    {
        if ($score >= 90) return 'A+';
        if ($score >= 85) return 'A';
        if ($score >= 80) return 'A-';
        if ($score >= 75) return 'B+';
        if ($score >= 70) return 'B';
        if ($score >= 65) return 'B-';
        if ($score >= 60) return 'C+';
        if ($score >= 55) return 'C';
        if ($score >= 50) return 'C-';
        if ($score >= 40) return 'D';
        return 'F';
    }

    /**
     * تحليل شامل للمكالمة (جميع العمليات)
     * 
     * @param string $callId معرف المكالمة
     * @return array النتائج الشاملة
     */
    public function analyzeCall(string $callId): array
    {
        $results = [];

        // تفريغ نصي
        $transcriptResult = $this->transcribeCall($callId);
        $results['transcript'] = $transcriptResult;

        if (!$transcriptResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to transcribe call',
                'partial_results' => $results,
            ];
        }

        // تحليل المشاعر
        $sentimentResult = $this->analyzeSentiment($callId);
        $results['sentiment'] = $sentimentResult;

        // استخراج المواضيع
        $topicsResult = $this->extractTopics($callId);
        $results['topics'] = $topicsResult;

        // استخراج بنود العمل
        $actionsResult = $this->extractActionItems($callId);
        $results['action_items'] = $actionsResult;

        // تقييم الجودة
        $scoreResult = $this->scoreCall($callId);
        $results['score'] = $scoreResult;

        return [
            'success' => true,
            'call_id' => $callId,
            'analysis_complete' => true,
            'results' => $results,
            'summary' => [
                'has_transcript' => true,
                'sentiment' => $sentimentResult['sentiment']['overall'] ?? 'unknown',
                'primary_topic' => $topicsResult['primary_topic'] ?? null,
                'action_items_count' => count($actionsResult['action_items'] ?? []),
                'quality_score' => $scoreResult['overall_score'] ?? 0,
                'quality_grade' => $scoreResult['grade'] ?? 'F',
            ],
            'analyzed_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * البحث عن مكالمة
     */
    private function findCall(string $callId): ?array
    {
        foreach ($this->calls as $call) {
            if ($call['id'] === $callId) {
                return $call;
            }
        }
        return null;
    }

    /**
     * تحديث مكالمة
     */
    private function updateCall(string $callId, array $data): bool
    {
        foreach ($this->calls as $index => $call) {
            if ($call['id'] === $callId) {
                $this->calls[$index] = $data;
                $this->saveCalls();
                return true;
            }
        }
        return false;
    }

    /**
     * الحصول على جميع المكالمات
     */
    public function getCalls(array $filters = []): array
    {
        $filteredCalls = $this->calls;

        // تطبيق الفلاتر
        if (isset($filters['direction'])) {
            $filteredCalls = array_filter($filteredCalls, fn($c) => $c['direction'] === $filters['direction']);
        }

        if (isset($filters['status'])) {
            $filteredCalls = array_filter($filteredCalls, fn($c) => $c['status'] === $filters['status']);
        }

        if (isset($filters['sentiment'])) {
            $filteredCalls = array_filter($filteredCalls, fn($c) => 
                isset($c['sentiment']['overall']) && $c['sentiment']['overall'] === $filters['sentiment']
            );
        }

        if (isset($filters['min_score'])) {
            $filteredCalls = array_filter($filteredCalls, fn($c) => 
                isset($c['score']) && $c['score'] >= $filters['min_score']
            );
        }

        return array_values($filteredCalls);
    }

    /**
     * الحصول على إحصائيات المكالمات
     */
    public function getCallStatistics(): array
    {
        $totalCalls = count($this->calls);
        
        if ($totalCalls === 0) {
            return ['message' => 'No calls available'];
        }

        $inboundCalls = count(array_filter($this->calls, fn($c) => $c['direction'] === 'inbound'));
        $outboundCalls = count(array_filter($this->calls, fn($c) => $c['direction'] === 'outbound'));
        
        $completedCalls = count(array_filter($this->calls, fn($c) => $c['status'] === 'completed'));
        $missedCalls = count(array_filter($this->calls, fn($c) => $c['status'] === 'missed'));

        $avgDuration = array_sum(array_column($this->calls, 'duration_seconds')) / $totalCalls;

        $scores = array_filter(array_column($this->calls, 'score'));
        $avgScore = !empty($scores) ? array_sum($scores) / count($scores) : 0;

        $sentiments = [
            'positive' => count(array_filter($this->calls, fn($c) => 
                isset($c['sentiment']['overall']) && $c['sentiment']['overall'] === 'positive'
            )),
            'neutral' => count(array_filter($this->calls, fn($c) => 
                isset($c['sentiment']['overall']) && $c['sentiment']['overall'] === 'neutral'
            )),
            'negative' => count(array_filter($this->calls, fn($c) => 
                isset($c['sentiment']['overall']) && $c['sentiment']['overall'] === 'negative'
            )),
        ];

        return [
            'total_calls' => $totalCalls,
            'inbound_calls' => $inboundCalls,
            'outbound_calls' => $outboundCalls,
            'completed_calls' => $completedCalls,
            'missed_calls' => $missedCalls,
            'completion_rate' => round(($completedCalls / $totalCalls) * 100, 2),
            'average_duration_seconds' => round($avgDuration, 1),
            'average_duration_minutes' => round($avgDuration / 60, 2),
            'average_quality_score' => round($avgScore, 2),
            'sentiment_distribution' => $sentiments,
            'sentiment_positive_rate' => round(($sentiments['positive'] / $totalCalls) * 100, 2),
            'calls_with_transcript' => count(array_filter($this->calls, fn($c) => $c['transcript'] !== null)),
            'calls_analyzed' => count(array_filter($this->calls, fn($c) => $c['score'] !== null)),
        ];
    }

    /**
     * حذف مكالمة
     */
    public function deleteCall(string $callId): bool
    {
        foreach ($this->calls as $index => $call) {
            if ($call['id'] === $callId) {
                unset($this->calls[$index]);
                $this->calls = array_values($this->calls);
                $this->saveCalls();
                return true;
            }
        }
        return false;
    }

    /**
     * توليد معرف فريد
     */
    private function generateUniqueId(string $prefix = ''): string
    {
        return $prefix . bin2hex(random_bytes(8));
    }

    /**
     * تصدير المكالمات إلى CSV
     */
    public function exportCallsToCsv(): string
    {
        $csv = "ID,Phone,Contact,Direction,Duration,Status,Sentiment,Score,Date\n";
        
        foreach ($this->calls as $call) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%d,%s,%s,%d,%s\n",
                $call['id'],
                $call['phone_number'],
                $call['contact_name'],
                $call['direction'],
                $call['duration_seconds'],
                $call['status'],
                $call['sentiment']['overall'] ?? 'N/A',
                $call['score'] ?? 0,
                $call['start_time']
            );
        }

        return $csv;
    }
}
