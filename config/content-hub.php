<?php

return [
    'domain' => env('CONTENT_HUB_DOMAIN', ''),
    'max_import_rows' => 5000,
    'max_broadcast_recipients' => 500,
    'abilities' => [
        'owner' => ['*'],
        'admin' => ['dashboard.view', 'content.manage', 'contacts.manage', 'contacts.export', 'message.personal', 'message.broadcast', 'history.view', 'history.retry'],
        'content' => ['dashboard.view', 'content.manage', 'history.view'],
        'customer_service' => ['dashboard.view', 'contacts.manage', 'message.personal', 'history.view'],
        'viewer' => ['dashboard.view', 'history.view'],
    ],
];
