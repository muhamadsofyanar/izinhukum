<?php

return [
    'default' => env('MAIL_MAILER', 'log'),
    'mailers' => [
        'log' => ['transport' => 'log', 'channel' => env('MAIL_LOG_CHANNEL')],
        'array' => ['transport' => 'array'],
        'smtp' => ['transport' => 'smtp', 'scheme' => env('MAIL_SCHEME'), 'url' => env('MAIL_URL'), 'host' => env('MAIL_HOST', 'smtp.mailketing.id'), 'port' => env('MAIL_PORT', 587), 'username' => env('MAIL_USERNAME'), 'password' => env('MAIL_PASSWORD'), 'timeout' => null, 'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST))],
    ],
    'from' => ['address' => env('MAIL_FROM_ADDRESS', 'izinhukum@gmail.com'), 'name' => env('MAIL_FROM_NAME', 'IzinHukum')],
];
