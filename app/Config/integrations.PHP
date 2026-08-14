<?php
/**
 * Tourfecto - Integrations Registry
 * @version 1.0.0
 *
 * ==========================================================================
 *  ده "الملف الواحد" اللي بتضيف فيه أي API جديد. مش بيحتوي منطق الاتصال
 *  نفسه (ده في app/Integrations/{OAuth|ApiKey}/*.php) — بس بيقول لـ
 *  IntegrationManager: إيه الكلاس المسؤول عن الـ platform ده، ونوعه،
 *  والـ env vars اللي بيعتمد عليها.
 *
 *  عشان تضيف API جديد:
 *   1) أنشئ كلاس واحد في app/Integrations/ApiKey/ أو app/Integrations/OAuth/
 *      (فيه أمثلة جاهزة تنسخ منها).
 *   2) سجّله في المصفوفة تحت (سطر واحد).
 *   3) لو محتاج مفاتيح جديدة، ضيفها في .env.example.
 *  خلاص — الـ Controller/Service اللي هيستخدمه هيناديه عن طريق:
 *      IntegrationManager::get('openai')->request('chat', [...]);
 * ==========================================================================
 */
return [

    // ===================== AI =====================
    'openai' => [
        'label'      => 'OpenAI API',
        'category'   => 'ai',
        'class'      => 'OpenAIIntegration',
        'auth_type'  => 'api_key',
        'env_keys'   => ['OPENAI_API_KEY'],
        'enabled_env'=> 'OPENAI_ENABLED',
    ],
    'anthropic' => [
        'label'      => 'Anthropic Claude API',
        'category'   => 'ai',
        'class'      => 'AnthropicIntegration',
        'auth_type'  => 'api_key',
        'env_keys'   => ['ANTHROPIC_API_KEY'],
        'enabled_env'=> 'ANTHROPIC_ENABLED',
    ],
    'gemini' => [
        'label'      => 'Google Gemini API',
        'category'   => 'ai',
        'class'      => 'GeminiIntegration',
        'auth_type'  => 'api_key',
        'env_keys'   => ['GEMINI_API_KEY'],
        'enabled_env'=> null, // شغّالة أصلاً بدون enable flag
    ],

    // ===================== Google =====================
    'google_search_console' => [
        'label'      => 'Google Search Console API',
        'category'   => 'google',
        'class'      => 'GoogleSearchConsoleIntegration',
        'auth_type'  => 'oauth',
        'env_keys'   => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_OAUTH_REDIRECT_URI'],
        'enabled_env'=> null,
        'oauth_scope'=> 'https://www.googleapis.com/auth/webmasters.readonly',
    ],
    'google_analytics' => [
        'label'      => 'Google Analytics Data API',
        'category'   => 'google',
        'class'      => 'GoogleAnalyticsIntegration',
        'auth_type'  => 'oauth',
        'env_keys'   => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_OAUTH_REDIRECT_URI'],
        'enabled_env'=> null,
        'oauth_scope'=> 'https://www.googleapis.com/auth/analytics.readonly',
    ],
    'google_ads' => [
        'label'      => 'Google Ads API',
        'category'   => 'google',
        'class'      => 'GoogleAdsIntegration',
        'auth_type'  => 'oauth',
        'env_keys'   => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_ADS_DEVELOPER_TOKEN'],
        'enabled_env'=> null,
        'oauth_scope'=> 'https://www.googleapis.com/auth/adwords',
    ],
    'google_business' => [
        'label'      => 'Google Business Profile API',
        'category'   => 'google',
        'class'      => 'GoogleBusinessAPI', // الكلاس القديم الموجود فعلاً
        'auth_type'  => 'oauth',
        'env_keys'   => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_OAUTH_REDIRECT_URI'],
        'enabled_env'=> null,
        'oauth_scope'=> 'https://www.googleapis.com/auth/business.manage',
    ],

    // ===================== Meta =====================
    'meta_ads' => [
        'label'      => 'Meta Marketing API',
        'category'   => 'meta',
        'class'      => 'MetaOAuthClient', // الكلاس القديم الموجود فعلاً
        'auth_type'  => 'oauth',
        'env_keys'   => ['META_APP_ID', 'META_APP_SECRET', 'META_OAUTH_REDIRECT_URI'],
        'enabled_env'=> null,
        'oauth_scope'=> 'ads_management,ads_read',
    ],
    'whatsapp' => [
        'label'      => 'WhatsApp Business Platform API',
        'category'   => 'meta',
        'class'      => 'WhatsAppIntegration',
        'auth_type'  => 'api_key',
        'env_keys'   => ['WHATSAPP_PHONE_ID', 'WHATSAPP_ACCESS_TOKEN'],
        'enabled_env'=> 'WHATSAPP_ENABLED',
    ],

    // ===================== Payments =====================
    'stripe' => [
        'label'      => 'Stripe API',
        'category'   => 'payments',
        'class'      => 'StripeIntegration',
        'auth_type'  => 'api_key',
        'env_keys'   => ['STRIPE_API_KEY', 'STRIPE_WEBHOOK_SECRET'],
        'enabled_env'=> 'STRIPE_ENABLED',
    ],
    'paypal' => [
        'label'      => 'PayPal REST API',
        'category'   => 'payments',
        'class'      => 'PayPalIntegration',
        'auth_type'  => 'api_key',
        'env_keys'   => ['PAYPAL_CLIENT_ID', 'PAYPAL_CLIENT_SECRET'],
        'enabled_env'=> 'PAYPAL_ENABLED',
    ],

    // ===================== Tourism =====================
    'tripadvisor' => [
        'label'      => 'Tripadvisor API',
        'category'   => 'tourism',
        'class'      => 'TripAdvisorAPI', // الكلاس القديم الموجود فعلاً
        'auth_type'  => 'api_key',
        'env_keys'   => ['TRIPADVISOR_API_KEY'],
        'enabled_env'=> null,
    ],

    // ... أضف باقي الـ APIs بنفس الشكل وقت ما تحتاجها فعليًا (مش كلهم دفعة واحدة)
];
