<?php

/**
 * Tourfecto - AI-Powered Website Builder Service
 * @version 2.0.0 "Genius Edition"
 * 
 * منصة بناء مواقع سياحية ذكية بالكامل:
 * - توليد مواقع بالذكاء الاصطناعي من وصف نصي
 * - محرر مرئي Drag & Drop
 * - مكونات سياحية متخصصة (حجوزات، جولات، فنادق)
 * - تحسين SEO تلقائي
 * - Static Site Generation للنشر الفوري
 */

class WebsiteBuilderService
{
    private $db;
    private $aiService;
    
    public function __construct()
    {
        $this->db = TourfectoDB::getInstance();
        $this->aiService = new AiContentGenerationService();
    }

    /**
     * إنشاء موقع جديد بالذكاء الاصطناعي
     * @param int $clientId معرف العميل
     * @param string $businessType نوع النشاط (فندق، شركة سياحة، إلخ)
     * @param string $prompt وصف الموقع المطلوب
     * @param array $preferences تفضيلات التصميم
     * @return array بيانات الموقع المنشأ
     */
    public function createWebsiteWithAI(int $clientId, string $businessType, string $prompt, array $preferences = []): array
    {
        try {
            // 1. تحليل الطلب بالذكاء الاصطناعي
            $analysis = $this->aiService->analyzeWebsitePrompt($prompt, $businessType);
            
            // 2. توليد هيكل الموقع
            $siteStructure = $this->generateSiteStructure($analysis, $businessType);
            
            // 3. اختيار القالب المناسب
            $template = $this->selectTemplate($businessType, $analysis['style']);
            
            // 4. توليد المحتوى
            $content = $this->aiService->generateWebsiteContent($analysis, $businessType);
            
            // 5. إنشاء السجل في قاعدة البيانات
            $websiteId = $this->db->insert('websites', [
                'client_id' => $clientId,
                'name' => $analysis['siteName'],
                'slug' => $this->generateSlug($analysis['siteName']),
                'business_type' => $businessType,
                'template_id' => $template['id'],
                'theme_config' => json_encode(array_merge($template['defaultTheme'], $preferences)),
                'status' => 'draft',
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            // 6. إنشاء الصفحات
            foreach ($siteStructure['pages'] as $page) {
                $this->createPage($websiteId, $page, $content);
            }
            
            // 7. إضافة المكونات الذكية
            $this->addSmartComponents($websiteId, $businessType);
            
            return [
                'success' => true,
                'website_id' => $websiteId,
                'preview_url' => '/preview/' . $websiteId,
                'editor_url' => '/editor/' . $websiteId,
                'message' => 'تم إنشاء الموقع بنجاح! يمكنك الآن التعديل عليه.'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'فشل إنشاء الموقع: ' . $e->getMessage()
            ];
        }
    }

    /**
     * تحليل وصف الموقع بالذكاء الاصطناعي
     */
    public function analyzeWebsitePrompt(string $prompt, string $businessType): array
    {
        // استخدام Gemini لتحليل الوصف واستخراج المتطلبات
        $gemini = new AiRerankingService(); // أو خدمة AI المناسبة
        
        $systemPrompt = "أنت خبير في تصميم المواقع السياحية. حلل الوصف التالي واستخرج:
        1. اسم الموقع المقترح
        2. الألوان المناسبة (primary, secondary, accent)
        3. الخطوط (fonts)
        4. الأقسام المطلوبة (hero, services, gallery, testimonials, booking, contact)
        5. نبرة المحتوى (رسمي، ودي، فاخر، مغامر)
        
        نوع النشاط: {$businessType}
        الوصف: {$prompt}";
        
        // هنا يتم استدعاء API الذكاء الاصطناعي
        // للتبسيط سنعرض مثالاً على النتيجة المتوقعة
        
        return [
            'siteName' => 'Discover Egypt Tours',
            'style' => [
                'primaryColor' => '#1e88e5',
                'secondaryColor' => '#ffb74d',
                'accentColor' => '#43a047',
                'fonts' => ['Cairo', 'Open Sans'],
                'layout' => 'modern'
            ],
            'sections' => ['hero', 'featured-tours', 'destinations', 'testimonials', 'booking-form', 'contact'],
            'tone' => 'adventurous',
            'targetAudience' => 'international_tourists',
            'keyFeatures' => ['multi-language', 'online-booking', 'payment-gateway']
        ];
    }

    /**
     * توليد هيكل الصفحات
     */
    private function generateSiteStructure(array $analysis, string $businessType): array
    {
        $structures = [
            'hotel' => [
                'pages' => [
                    ['slug' => 'home', 'title' => 'الرئيسية', 'template' => 'hotel-home'],
                    ['slug' => 'rooms', 'title' => 'الغرف والأجنحة', 'template' => 'rooms-list'],
                    ['slug' => 'amenities', 'title' => 'المرافق', 'template' => 'amenities'],
                    ['slug' => 'gallery', 'title' => 'معرض الصور', 'template' => 'gallery'],
                    ['slug' => 'booking', 'title' => 'احجز الآن', 'template' => 'booking-engine'],
                    ['slug' => 'contact', 'title' => 'اتصل بنا', 'template' => 'contact']
                ]
            ],
            'tour-operator' => [
                'pages' => [
                    ['slug' => 'home', 'title' => 'الرئيسية', 'template' => 'tours-home'],
                    ['slug' => 'tours', 'title' => 'الجولات المتاحة', 'template' => 'tours-list'],
                    ['slug' => 'destinations', 'title' => 'الوجهات', 'template' => 'destinations'],
                    ['slug' => 'about', 'title' => 'من نحن', 'template' => 'about'],
                    ['slug' => 'blog', 'title' => 'مدونة السفر', 'template' => 'blog'],
                    ['slug' => 'contact', 'title' => 'اتصل بنا', 'template' => 'contact']
                ]
            ],
            'travel-agency' => [
                'pages' => [
                    ['slug' => 'home', 'title' => 'الرئيسية', 'template' => 'agency-home'],
                    ['slug' => 'packages', 'title' => 'باقات السفر', 'template' => 'packages'],
                    ['slug' => 'services', 'title' => 'خدماتنا', 'template' => 'services'],
                    ['slug' => 'visa', 'title' => 'إجراءات التأشيرات', 'template' => 'visa-info'],
                    ['slug' => 'contact', 'title' => 'اتصل بنا', 'template' => 'contact']
                ]
            ]
        ];
        
        return $structures[$businessType] ?? $structures['tour-operator'];
    }

    /**
     * اختيار القالب المناسب
     */
    private function selectTemplate(string $businessType, array $style): array
    {
        $templates = [
            'hotel' => [
                'id' => 'hotel-luxury-01',
                'name' => 'فندق فاخر',
                'defaultTheme' => [
                    'primary' => '#2c3e50',
                    'secondary' => '#e67e22',
                    'font_primary' => 'Playfair Display',
                    'font_body' => 'Lato'
                ]
            ],
            'tour-operator' => [
                'id' => 'tours-adventure-01',
                'name' => 'جولات مغامرة',
                'defaultTheme' => [
                    'primary' => '#27ae60',
                    'secondary' => '#f39c12',
                    'font_primary' => 'Montserrat',
                    'font_body' => 'Open Sans'
                ]
            ],
            'travel-agency' => [
                'id' => 'agency-modern-01',
                'name' => 'وكالة سفر عصرية',
                'defaultTheme' => [
                    'primary' => '#3498db',
                    'secondary' => '#e74c3c',
                    'font_primary' => 'Poppins',
                    'font_body' => 'Roboto'
                ]
            ]
        ];
        
        return $templates[$businessType] ?? $templates['tour-operator'];
    }

    /**
     * إنشاء صفحة
     */
    private function createPage(int $websiteId, array $pageData, array $content): int
    {
        $pageContent = $content[$pageData['slug']] ?? $this->generateDefaultContent($pageData);
        
        return $this->db->insert('website_pages', [
            'website_id' => $websiteId,
            'slug' => $pageData['slug'],
            'title' => $pageData['title'],
            'template' => $pageData['template'],
            'content' => json_encode($pageContent),
            'seo_title' => $pageData['title'] . ' | Tourfecto Site',
            'seo_description' => substr($pageContent['intro'] ?? '', 0, 160),
            'is_published' => false,
            'order' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * إضافة المكونات الذكية
     */
    private function addSmartComponents(int $websiteId, string $businessType): void
    {
        $components = [];
        
        // مكونات مشتركة
        $components[] = [
            'website_id' => $websiteId,
            'type' => 'whatsapp-float',
            'config' => json_encode(['position' => 'bottom-right', 'auto_message' => 'مرحباً! كيف يمكنني مساعدتك؟']),
            'is_active' => true
        ];
        
        $components[] = [
            'website_id' => $websiteId,
            'type' => 'chatbot',
            'config' => json_encode(['welcome_message' => 'أهلاً بك! اسألني عن أي شيء يتعلق بخدماتنا']),
            'is_active' => true
        ];
        
        // مكونات خاصة بنوع النشاط
        if ($businessType === 'hotel') {
            $components[] = [
                'website_id' => $websiteId,
                'type' => 'booking-engine',
                'config' => json_encode([
                    'check_in_out' => true,
                    'room_selector' => true,
                    'payment_integration' => true
                ]),
                'is_active' => true
            ];
            
            $components[] = [
                'website_id' => $websiteId,
                'type' => 'room-availability',
                'config' => json_encode(['real_time_sync' => true]),
                'is_active' => true
            ];
        }
        
        if ($businessType === 'tour-operator') {
            $components[] = [
                'website_id' => $websiteId,
                'type' => 'tour-search',
                'config' => json_encode([
                    'filters' => ['destination', 'duration', 'price', 'difficulty'],
                    'map_view' => true
                ]),
                'is_active' => true
            ];
            
            $components[] = [
                'website_id' => $websiteId,
                'type' => 'itinerary-builder',
                'config' => json_encode(['ai_suggestions' => true]),
                'is_active' => true
            ];
        }
        
        foreach ($components as $component) {
            $this->db->insert('website_components', $component);
        }
    }

    /**
     * نشر الموقع (Static Site Generation)
     */
    public function publishWebsite(int $websiteId): array
    {
        try {
            $website = $this->db->selectOne('websites', ['id' => $websiteId]);
            if (!$website) {
                return ['success' => false, 'error' => 'الموقع غير موجود'];
            }
            
            // 1. جلب جميع الصفحات
            $pages = $this->db->select('website_pages', ['website_id' => $websiteId, 'is_published' => true]);
            
            // 2. توليد ملفات HTML ثابتة
            $outputDir = TOURFECTO_STORAGE . "/sites/{$websiteId}/public";
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }
            
            foreach ($pages as $page) {
                $html = $this->renderPageToHTML($website, $page);
                file_put_contents("{$outputDir}/{$page['slug']}.html", $html);
                
                // الصفحة الرئيسية تكون index.html
                if ($page['slug'] === 'home') {
                    file_put_contents("{$outputDir}/index.html", $html);
                }
            }
            
            // 3. نسخ الأصول (CSS, JS, Images)
            $this->copyAssets($websiteId, $outputDir);
            
            // 4. تحديث الحالة
            $this->db->update('websites', ['id' => $websiteId], [
                'status' => 'published',
                'published_at' => date('Y-m-d H:i:s'),
                'public_url' => "https://sites.tourfecto.com/{$website['slug']}"
            ]);
            
            // 5. تفعيل CDN
            $this->purgeCDNCache($websiteId);
            
            return [
                'success' => true,
                'public_url' => "https://sites.tourfecto.com/{$website['slug']}",
                'message' => 'تم نشر الموقع بنجاح!'
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'فشل النشر: ' . $e->getMessage()];
        }
    }

    /**
     * تصدير صفحة إلى HTML
     */
    private function renderPageToHTML(array $website, array $page): string
    {
        $content = json_decode($page['content'], true);
        $theme = json_decode($website['theme_config'], true);
        
        // تحميل القالب
        $templatePath = TOURFECTO_ROOT . "/resources/templates/websites/{$page['template']}.php";
        if (!file_exists($templatePath)) {
            $templatePath = TOURFECTO_ROOT . "/resources/templates/websites/default.php";
        }
        
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * نسخ الأصول
     */
    private function copyAssets(int $websiteId, string $outputDir): void
    {
        $assetsDir = $outputDir . '/assets';
        if (!is_dir($assetsDir)) {
            mkdir($assetsDir, 0755, true);
        }
        
        // نسخ CSS/JS الأساسي
        $this->recursiveCopy(TOURFECTO_ROOT . '/public/assets/websites', $assetsDir);
    }

    /**
     * نسخ متكرر للمجلدات
     */
    private function recursiveCopy(string $src, string $dst): void
    {
        $dir = opendir($src);
        @mkdir($dst, 0755, true);
        
        while (($file = readdir($dir)) !== false) {
            if ($file != '.' && $file != '..') {
                if (is_dir("$src/$file")) {
                    $this->recursiveCopy("$src/$file", "$dst/$file");
                } else {
                    copy("$src/$file", "$dst/$file");
                }
            }
        }
        closedir($dir);
    }

    /**
     * مسح ذاكرة التخزين المؤقت لـ CDN
     */
    private function purgeCDNCache(int $websiteId): void
    {
        // تكامل مع Cloudflare أو أي CDN
        // يمكن تنفيذه لاحقاً
    }

    /**
     * توليد Slug من الاسم
     */
    private function generateSlug(string $name): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9\s-]/', '', $name);
        $slug = preg_replace('/[\s-]+/', '-', strtolower(trim($slug)));
        return $slug;
    }

    /**
     * توليد محتوى افتراضي
     */
    private function generateDefaultContent(array $pageData): array
    {
        return [
            'intro' => 'مرحباً بك في موقعنا الإلكتروني',
            'sections' => []
        ];
    }
}
