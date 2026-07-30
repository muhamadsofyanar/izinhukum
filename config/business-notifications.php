<?php

return [
    'new_order' => [
        'email' => [
            'enabled' => env('ORDER_NOTIFICATION_EMAIL_ENABLED', true),
            'recipient' => env('ORDER_NOTIFICATION_EMAIL') ?: env('ADMIN_EMAIL', env('COMPANY_EMAIL')),
        ],
        'whatsapp' => [
            'enabled' => env('ORDER_NOTIFICATION_WHATSAPP_ENABLED', true),
            'recipient' => env('ORDER_NOTIFICATION_WHATSAPP') ?: env('COMPANY_WHATSAPP'),
        ],
    ],
];
