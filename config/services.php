<?php

return [

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

    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'database_url' => env('FIREBASE_DATABASE_URL'),
        'project_id' => env('FIREBASE_PROJECT_ID'),
    ],

    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

    /*
    | Open Food Facts (product bot + barcode lookup). No API key required.
    | RU host first for better CIS barcode coverage; world is the fallback.
    */
    'openfoodfacts' => [
        'hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'OFF_HOSTS',
                'https://ru.openfoodfacts.org,https://world.openfoodfacts.org',
            )),
        ))),
        'cache_ttl' => (int) env('OFF_CACHE_TTL', 86400),
        'miss_cache_ttl' => (int) env('OFF_MISS_CACHE_TTL', 3600),
        'min_interval_ms' => (int) env('OFF_MIN_INTERVAL_MS', 250),
        'timeout' => (int) env('OFF_TIMEOUT', 8),
        'user_agent' => env(
            'OFF_USER_AGENT',
            'SmartPlanogramControlCenter/2.4 (product-bot; https://github.com/devopstem/sp_backend_web)',
        ),
    ],

];
