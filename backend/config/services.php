<?php

return [

    'spcambo' => [
        'api_key_lookup_secret' => env('SP_CAMBO_API_KEY_LOOKUP_SECRET'),
        'management_key_lookup_secret' => env('SP_CAMBO_MANAGEMENT_KEY_LOOKUP_SECRET'),
        'redeem_code_lookup_secret' => env('SP_CAMBO_REDEEM_CODE_LOOKUP_SECRET'),
        'gateway_secret' => env('SP_CAMBO_INTERNAL_GATEWAY_SECRET'),
        'allow_private_provider_origins' => filter_var(env('SP_CAMBO_ALLOW_PRIVATE_PROVIDER_ORIGINS', false), FILTER_VALIDATE_BOOL),
        'gateway_base_url' => env('SP_CAMBO_GATEWAY_BASE_URL', 'http://127.0.0.1:3010'),
        'public_gateway_base_url' => env('SP_CAMBO_PUBLIC_GATEWAY_BASE_URL', env('SP_CAMBO_GATEWAY_BASE_URL', 'http://127.0.0.1:3010')),
        'playground_daily_token_quota' => (int) env('SP_CAMBO_PLAYGROUND_DAILY_TOKEN_QUOTA', 20000),
        'playground_timeout_seconds' => (int) env('SP_CAMBO_PLAYGROUND_TIMEOUT_SECONDS', 90),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'link_secret' => env('TELEGRAM_LINK_SECRET'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'timeout_seconds' => (int) env('TELEGRAM_HTTP_TIMEOUT_SECONDS', 15),
    ],

    'bakong' => [
        'base_url' => env('BAKONG_BASE_URL', 'https://api-bakong.nbc.gov.kh'),
        'token' => env('BAKONG_TOKEN'),
        'account_id' => env('BAKONG_ACCOUNT_ID'),
        'merchant_name' => env('BAKONG_MERCHANT_NAME'),
        'merchant_city' => env('BAKONG_MERCHANT_CITY', 'Phnom Penh'),
        'currency' => env('BAKONG_CURRENCY', 'USD'),
        'attempt_ttl_seconds' => (int) env('BAKONG_PAYMENT_ATTEMPT_TTL_SECONDS', 300),
        'khqr_generator_url' => env('BAKONG_KHQR_GENERATOR_URL'),
        'khqr_generator_secret' => env('BAKONG_KHQR_GENERATOR_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

];
