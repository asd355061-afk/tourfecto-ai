<?php

namespace App\Services\Core;

/**
 * Internationalization (i18n) Service
 * 
 * Central translation engine supporting 10+ languages with RTL support.
 * English is the base language, with fallback mechanism.
 */
class I18nService
{
    private array $supportedLanguages = [
        'en' => ['name' => 'English', 'native' => 'English', 'rtl' => false, 'flag' => '🇺🇸'],
        'ar' => ['name' => 'Arabic', 'native' => 'العربية', 'rtl' => true, 'flag' => '🇸🇦'],
        'es' => ['name' => 'Spanish', 'native' => 'Español', 'rtl' => false, 'flag' => '🇪🇸'],
        'fr' => ['name' => 'French', 'native' => 'Français', 'rtl' => false, 'flag' => '🇫🇷'],
        'de' => ['name' => 'German', 'native' => 'Deutsch', 'rtl' => false, 'flag' => '🇩🇪'],
        'it' => ['name' => 'Italian', 'native' => 'Italiano', 'rtl' => false, 'flag' => '🇮🇹'],
        'pt' => ['name' => 'Portuguese', 'native' => 'Português', 'rtl' => false, 'flag' => '🇵🇹'],
        'ru' => ['name' => 'Russian', 'native' => 'Русский', 'rtl' => false, 'flag' => '🇷🇺'],
        'zh' => ['name' => 'Chinese', 'native' => '中文', 'rtl' => false, 'flag' => '🇨🇳'],
        'ja' => ['name' => 'Japanese', 'native' => '日本語', 'rtl' => false, 'flag' => '🇯🇵'],
        'ko' => ['name' => 'Korean', 'native' => '한국어', 'rtl' => false, 'flag' => '🇰🇷'],
        'tr' => ['name' => 'Turkish', 'native' => 'Türkçe', 'rtl' => false, 'flag' => '🇹🇷'],
        'hi' => ['name' => 'Hindi', 'native' => 'हिन्दी', 'rtl' => false, 'flag' => '🇮🇳'],
        'ur' => ['name' => 'Urdu', 'native' => 'اردو', 'rtl' => true, 'flag' => '🇵🇰'],
        'fa' => ['name' => 'Persian', 'native' => 'فارسی', 'rtl' => true, 'flag' => '🇮🇷'],
    ];

    private string $defaultLanguage = 'en';
    private string $currentLanguage = 'en';
    private array $translations = [];
    private ?TranslationApiService $translationApi = null;

    public function __construct()
    {
        $this->currentLanguage = $this->defaultLanguage;
        $this->loadTranslations();
    }

    /**
     * Set current language
     */
    public function setLanguage(string $lang): bool
    {
        if (!$this->isSupported($lang)) {
            // Fallback to English if not supported
            $lang = $this->defaultLanguage;
        }
        
        $this->currentLanguage = $lang;
        $this->loadTranslations();
        
        return true;
    }

    /**
     * Get current language
     */
    public function getCurrentLanguage(): string
    {
        return $this->currentLanguage;
    }

    /**
     * Check if language is supported
     */
    public function isSupported(string $lang): bool
    {
        return isset($this->supportedLanguages[$lang]);
    }

    /**
     * Get all supported languages
     */
    public function getSupportedLanguages(): array
    {
        return $this->supportedLanguages;
    }

    /**
     * Check if current language is RTL
     */
    public function isRtl(): bool
    {
        return $this->supportedLanguages[$this->currentLanguage]['rtl'] ?? false;
    }

    /**
     * Get language direction
     */
    public function getDirection(): string
    {
        return $this->isRtl() ? 'rtl' : 'ltr';
    }

    /**
     * Translate a key
     */
    public function trans(string $key, array $params = []): string
    {
        $translation = $this->getTranslation($key);
        
        if (empty($translation)) {
            // Fallback to English
            $englishKey = str_replace($this->currentLanguage . '.', 'en.', $key);
            $translation = $this->getTranslationFromCache($englishKey);
            
            if (empty($translation)) {
                // Return key as last resort
                return $key;
            }
        }
        
        // Replace parameters
        foreach ($params as $paramKey => $paramValue) {
            $translation = str_replace('{' . $paramKey . '}', $paramValue, $translation);
        }
        
        return $translation;
    }

    /**
     * Shorthand for trans()
     */
    public function t(string $key, array $params = []): string
    {
        return $this->trans($key, $params);
    }

    /**
     * Get translation for current language
     */
    private function getTranslation(string $key): string
    {
        return $this->getTranslationFromCache($key) ?? '';
    }

    /**
     * Get translation from cache or load it
     */
    private function getTranslationFromCache(string $key): ?string
    {
        $keys = explode('.', $key);
        $result = $this->translations;
        
        foreach ($keys as $k) {
            if (!isset($result[$k])) {
                return null;
            }
            $result = $result[$k];
        }
        
        return is_string($result) ? $result : null;
    }

    /**
     * Load translations for current language
     */
    private function loadTranslations(): void
    {
        $cacheKey = "translations_{$this->currentLanguage}";
        
        // Try to load from cache first
        if (isset($this->translations[$cacheKey])) {
            return;
        }
        
        // Load from file
        $file = "/workspace/resources/lang/{$this->currentLanguage}.json";
        
        if (file_exists($file)) {
            $content = file_get_contents($file);
            $this->translations = json_decode($content, true) ?? [];
        } else {
            // Load default empty structure
            $this->translations = $this->getDefaultTranslations();
        }
    }

    /**
     * Get default translations structure
     */
    private function getDefaultTranslations(): array
    {
        return [
            'common' => [
                'welcome' => 'Welcome',
                'goodbye' => 'Goodbye',
                'save' => 'Save',
                'cancel' => 'Cancel',
                'delete' => 'Delete',
                'edit' => 'Edit',
                'view' => 'View',
                'search' => 'Search',
                'loading' => 'Loading...',
                'error' => 'Error',
                'success' => 'Success',
                'warning' => 'Warning',
                'info' => 'Information',
            ],
            'auth' => [
                'login' => 'Login',
                'logout' => 'Logout',
                'register' => 'Register',
                'forgot_password' => 'Forgot Password?',
                'reset_password' => 'Reset Password',
                'email' => 'Email Address',
                'password' => 'Password',
                'remember_me' => 'Remember Me',
            ],
            'dashboard' => [
                'title' => 'Dashboard',
                'overview' => 'Overview',
                'analytics' => 'Analytics',
                'reports' => 'Reports',
                'settings' => 'Settings',
            ],
            'crm' => [
                'customers' => 'Customers',
                'leads' => 'Leads',
                'deals' => 'Deals',
                'pipeline' => 'Pipeline',
                'activities' => 'Activities',
            ],
            'ai' => [
                'assistant' => 'AI Assistant',
                'chat' => 'Chat',
                'analyze' => 'Analyze',
                'generate' => 'Generate',
                'optimize' => 'Optimize',
            ],
        ];
    }

    /**
     * Add or update translation
     */
    public function setTranslation(string $key, string $value, ?string $lang = null): void
    {
        $lang = $lang ?? $this->currentLanguage;
        $keys = explode('.', $key);
        
        $current = &$this->translations;
        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $current[$k] = $value;
            } else {
                if (!isset($current[$k])) {
                    $current[$k] = [];
                }
                $current = &$current[$k];
            }
        }
        
        $this->saveTranslations($lang);
    }

    /**
     * Save translations to file
     */
    private function saveTranslations(string $lang): void
    {
        $file = "/workspace/resources/lang/{$lang}.json";
        file_put_contents($file, json_encode($this->translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Auto-translate missing keys using Translation API
     */
    public function autoTranslateMissing(string $sourceLang = 'en', string $targetLang = null): int
    {
        $targetLang = $targetLang ?? $this->currentLanguage;
        
        if (!$this->isSupported($targetLang)) {
            return 0;
        }
        
        $this->setLanguage($sourceLang);
        $sourceTranslations = $this->flattenTranslations($this->translations);
        
        $this->setLanguage($targetLang);
        $targetTranslations = $this->flattenTranslations($this->translations);
        
        $missing = array_diff_key($sourceTranslations, $targetTranslations);
        $translatedCount = 0;
        
        if (empty($missing)) {
            return 0;
        }
        
        // Initialize translation API if needed
        if ($this->translationApi === null) {
            $this->translationApi = new TranslationApiService();
        }
        
        foreach ($missing as $key => $value) {
            if (empty(trim($value))) {
                continue;
            }
            
            $translated = $this->translationApi->translate($value, $sourceLang, $targetLang);
            
            if ($translated) {
                $this->setTranslation($key, $translated, $targetLang);
                $translatedCount++;
            }
        }
        
        return $translatedCount;
    }

    /**
     * Flatten translations array
     */
    private function flattenTranslations(array $array, string $prefix = ''): array
    {
        $result = [];
        
        foreach ($array as $key => $value) {
            $newKey = $prefix ? "{$prefix}.{$key}" : $key;
            
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenTranslations($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }
        
        return $result;
    }

    /**
     * Detect language from text
     */
    public function detectLanguage(string $text): string
    {
        // Simple detection based on character ranges
        $arabicPattern = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u';
        $chinesePattern = '/[\x{4E00}-\x{9FFF}]/u';
        $japanesePattern = '/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u';
        $cyrillicPattern = '/[\x{0400}-\x{04FF}]/u';
        $koreanPattern = '/[\x{AC00}-\x{D7AF}]/u';
        
        if (preg_match($arabicPattern, $text)) {
            return 'ar';
        }
        
        if (preg_match($chinesePattern, $text)) {
            return 'zh';
        }
        
        if (preg_match($japanesePattern, $text)) {
            return 'ja';
        }
        
        if (preg_match($cyrillicPattern, $text)) {
            return 'ru';
        }
        
        if (preg_match($koreanPattern, $text)) {
            return 'ko';
        }
        
        // Default to English
        return 'en';
    }

    /**
     * Format date according to language
     */
    public function formatDate(\DateTime $date, string $format = 'long'): string
    {
        $locales = [
            'en' => 'en_US',
            'ar' => 'ar_SA',
            'es' => 'es_ES',
            'fr' => 'fr_FR',
            'de' => 'de_DE',
            'it' => 'it_IT',
            'pt' => 'pt_BR',
            'ru' => 'ru_RU',
            'zh' => 'zh_CN',
            'ja' => 'ja_JP',
            'ko' => 'ko_KR',
            'tr' => 'tr_TR',
            'hi' => 'hi_IN',
            'ur' => 'ur_PK',
            'fa' => 'fa_IR',
        ];
        
        $locale = $locales[$this->currentLanguage] ?? 'en_US';
        
        // Set locale temporarily
        $oldLocale = setlocale(LC_TIME, 0);
        setlocale(LC_TIME, $locale);
        
        switch ($format) {
            case 'short':
                $result = strftime('%m/%d/%Y', $date->getTimestamp());
                break;
            case 'medium':
                $result = strftime('%b %d, %Y', $date->getTimestamp());
                break;
            case 'long':
                $result = strftime('%B %d, %Y', $date->getTimestamp());
                break;
            case 'time':
                $result = strftime('%H:%M:%S', $date->getTimestamp());
                break;
            case 'datetime':
                $result = strftime('%B %d, %Y %H:%M:%S', $date->getTimestamp());
                break;
            default:
                $result = $date->format('Y-m-d H:i:s');
        }
        
        // Restore locale
        setlocale(LC_TIME, $oldLocale);
        
        return $result;
    }

    /**
     * Format number according to language
     */
    public function formatNumber(float $number, int $decimals = 2): string
    {
        $separators = [
            'en' => ['.' , ','],
            'ar' => [',' , '.'],
            'de' => [',' , '.'],
            'fr' => [',' , ' '],
            'es' => [',' , '.'],
            'it' => [',' , '.'],
            'pt' => [',' , '.'],
            'ru' => [',' , ' '],
            'zh' => ['.' , ','],
            'ja' => ['.' , ','],
            'ko' => ['.' , ','],
        ];
        
        $separator = $separators[$this->currentLanguage] ?? ['.', ','];
        
        return number_format($number, $decimals, $separator[0], $separator[1]);
    }

    /**
     * Format currency according to language
     */
    public function formatCurrency(float $amount, string $currency = 'USD'): string
    {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'SAR' => 'ر.س',
            'AED' => 'د.إ',
            'EGP' => 'ج.م',
            'KWD' => 'د.ك',
            'QAR' => 'ر.ق',
            'BHD' => 'ب.د',
            'OMR' => 'ر.ع.',
            'JOD' => 'د.ا',
            'LBP' => 'ل.ل',
            'TRY' => '₺',
            'INR' => '₹',
            'PKR' => '₨',
            'IRR' => '﷼',
            'CNY' => '¥',
            'JPY' => '¥',
            'KRW' => '₩',
            'RUB' => '₽',
        ];
        
        $symbol = $symbols[$currency] ?? $currency;
        $formatted = $this->formatNumber($amount, 2);
        
        // RTL languages put symbol after
        if ($this->isRtl()) {
            return "{$formatted} {$symbol}";
        }
        
        return "{$symbol}{$formatted}";
    }

    /**
     * Get language name
     */
    public function getLanguageName(string $code, bool $native = false): string
    {
        if (!isset($this->supportedLanguages[$code])) {
            return $code;
        }
        
        return $native 
            ? $this->supportedLanguages[$code]['native']
            : $this->supportedLanguages[$code]['name'];
    }

    /**
     * Get language flag emoji
     */
    public function getLanguageFlag(string $code): string
    {
        return $this->supportedLanguages[$code]['flag'] ?? '';
    }

    /**
     * Export all translations for a language
     */
    public function exportTranslations(string $lang = null): array
    {
        $lang = $lang ?? $this->currentLanguage;
        $this->setLanguage($lang);
        
        return $this->translations;
    }

    /**
     * Import translations from array
     */
    public function importTranslations(array $translations, string $lang = null): void
    {
        $lang = $lang ?? $this->currentLanguage;
        $this->translations = $translations;
        $this->saveTranslations($lang);
    }

    /**
     * Clear translation cache
     */
    public function clearCache(): void
    {
        $this->translations = [];
        $this->loadTranslations();
    }
}
