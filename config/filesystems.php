<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        'local' => ['driver' => 'local', 'root' => storage_path('app/private'), 'serve' => true, 'throw' => false, 'report' => false],
        // Keep user uploads beside SQLite so the existing persistent database
        // volume also preserves LMS PDFs, community attachments, and branding.
        'public' => ['driver' => 'local', 'root' => env('PUBLIC_UPLOAD_ROOT', database_path('uploads')), 'url' => env('APP_URL').'/storage', 'visibility' => 'public', 'throw' => false, 'report' => false],
    ],
    'links' => [public_path('storage') => env('PUBLIC_UPLOAD_ROOT', database_path('uploads'))],
];
