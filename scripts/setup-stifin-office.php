<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config.php';
require __DIR__ . '/../db.php';
getDB()->exec(file_get_contents(__DIR__ . '/../migrations/stifin-office.sql'));
getDB()->exec(file_get_contents(__DIR__ . '/../migrations/stifin-official-reports.sql'));
echo "Migrasi kantor cabang siap. Data lama tidak dihapus.\n";
