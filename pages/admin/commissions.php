<?php
// Digabung ke /admin/affiliate-finance (tab "Komisi"). Route lama ini dipertahankan
// sebagai redirect agar bookmark & link lama (mis. dashboard ?status=paid) tetap jalan.
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('affiliates')) { redirect('/admin/no-access'); }

$qs = $_SERVER['QUERY_STRING'] ?? '';
redirect('/admin/affiliate-finance' . ($qs !== '' ? '?' . $qs : ''));
