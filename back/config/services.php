<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    // Even3 (plataforma externa de inscrição), API V2. Token só no ambiente do backend; nunca no frontend nem no Git.
    // Doc oficial: https://developers.even3.com — Authorization: Bearer <token>, uma chave por evento.
    // V1 (www.even3.com.br/api/v1) é legado/diagnóstico: não há fallback.
    'even3' => [
        'enabled' => (bool) env('EVEN3_ENABLED', false),
        'base_url' => env('EVEN3_BASE_URL') ?: 'https://api.even3.com/api/v2',
        'token' => env('EVEN3_API_TOKEN'),
        'timeout' => (int) env('EVEN3_TIMEOUT', 15),
        'connect_timeout' => (int) env('EVEN3_CONNECT_TIMEOUT', 5),
        'retries' => (int) env('EVEN3_RETRIES', 2),
        'retry_backoff_ms' => (int) env('EVEN3_RETRY_BACKOFF_MS', 500),
        'page_size' => 100, // máximo aceito pela V2
        'max_pages' => (int) env('EVEN3_MAX_PAGES', 500),
        'webhook_header' => env('EVEN3_WEBHOOK_HEADER'),
        'webhook_secret' => env('EVEN3_WEBHOOK_SECRET'),
    ],

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

];
