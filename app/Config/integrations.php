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
        'enabled_env' => 'OPENAI_ENABLED',
    ],
    'anthropic' => [
        'label'      => 'Anthropic Claude API',
        'category'   => 'ai',
        'class'      => 'AnthropicIntegration',
        'auth_type'  => 'api_key',
        'env_keys'   => ['ANTHROPIC_API_KEY'],
        'enabled_env' => 'ANTHROPIC_ENABLED',
    ],
    'gemini' => [
        'label'      => 'Google Gemini API',
        'category'   => 'ai',
        'class'      => 'GeminiIntegration',
        'auth_type'  => 'api_key',
        'env_keys'   => ['GEMINI_API_KEY'],
        'enabled_env' => null, // شغّالة أصلاً بدون enable flag
    ],

    // ===================== Google =====================
    'google_search_console' => [
        'label'      => 'Google Search Console API',
        'category'   => 'google',
        'class'      => 'GoogleSearchConsoleIntegration',
        'auth_type'  => 'oauth',
        'env_keys'   => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_OAUTH_REDIRECT_URI'],
        'enabled_env' => null,
        'oauth_scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
    ],
    'google_analytics' => [
        'label'      => 'Google Analytics Data API',
        'category'   => 'google',
        'class'      => 'GoogleAnalyticsIntegration',
        'auth_type'  => 'oauth',
        'env_keys'   => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_OAUTH_REDIRECT_URI'],
        'enabled_env' => null,
        'oauth_scope' => 'https://www.googleapis.com/auth/analytics.readonly',
    ],
    'google_ads' => [
        'label'      => 'Google Ads API',
        'category'   => 'google',
        'class'      => 'GoogleAdsIntegration',
        'auth_type'  => 'oauth',
        'env_keys'   => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_ADS_DEVELOPER_TOKEN'],
        'enabled_env' => null,
        'oauth_scope' => 'https://www.googleapis.com/auth/adwords',
    ],
    'google_business' => [
        'label'      => 'Google Business Profile API',
        'category'   => 'google',
        'class'      => 'GoogleBusinessAPI', // الكلاس القديم الموجود فعلاً
        'auth_type'  => 'oauth',
        'env_keys'   => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_OAUTH_REDIRECT_URI'],
        'enabled_env' => null,
        'oauth_scope' => 'https://www.googleapis.com/auth/business.manage',
    ],

    // ===================== Meta =====================
    'meta_ads' => [
        'label'      => 'Meta Marketing API',
        'category'   => 'meta',
        'class'      => 'MetaOAuthClient', // الكلاس القديم الموجود فعلاً
        'auth_type'  => 'oauth',
        'env_keys'   => ['META_APP_ID', 'META_APP_SECRET', 'META_OAUTH_REDIRECT_URI'],
        'enabled_env' => null,
        'oauth_scope' => 'ads_management,ads_read',
    ],
    'whatsapp' => [
        'label'      => 'WhatsApp Business Platform API',
        'category'   => 'meta',
        'class'      => 'WhatsAppIntegration',
        'auth_type'  => 'api_key',
        'env_keys'   => ['WHATSAPP_PHONE_ID', 'WHATSAPP_ACCESS_TOKEN'],
        'enabled_env' => 'WHATSAPP_ENABLED',
    ],

    // ===================== Payments =====================
    'stripe' => [
        'label'      => 'Stripe API',
        'category'   => 'payments',
        'class'      => 'StripeIntegration',
        'auth_type'  => 'api_key',
        'env_keys'   => ['STRIPE_API_KEY', 'STRIPE_WEBHOOK_SECRET'],
        'enabled_env' => 'STRIPE_ENABLED',
    ],
    'paypal' => [
        'label'      => 'PayPal REST API',
        'category'   => 'payments',
        'class'      => 'PayPalIntegration',
        'auth_type'  => 'api_key',
        'env_keys'   => ['PAYPAL_CLIENT_ID', 'PAYPAL_CLIENT_SECRET'],
        'enabled_env' => 'PAYPAL_ENABLED',
    ],

    // ===================== Email Marketing =====================
    // بوابة إرسال احترافية اختيارية لموديول تسويق البريد (بديل SMTP).
    // من غيرها النظام بيشتغل كامل عبر SMTP (Mailer) - بيها بنرفع معدلات
    // التسليم. التتبع (فتح/كليك/إلغاء) بيبقى على بنية Tourfecto دائمًا.
    'brevo' => [
        'label'      => 'Brevo (Sendinblue) API',
        'category'   => 'email_marketing',
        'class'      => 'BrevoIntegration',
        'auth_type'  => 'api_key',
        'env_keys'   => ['BREVO_API_KEY'],
        'enabled_env' => 'BREVO_ENABLED',
    ],

    // ===================== Tourism =====================
    'tripadvisor' => [
        'label'      => 'Tripadvisor API',
        'category'   => 'tourism',
        'class'      => 'TripAdvisorAPI', // الكلاس القديم الموجود فعلاً
        'auth_type'  => 'api_key',
        'env_keys'   => ['TRIPADVISOR_API_KEY'],
        'enabled_env' => null,
    ],

    // ===================== Third-Party (Search / Analytics / Comms / CRM) =====================
    'algolia' => [
        'label'      => 'Algolia Search',
        'category'   => 'search',
        'class'      => 'AlgoliaService',
        'auth_type'  => 'api_key',
        'env_keys'   => ['ALGOLIA_APP_ID', 'ALGOLIA_SEARCH_API_KEY'],
        'enabled_env' => 'ALGOLIA_ENABLED',
    ],
    'slack' => [
        'label'      => 'Slack',
        'category'   => 'comms',
        'class'      => 'SlackService',
        'auth_type'  => 'api_key',
        'env_keys'   => ['SLACK_BOT_TOKEN'],
        'enabled_env' => 'SLACK_ENABLED',
    ],
    'zapier' => [
        'label'      => 'Zapier',
        'category'   => 'automation',
        'class'      => 'ZapierService',
        'auth_type'  => 'api_key',
        'env_keys'   => ['ZAPIER_WEBHOOK_URL'],
        'enabled_env' => 'ZAPIER_ENABLED',
    ],
    'hubspot' => [
        'label'      => 'HubSpot CRM',
        'category'   => 'crm',
        'class'      => 'HubSpotService',
        'auth_type'  => 'api_key',
        'env_keys'   => ['HUBSPOT_API_KEY'],
        'enabled_env' => 'HUBSPOT_ENABLED',
    ],
    'zoom' => [
        'label'      => 'Zoom',
        'category'   => 'comms',
        'class'      => 'ZoomService',
        'auth_type'  => 'api_key',
        'env_keys'   => ['ZOOM_ACCOUNT_ID', 'ZOOM_CLIENT_ID', 'ZOOM_CLIENT_SECRET'],
        'enabled_env' => 'ZOOM_ENABLED',
    ],
    'mixpanel' => [
        'label'      => 'Mixpanel',
        'category'   => 'analytics',
        'class'      => 'MixpanelService',
        'auth_type'  => 'api_key',
        'env_keys'   => ['MIXPANEL_TOKEN'],
        'enabled_env' => 'MIXPANEL_ENABLED',
    ],
    'onesignal' => [
        'label'      => 'OneSignal',
        'category'   => 'comms',
        'class'      => 'OneSignalService',
        'auth_type'  => 'api_key',
        'env_keys'   => ['ONESIGNAL_APP_ID', 'ONESIGNAL_REST_API_KEY'],
        'enabled_env' => 'ONESIGNAL_ENABLED',
    ],
    'calendly' => [
        'label'      => 'Calendly',
        'category'   => 'scheduling',
        'class'      => 'CalendlyService',
        'auth_type'  => 'api_key',
        'env_keys'   => ['CALENDLY_API_TOKEN'],
        'enabled_env' => 'CALENDLY_ENABLED',
    ],
];
