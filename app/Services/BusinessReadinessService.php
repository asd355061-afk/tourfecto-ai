<?php

/**
 * Tourfecto - Business Readiness Service
 * Business Control Center - AI Audit scoring (Competitive phase 2026-08-15)
 * @version 1.0.0
 *
 * الهدف: نقطة القوة التنافسية المعادلة لـ SOCi Local Visibility Index
 * و Birdeye Brand Grader - درجة رقمية (0-100) بتقول للمستخدم "بيزنس
 * بروفايلك مكتمل قد إيه" مع تفصيل لكل فئة وترتيب الأولويات (Next Steps).
 *
 * التصميم مكون من طبقتين:
 * 1) scoreFromContext(array $context): المنطق الخالص (pure logic) اللي
 *    بياخد ناتج BusinessContextService::getContext() (نفس الـ Single
 *    Source of Truth - مفيش أي استعلام DB هنا إطلاقًا). الطبقة دي بتخلى
 *    الاختبارات تشتغل offline من غير DB (نفس نمط اختبارات SsrfGuard).
 * 2) score(int $businessId): wrapper رفيع بيجيب الـContext من الكاش/DB
 *    وبيمرره للطبقة الأولى - ده اللي بيستخدمه الـController.
 *
 * ملاحظة ملكية: زي BusinessContextService، الـService هنا مش بيفحص
 * Authorization - الـController اللي بينادي score() هو المسؤول إنه يتأكد
 * إن الـbusinessId يخص المستخدم الحالي قبل النداء.
 */
class BusinessReadinessService
{
    /**
     * أوزان الفئات السبع - مجموعها 100. قابلة للتغيير مستقبلًا (قابلة
     * للتخصيص لكل باقة/عميل) لكنها بتفضل ثابتة دلوقتي عشان الاستقرار.
     * الأوزان بتعكس أثر كل فئة على جودة مخرجات الـAI: بيانات الهوية
     * والسياق الـAI هما الأهم، ثم التواصل والمواقع، ثم الخدمات والأسواق
     * والهوية البصرية.
     */
    private const CATEGORY_WEIGHTS = [
        'identity'      => 20,
        'ai_context'    => 20,
        'contact'       => 15,
        'locations'     => 15,
        'services'      => 10,
        'target_markets' => 10,
        'brand'         => 10,
    ];

    /**
     * درجة الجاهزية الكاملة للـBusiness - دي اللي بيستخدمها الـController.
     */
    public function score(int $businessId): array
    {
        $context = (new BusinessContextService())->getContext($businessId);
        return $this->scoreFromContext($context);
    }

    /**
     * المنطق الخالص للتقييم - بيفهم ناتج getContext() بس، ومفيش أي
     * اعتماد على DB/كاش/سيرفر هنا عشان الاختبارات تشتغل مباشرة.
     *
     * @param array $context ناتج BusinessContextService::getContext()
     * @return array{
     *   exists: bool,
     *   total: int,
     *   grade: string,
     *   categories: array<string,array{score:int,weight:int,contribution:int,label:string}>,
     *   recommendations: array<int,array{category:string,priority:string,message:string}>,
     *   generated_at: string
     * }
     */
    public function scoreFromContext(array $context): array
    {
        if (empty($context['exists'])) {
            return [
                'exists' => false,
                'total' => 0,
                'grade' => 'F',
                'categories' => [],
                'recommendations' => [],
                'generated_at' => date('c'),
            ];
        }

        $scorers = [
            'identity'       => ['label' => 'بيانات النشاط الأساسية', 'weight' => self::CATEGORY_WEIGHTS['identity'],       'fn' => fn () => $this->scoreIdentity($context)],
            'contact'        => ['label' => 'بيانات التواصل والتحقق',  'weight' => self::CATEGORY_WEIGHTS['contact'],        'fn' => fn () => $this->scoreContact($context)],
            'locations'      => ['label' => 'المواقع والفروع',        'weight' => self::CATEGORY_WEIGHTS['locations'],      'fn' => fn () => $this->scoreLocations($context)],
            'services'       => ['label' => 'الخدمات',                'weight' => self::CATEGORY_WEIGHTS['services'],       'fn' => fn () => $this->scoreServices($context)],
            'target_markets' => ['label' => 'الأسواق المستهدفة',      'weight' => self::CATEGORY_WEIGHTS['target_markets'], 'fn' => fn () => $this->scoreTargetMarkets($context)],
            'ai_context'     => ['label' => 'السياق الذكي للـAI',     'weight' => self::CATEGORY_WEIGHTS['ai_context'],     'fn' => fn () => $this->scoreAiContext($context)],
            'brand'          => ['label' => 'الهوية البصرية والنبرة', 'weight' => self::CATEGORY_WEIGHTS['brand'],          'fn' => fn () => $this->scoreBrand($context)],
        ];

        $categories = [];
        $total = 0;
        foreach ($scorers as $key => $def) {
            $categoryScore = $def['fn']();
            $contribution = (int) round($categoryScore * $def['weight'] / 100);
            $total += $contribution;
            $categories[$key] = [
                'score' => $categoryScore,
                'weight' => $def['weight'],
                'contribution' => $contribution,
                'label' => $def['label'],
            ];
        }
        // جمع كل فئة (بعد تقريب كل مساهمة) ممكن يطلع 99/101 في الحافة -
        // نثبّته على 100 لو خرج عن النطاق (درجة نهاية منطقية مش تجميع عشوائي).
        $total = max(0, min(100, $total));

        return [
            'exists' => true,
            'total' => $total,
            'grade' => $this->gradeFor($total),
            'categories' => $categories,
            'recommendations' => $this->buildRecommendations($categories, $context),
            'generated_at' => date('c'),
        ];
    }

    /**
     * أهم النصائح المرتبة حسب الأثر (أولًا الفئات الأثقل وزنًا، وبعدين
     * الفرص الأعلى فاقدًا داخل كل فئة). كل نص رسالة إجراء واضح.
     */
    private function buildRecommendations(array $categories, array $context): array
    {
        $recommendations = [];

        foreach ($categories as $key => $cat) {
            if ($cat['score'] === 100) {
                continue;
            }
            $next = $this->topMissingSignals($key, $context);
            foreach ($next as $signal) {
                $recommendations[] = [
                    'category' => $key,
                    'priority' => $cat['weight'] >= 20 ? 'high' : ($cat['weight'] >= 15 ? 'medium' : 'low'),
                    'message' => $signal,
                ];
            }
        }

        // ترتيب: الأولوية (high > medium > low) وبعدين الفئة الأثقل وزنًا
        $priorityRank = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($recommendations, function (array $a, array $b) use ($priorityRank, $categories) {
            $pa = $priorityRank[$a['priority']];
            $pb = $priorityRank[$b['priority']];
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return $categories[$b['category']]['weight'] <=> $categories[$a['category']]['weight'];
        });

        return $recommendations;
    }

    /**
     * أكتر حاجة ناقصة في الفئة - بترجع أقصى 3 رسائل فعلية (مش كل فاضي).
     */
    private function topMissingSignals(string $category, array $context): array
    {
        $missing = [];

        switch ($category) {
            case 'identity':
                $b = $context['business'];
                if (empty($b['legal_name']) && empty($b['trade_name'])) {
                    $missing[] = 'أضف الاسم التجاري أو الاسم القانوني للنشاط';
                }
                if (empty($b['business_type'])) {
                    $missing[] = 'حدد نوع الشركة (وكالة سفر، منظم رحلات، DMC...)';
                }
                if (empty($b['description'])) {
                    $missing[] = 'اكتب وصفًا مختصرًا للنشاط والخدمات الرئيسية';
                }
                if (empty($b['year_established'])) {
                    $missing[] = 'أضف سنة التأسيس لبناء الثقة مع العملاء';
                }
                break;

            case 'contact':
                $b = $context['business'];
                if (empty($b['website_url'])) {
                    $missing[] = 'أضف رابط موقعك الإلكتروني - أساسي لأي محتوى AI';
                }
                if (empty($b['business_email'])) {
                    $missing[] = 'أضف البريد الرسمي للنشاط';
                }
                if (empty($b['business_phone']) && empty($b['whatsapp_number'])) {
                    $missing[] = 'أضف رقم هاتف أو رقم واتساب للتواصل المباشر';
                }
                break;

            case 'locations':
                $locations = $context['locations'] ?? [];
                if (empty($locations)) {
                    $missing[] = 'أضف موقعًا واحدًا على الأقل - المقر الرئيسي إلزامي';
                } else {
                    $primary = $context['primary_location'] ?? $locations[0];
                    if (empty($primary['opening_hours'])) {
                        $missing[] = 'أضف ساعات العمل للموقع الرئيسي - يساعد محركات البحث والـAI';
                    }
                    if (empty($primary['address'])) {
                        $missing[] = 'أكمل عنوان الموقع الرئيسي (الشارع والمنطقة)';
                    }
                    if (empty($primary['latitude']) || empty($primary['longitude'])) {
                        $missing[] = 'أضف إحداثيات الموقع الرئيسي للظهور على الخرائط';
                    }
                }
                break;

            case 'services':
                $services = $context['services'] ?? [];
                if (empty($services)) {
                    $missing[] = 'أضف خدمة واحدة نشطة على الأقل (جولات، رحلات نيلية...)';
                } else {
                    $hasDescription = array_filter($services, fn ($s) => !empty($s['description']));
                    if (count($hasDescription) !== count($services)) {
                        $missing[] = 'أضف وصفًا تفصيليًا لكل خدمة لتغذية محتوى الـAI';
                    }
                    $hasCategory = array_filter($services, fn ($s) => !empty($s['category']));
                    if (count($hasCategory) !== count($services)) {
                        $missing[] = 'صنّف كل خدمة ضمن فئة واضحة (رحلات، فنادق، نقل...)';
                    }
                }
                break;

            case 'target_markets':
                $tm = $context['target_markets'] ?? null;
                if (empty($tm['countries'])) {
                    $missing[] = 'حدد الدول المستهدفة للسفر';
                }
                if (empty($tm['languages'])) {
                    $missing[] = 'حدد لغات جمهورك المستهدف';
                }
                if (empty($tm['customer_type'])) {
                    $missing[] = 'حدد نوع العميل (أفراد B2C أو شركات B2B أو كلاهما)';
                }
                break;

            case 'ai_context':
                $ai = $context['ai_context'] ?? null;
                if (empty($ai['business_summary'])) {
                    $missing[] = 'اكتب ملخصًا ذكيًا للنشاط - العمود الفقري لكل توليد محتوى';
                }
                if (empty($ai['target_audience'])) {
                    $missing[] = 'عرّف جمهورك المستهدف بوضوح ليخاطبه الـAI بشكل صحيح';
                }
                if (empty($ai['unique_selling_points'])) {
                    $missing[] = 'أضف نقاط التميز التنافسية (مميزاتك اللي مش موجودة عند غيرك)';
                }
                if (empty($ai['preferred_keywords'])) {
                    $missing[] = 'أضف الكلمات المفتاحية المفضلة لديك لتحسين SEO';
                }
                break;

            case 'brand':
                $brand = $context['brand_settings'] ?? null;
                if (empty($brand['brand_colors'])) {
                    $missing[] = 'حدد ألوان العلامة التجارية الرسمية';
                }
                if (empty($brand['writing_style'])) {
                    $missing[] = 'حدد أسلوب الكتابة المعتمد (رسمي، ودود...)';
                }
                if (empty($brand['preferred_terminology'])) {
                    $missing[] = 'حدد المصطلحات المفضلة لضمان ثبات الرسائل';
                }
                break;
        }

        return array_slice($missing, 0, 3);
    }

    private function scoreIdentity(array $context): int
    {
        $b = $context['business'];
        $score = 0;
        if (!empty($b['legal_name']) || !empty($b['trade_name'])) {
            $score += 30;
        }
        if (!empty($b['business_type'])) {
            $score += 20;
        }
        if (!empty($b['description'])) {
            $score += 20;
        }
        if (!empty($b['year_established'])) {
            $score += 10;
        }
        if (!empty($b['country_code'])) {
            $score += 10;
        }
        if (!empty($b['city'])) {
            $score += 10;
        }
        return min(100, $score);
    }

    private function scoreContact(array $context): int
    {
        $b = $context['business'];
        $score = 0;
        if (!empty($b['website_url'])) {
            $score += 25;
        }
        if (!empty($b['business_email'])) {
            $score += 20;
        }
        if (!empty($b['business_phone'])) {
            $score += 15;
        }
        if (!empty($b['whatsapp_number'])) {
            $score += 10;
        }
        if (!empty($b['logo_url'])) {
            $score += 10;
        }
        if (!empty($b['address'])) {
            $score += 10;
        }
        if (!empty($b['postal_code'])) {
            $score += 5;
        }
        if (!empty($b['supported_languages'])) {
            $score += 5;
        }
        return min(100, $score);
    }

    private function scoreLocations(array $context): int
    {
        $locations = $context['locations'] ?? [];
        if (empty($locations)) {
            return 0;
        }
        $primary = $context['primary_location'] ?? $locations[0];

        $score = 30; // يوجد موقع واحد على الأقل
        if (!empty($context['primary_location'])) {
            $score += 30; // يوجد مقر رئيسي محدد
        }
        if (!empty($primary['country_code']) && !empty($primary['city'])) {
            $score += 15;
        }
        if (!empty($primary['opening_hours'])) {
            $score += 15;
        }
        if (!empty($primary['address'])) {
            $score += 10;
        }
        return min(100, $score);
    }

    private function scoreServices(array $context): int
    {
        $services = $context['services'] ?? [];
        if (empty($services)) {
            return 0;
        }
        $score = 60; // خدمة نشطة واحدة على الأقل
        $all = count($services);
        $withDescription = count(array_filter($services, fn ($s) => !empty($s['description'])));
        $withCategory = count(array_filter($services, fn ($s) => !empty($s['category'])));
        if ($all > 0 && $withDescription === $all) {
            $score += 20;
        }
        if ($all > 0 && $withCategory === $all) {
            $score += 20;
        }
        return min(100, $score);
    }

    private function scoreTargetMarkets(array $context): int
    {
        $tm = $context['target_markets'] ?? null;
        if (!$tm) {
            return 0;
        }
        $score = 0;
        if (!empty($tm['countries'])) {
            $score += 30;
        }
        if (!empty($tm['cities'])) {
            $score += 20;
        }
        if (!empty($tm['languages'])) {
            $score += 20;
        }
        if (!empty($tm['customer_type'])) {
            $score += 15;
        }
        if (!empty($tm['customer_segments'])) {
            $score += 15;
        }
        return min(100, $score);
    }

    private function scoreAiContext(array $context): int
    {
        $ai = $context['ai_context'] ?? null;
        if (!$ai) {
            return 0;
        }
        $score = 0;
        if (!empty($ai['business_summary'])) {
            $score += 20;
        }
        if (!empty($ai['brand_description'])) {
            $score += 15;
        }
        if (!empty($ai['target_audience'])) {
            $score += 15;
        }
        if (!empty($ai['unique_selling_points'])) {
            $score += 15;
        }
        if (!empty($ai['brand_voice'])) {
            $score += 10;
        }
        if (!empty($ai['preferred_keywords'])) {
            $score += 10;
        }
        if (!empty($ai['business_goals'])) {
            $score += 10;
        }
        if (!empty($ai['competitors'])) {
            $score += 5;
        }
        return min(100, $score);
    }

    private function scoreBrand(array $context): int
    {
        $brand = $context['brand_settings'] ?? null;
        if (!$brand) {
            return 0;
        }
        $score = 0;
        if (!empty($brand['brand_colors'])) {
            $score += 40;
        }
        if (!empty($brand['writing_style'])) {
            $score += 30;
        }
        if (!empty($brand['preferred_terminology'])) {
            $score += 30;
        }
        return min(100, $score);
    }

    /**
     * تحويل الدرجة لرمز تقييم (A-F) - نفس فلسفة SOCi/Birdeye: رمز سهل
     * الواجهة بتعرضه بلون معين من غير ما تشرح الأرقام.
     */
    private function gradeFor(int $total): string
    {
        if ($total >= 90) {
            return 'A';
        }
        if ($total >= 75) {
            return 'B';
        }
        if ($total >= 60) {
            return 'C';
        }
        if ($total >= 40) {
            return 'D';
        }
        return 'F';
    }
}
