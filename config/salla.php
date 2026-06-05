<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Merchant API Base URL
    |--------------------------------------------------------------------------
    | كل طلبات الـ API تروح هنا + Authorization: Bearer <token>
    */
    'api_base' => env('SALLA_API_BASE', 'https://api.salla.dev/admin/v2'),

    /*
    |--------------------------------------------------------------------------
    | OAuth 2.0 — Easy Mode
    |--------------------------------------------------------------------------
    | نقاط المصادقة وبيانات التطبيق من بوابة الشركاء.
    */
    'oauth' => [
        'token_url'      => env('SALLA_TOKEN_URL', 'https://accounts.salla.sa/oauth2/token'),
        'user_info_url'  => env('SALLA_USER_INFO_URL', 'https://accounts.salla.sa/oauth2/user/info'),
        'client_id'      => env('SALLA_CLIENT_ID'),
        'client_secret'  => env('SALLA_CLIENT_SECRET'),
        // سر التحقق من توقيع الـ Webhook (HMAC-SHA256) — من إعدادات التطبيق
        'webhook_secret' => env('SALLA_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | إعدادات المزامنة
    |--------------------------------------------------------------------------
    */
    'sync' => [
        // عدد العناصر في الصفحة الواحدة من List endpoints
        'per_page' => (int) env('SALLA_SYNC_PER_PAGE', 50),

        // backoff: ثواني الانتظار الأساسية عند HTTP 429 (يتضاعف مع كل محاولة)
        'retry_base_secs' => (int) env('SALLA_RETRY_BASE_SECS', 2),

        // عدد إعادة المحاولة القصوى قبل الفشل النهائي
        'max_retries' => (int) env('SALLA_MAX_RETRIES', 5),

        /*
        | مزامنة العملاء — معطّلة افتراضيًا لأسباب قانونية (PDPL).
        |
        | ⚠️ تحذير مهم:
        | نقل بيانات العملاء (اسم/إيميل/جوال) بين متجرين لتاجرين مختلفين
        | يخضع لنظام حماية البيانات الشخصية السعودي (PDPL).
        | لا تُفعِّل إلا للمتاجر ذات التاجر الواحد + موافقة صريحة من العميل.
        */
        'enable_customer_sync' => (bool) env('SALLA_ENABLE_CUSTOMER_SYNC', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | الـ Queue المخصصة لـ Jobs المزامنة (لعزلها عن Jobs أخرى في الإنتاج).
    */
    'queue' => env('SALLA_SYNC_QUEUE', 'salla-sync'),
];
