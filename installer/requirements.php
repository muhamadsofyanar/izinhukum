<?php
// ─── Definisi kebutuhan server installer (dipakai bersama) ─────────
// Sumber tunggal agar tampilan (views/step1-requirements.php) dan guard
// server-side (actions/mark-step.php) tidak pernah drift.
//
//   installer_required_checks()     → WAJIB (blocker, harus lolos)
//   installer_recommended_checks()  → DIREKOMENDASIKAN (opsional, non-blocker)
//   installer_required_all_pass()   → true bila semua yang wajib lolos
//
// Setiap check: ['label' => string, 'detail' => string, 'pass' => bool].

if (!function_exists('installer_required_checks')) {
    function installer_required_checks(): array
    {
        $root        = dirname(__DIR__);          // installer/ → root project
        $installerDir = __DIR__;                   // folder installer/
        $uploadsDir  = $root . '/assets/uploads';
        $sqlFile     = $root . '/stifin-install.sql';

        return [
            [
                'label'  => 'Ekstensi Sodium',
                'detail' => 'Verifikasi tanda tangan lisensi Darmutiara (Ed25519).',
                'pass'   => extension_loaded('sodium'),
            ],
            [
                'label'  => 'PHP versi 8.0+',
                'detail' => '(Anda: PHP ' . PHP_VERSION . ')',
                'pass'   => version_compare(PHP_VERSION, '8.0.0', '>='),
            ],
            [
                'label'  => 'Ekstensi PDO MySQL',
                'detail' => '(pdo_mysql)',
                'pass'   => extension_loaded('pdo') && extension_loaded('pdo_mysql'),
            ],
            [
                'label'  => 'Ekstensi mbstring',
                'detail' => '',
                'pass'   => extension_loaded('mbstring'),
            ],
            [
                'label'  => 'Ekstensi JSON',
                'detail' => '',
                'pass'   => extension_loaded('json'),
            ],
            [
                'label'  => 'Ekstensi OpenSSL',
                'detail' => '',
                'pass'   => extension_loaded('openssl'),
            ],
            [
                'label'  => 'Ekstensi cURL',
                'detail' => '',
                'pass'   => extension_loaded('curl'),
            ],
            [
                'label'  => 'Ekstensi fileinfo',
                'detail' => '',
                'pass'   => extension_loaded('fileinfo'),
            ],
            [
                'label'  => 'File stifin-install.sql tersedia & terbaca',
                'detail' => '',
                'pass'   => file_exists($sqlFile) && is_readable($sqlFile),
            ],
            [
                'label'  => 'Folder utama bisa ditulis',
                'detail' => '(untuk membuat config.php)',
                'pass'   => is_writable($root),
            ],
            [
                'label'  => 'Folder installer/ bisa ditulis',
                'detail' => '(untuk menulis install.lock)',
                'pass'   => is_writable($installerDir),
            ],
            [
                'label'  => 'Folder assets/uploads/ bisa ditulis',
                'detail' => '',
                'pass'   => (is_dir($uploadsDir) && is_writable($uploadsDir))
                            || @mkdir($uploadsDir, 0755, true),
            ],
        ];
    }
}

if (!function_exists('installer_recommended_checks')) {
    function installer_recommended_checks(): array
    {
        return [
            [
                'label'  => 'Ekstensi GD',
                'detail' => 'Dibutuhkan untuk fitur Certificate Generator',
                'pass'   => extension_loaded('gd'),
            ],
            [
                'label'  => 'Ekstensi Zip',
                'detail' => 'Dibutuhkan untuk fitur Auto-Update System',
                'pass'   => extension_loaded('zip'),
            ],
        ];
    }
}

if (!function_exists('installer_required_all_pass')) {
    function installer_required_all_pass(): bool
    {
        foreach (installer_required_checks() as $c) {
            if (!$c['pass']) {
                return false;
            }
        }
        return true;
    }
}
