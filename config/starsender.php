<?php

return [
    'enabled' => env('STARSENDER_ENABLED', false),
    'base_url' => rtrim((string) env('STARSENDER_BASE_URL', 'https://api.starsender.online'), '/'),
    'account_api_key' => env('STARSENDER_ACCOUNT_API_KEY'),
    'device_keys' => [
        'default' => env('STARSENDER_DEFAULT_DEVICE_KEY'),
        'transaction' => env('STARSENDER_TRANSACTION_DEVICE_KEY', env('STARSENDER_DEFAULT_DEVICE_KEY')),
        'support' => env('STARSENDER_SUPPORT_DEVICE_KEY', env('STARSENDER_DEFAULT_DEVICE_KEY')),
        'partner' => env('STARSENDER_PARTNER_DEVICE_KEY', env('STARSENDER_DEFAULT_DEVICE_KEY')),
        'campaign' => env('STARSENDER_CAMPAIGN_DEVICE_KEY', env('STARSENDER_DEFAULT_DEVICE_KEY')),
    ],
    'webhook_secret' => env('STARSENDER_WEBHOOK_SECRET'),
    'webhook_header_secret' => env('STARSENDER_WEBHOOK_HEADER_SECRET'),
    'timeout' => (int) env('STARSENDER_TIMEOUT', 20),
    'connect_timeout' => (int) env('STARSENDER_CONNECT_TIMEOUT', 5),
    'max_attempts' => (int) env('STARSENDER_MAX_ATTEMPTS', 4),
    'default_delay' => (int) env('STARSENDER_DEFAULT_DELAY', 2),
    'campaign_daily_limit' => (int) env('STARSENDER_CAMPAIGN_DAILY_LIMIT', 50),
    'rotator_enabled' => env('STARSENDER_ROTATOR_ENABLED', false),
    'premium_webhook_enabled' => env('STARSENDER_WEBHOOK_PREMIUM_ENABLED', false),
    'media_webhook_enabled' => env('STARSENDER_WEBHOOK_MEDIA_ENABLED', false),
    'group_webhook_enabled' => env('STARSENDER_WEBHOOK_GROUP_ENABLED', false),
    'webhook_retention_days' => (int) env('STARSENDER_WEBHOOK_RETENTION_DAYS', 90),
    'technical_log_retention_days' => (int) env('STARSENDER_TECHNICAL_LOG_RETENTION_DAYS', 180),
];
