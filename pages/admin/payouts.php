<?php
// Digabung ke /admin/affiliate-finance (tab "Pencairan"). Route lama ini dipertahankan
// sebagai redirect agar bookmark & redirect aksi payout lama tetap jalan.
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('affiliates')) { redirect('/admin/no-access'); }

redirect('/admin/affiliate-finance?tab=payouts');
