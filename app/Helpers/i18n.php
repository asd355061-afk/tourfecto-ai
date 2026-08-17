<?php

/**
 * Tourfecto - i18n / Translation Helper
 * نظام ترجمة بسيط وسريع: ملفات لغة PHP (مش JSON، أسرع في التحميل)،
 * كشف اللغة من ?lang= أو كوكي، ودعم RTL/LTR تلقائي.
 * الإنجليزي هو اللغة الافتراضية للموقع (base language).
 * @version 1.0.0
 */

if (!defined('UI_SUPPORTED_LANGUAGES')) {
    define('UI_SUPPORTED_LANGUAGES', ['en', 'ar', 'fr', 'de']);
}
if (!defined('UI_DEFAULT_LANGUAGE')) {
    // تصحيح حرج: كانت 'en' رغم إن الموقع عربي بالأساس (شركة مصرية،
    // تكامل واتساب، وأغلب المحتوى الأصلي مكتوب عربي مباشر من غير ما
    // يمر بنظام الترجمة أصلاً). يعني أي زائر جديد من غير تفضيل لغة
    // محفوظ (مفيش ?lang= ولا كوكي) كان يشوف الموقع بالإنجليزي افتراضيًا،
    // وبما إن مش كل صفحة اتترجمت لسه، كان بيشوف خليط عربي/إنجليزي غير
    // متناسق في نفس الجلسة - بالظبط المشكلة اللي محتاجين نتجنبها.
    // العربي هو اللغة الطبيعية لمحتوى الموقع فعليًا، فخليناه الافتراضي.
    define('UI_DEFAULT_LANGUAGE', 'ar');
}
if (!defined('UI_RTL_LANGUAGES')) {
    define('UI_RTL_LANGUAGES', ['ar']);
}

if (!function_exists('current_lang')) {
    /**
     * تحديد اللغة الحالية: ?lang= في الرابط (وبيحفظها في كوكي)، أو الكوكي
     * المحفوظة من زيارة سابقة، أو الإنجليزي كافتراضي.
     * @return string
     */
    function current_lang(): string
    {
        static $lang = null;
        if ($lang !== null) {
            return $lang;
        }

        $requested = $_GET['lang'] ?? null;
        if ($requested && in_array($requested, UI_SUPPORTED_LANGUAGES, true)) {
            $lang = $requested;
            if (!headers_sent()) {
                setcookie('tf_lang', $lang, time() + 60 * 60 * 24 * 365, '/');
            }
            return $lang;
        }

        $cookie = $_COOKIE['tf_lang'] ?? null;
        if ($cookie && in_array($cookie, UI_SUPPORTED_LANGUAGES, true)) {
            $lang = $cookie;
            return $lang;
        }

        $lang = UI_DEFAULT_LANGUAGE;
        return $lang;
    }
}

if (!function_exists('current_dir')) {
    /**
     * اتجاه الصفحة (rtl/ltr) حسب اللغة الحالية.
     * @return string
     */
    function current_dir(): string
    {
        return in_array(current_lang(), UI_RTL_LANGUAGES, true) ? 'rtl' : 'ltr';
    }
}

if (!function_exists('load_translations')) {
    /**
     * تحميل قاموس ترجمة لغة معيّنة (مع cache في نفس الـ request).
     * @param string $lang
     * @return array
     */
    function load_translations(string $lang): array
    {
        static $cache = [];
        if (isset($cache[$lang])) {
            return $cache[$lang];
        }

        $path = __DIR__ . '/../Lang/' . $lang . '.php';
        if (!file_exists($path)) {
            $path = __DIR__ . '/../Lang/' . UI_DEFAULT_LANGUAGE . '.php';
        }

        $cache[$lang] = file_exists($path) ? require $path : [];
        return $cache[$lang];
    }
}

if (!function_exists('t')) {
    /**
     * ترجمة مفتاح لنص باللغة الحالية (أو لغة محدّدة).
     * بيرجع المفتاح نفسه لو الترجمة مش موجودة (بدل ما يفضل فاضي)،
     * عشان يبقى واضح في الواجهة إن في مفتاح ناقص لسه، مش نص مخفي.
     * دعم متغيرات بسيط: t('welcome', ['name' => 'Ahmed']) بيستبدل {name}.
     * @param string $key
     * @param array $vars
     * @param string|null $langOverride
     * @return string
     */
    function t(string $key, array $vars = [], ?string $langOverride = null): string
    {
        $lang = $langOverride ?? current_lang();
        $dict = load_translations($lang);

        $value = $dict[$key] ?? null;

        // fallback للإنجليزي لو المفتاح ناقص في اللغة المطلوبة
        if ($value === null && $lang !== UI_DEFAULT_LANGUAGE) {
            $value = load_translations(UI_DEFAULT_LANGUAGE)[$key] ?? null;
        }

        if ($value === null) {
            return $key;
        }

        if (!empty($vars)) {
            foreach ($vars as $k => $v) {
                $value = str_replace('{' . $k . '}', (string) $v, $value);
            }
        }

        return $value;
    }
}

if (!function_exists('language_switcher_links')) {
    /**
     * بيرجّع array من [code, label, url] لكل لغة مدعومة، بنفس الرابط
     * الحالي + ?lang= جديد، لاستخدامها في قائمة تبديل اللغة.
     * @return array
     */
    function language_switcher_links(): array
    {
        $labels = ['en' => 'English', 'ar' => 'العربية', 'fr' => 'Français', 'de' => 'Deutsch'];
        $current = current_lang();
        $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

        $links = [];
        foreach (UI_SUPPORTED_LANGUAGES as $code) {
            $links[] = [
                'code' => $code,
                'label' => $labels[$code] ?? strtoupper($code),
                'url' => $path . '?lang=' . $code,
                'active' => $code === $current,
            ];
        }
        return $links;
    }

    /**
     * رقم إصدار لكسر التخزين المؤقت (cache-busting) لأي أصل ثابت (CSS/JS).
     * بيرجع وقت آخر تعديل حقيقي للملف - يعني أي تعديل مستقبلي في الملف
     * بيغيّر الرقم ده تلقائيًا، فالمتصفح يعرف يجيب النسخة الجديدة رغم
     * التخزين المؤقت الطويل (30 يوم) اللي ضفناه في .htaccess.
     * @param string $relativePath زي '/assets/css/panel.css'
     */
    function asset_v(string $relativePath): string
    {
        $fullPath = defined('ROOT_PATH') ? ROOT_PATH . '/public_html' . $relativePath : (__DIR__ . '/../../public_html' . $relativePath);
        $version = @filemtime($fullPath);
        return $relativePath . ($version ? '?v=' . $version : '');
    }

    /**
     * رقم واتساب الدعم/الاشتراكات - بيقرا من إعدادات النظام القابلة
     * للتعديل من لوحة الأدمن الأول، ويرجع لـ .env كاحتياط آمن.
     */
    function support_whatsapp_number(): string
    {
        $default = defined('SUPPORT_WHATSAPP_NUMBER') ? SUPPORT_WHATSAPP_NUMBER : '';
        if (!class_exists('SystemSettingsService')) {
            return $default;
        }
        try {
            return (new SystemSettingsService())->get('support_whatsapp_number', $default);
        } catch (Exception $e) {
            return $default;
        }
    }

    /**
     * اسم الموقع - بيقرا من إعدادات النظام القابلة للتعديل من لوحة
     * الأدمن الأول، ويرجع لـ APP_NAME كاحتياط آمن.
     */
    function site_name(): string
    {
        $default = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
        if (!class_exists('SystemSettingsService')) {
            return $default;
        }
        try {
            return (new SystemSettingsService())->get('site_name', $default);
        } catch (Exception $e) {
            return $default;
        }
    }

    /**
     * HTML جاهز للوجو/اسم الموقع - لو فيه لوجو مرفوع بيعرضه بالارتفاع
     * المحدّد من الأدمن، وإلا بيرجع الاسم بس (بدون أيقونة إيموجي ثابتة).
     * @param bool $withEmoji لو true وملقاش لوجو، يحط 🌍 قبل الاسم (زي الشكل الأصلي)
     */
    function site_brand_html(bool $withEmoji = true): string
    {
        $name = htmlspecialchars(site_name(), ENT_QUOTES, 'UTF-8');
        if (!class_exists('SystemSettingsService')) {
            return $withEmoji ? "🌍 {$name}" : $name;
        }
        try {
            $settings = new SystemSettingsService();
            $logoUrl = $settings->get('site_logo_url', '');
            $logoHeight = (int) $settings->get('site_logo_height', '32') ?: 32;
            if ($logoUrl) {
                $logoUrlEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
                return "<img src=\"{$logoUrlEsc}\" alt=\"{$name}\" style=\"height:{$logoHeight}px;width:auto;vertical-align:middle;\">";
            }
        } catch (Exception $e) {
            // تجاهل - نرجع للاسم بس
        }
        return $withEmoji ? "🌍 {$name}" : $name;
    }

    /**
     * وسوم <link> جاهزة لأيقونة تبويب المتصفح (Favicon) - لو فيه أيقونة
     * مرفوعة من لوحة الأدمن بيستخدمها، وإلا بيرجع الملفات الثابتة
     * الافتراضية (نفس الموجودة في public_html/assets/icons).
     */
    function site_favicon_html(): string
    {
        $default = '<link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png">' . "\n"
            . '    <link rel="icon" type="image/png" sizes="16x16" href="/assets/icons/favicon-16.png">' . "\n"
            . '    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">';

        if (!class_exists('SystemSettingsService')) {
            return $default;
        }
        try {
            $settings = new SystemSettingsService();
            $faviconUrl = $settings->get('site_favicon_url', '');
            if ($faviconUrl) {
                $esc = htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8');
                return "<link rel=\"icon\" href=\"{$esc}\">\n    <link rel=\"apple-touch-icon\" href=\"{$esc}\">";
            }
        } catch (Exception $e) {
            // تجاهل - نرجع للأيقونة الافتراضية
        }
        return $default;
    }
}
