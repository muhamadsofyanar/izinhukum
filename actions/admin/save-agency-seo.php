<?php
// ─── Simpan konfigurasi Agency SEO Engine (VENDOR-ONLY) ───────────────────
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

// Menyembunyikan UI tak cukup — endpoint tetap bisa di-POST langsung. Instalasi
// non-vendor tak boleh mengubah setting ini sama sekali.
if (!function_exists('agencySeoAvailable') || !agencySeoAvailable()) {
    redirect('/admin/integrations');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/integrations');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/integrations');
}

// Peta banyak produk: as_slot[<product_id>] = jumlah slot. Slot <=0 / kosong =
// produk itu BUKAN paket agency. Mendukung beberapa produk (tier/whitelabel).
$raw     = (array) ($_POST['as_slot'] ?? []);
$map     = [];
$invalid = 0;
foreach ($raw as $pid => $slot) {
    $pid  = (int) $pid;
    $slot = (int) $slot;
    if ($pid <= 0 || $slot <= 0) continue;
    $slot = min(1000, max(1, $slot));
    try {
        $ok = getDB()->prepare("SELECT COUNT(*) FROM products WHERE id = ? AND status <> 'draft'");
        $ok->execute([$pid]);
        if ((int) $ok->fetchColumn() === 0) { $invalid++; continue; } // produk tak valid/draft → lewati
    } catch (Throwable $e) {
        error_log('save-agency-seo cek produk: ' . $e->getMessage());
    }
    $map[$pid] = $slot;
}

// Produk baru dari dropdown "+ Tambah produk" (satu per simpan).
$newPid  = (int) ($_POST['as_new_product'] ?? 0);
$newSlot = (int) ($_POST['as_new_slot'] ?? 0);
if ($newPid > 0 && $newSlot > 0) {
    $newSlot = min(1000, max(1, $newSlot));
    try {
        $ok = getDB()->prepare("SELECT COUNT(*) FROM products WHERE id = ? AND status <> 'draft'");
        $ok->execute([$newPid]);
        if ((int) $ok->fetchColumn() > 0) $map[$newPid] = $newSlot;
        else $invalid++;
    } catch (Throwable $e) {
        error_log('save-agency-seo cek produk baru: ' . $e->getMessage());
    }
}

setSetting('agency_seo_products', json_encode($map, JSON_UNESCAPED_SLASHES));
// Peta baru = sumber kebenaran. Matikan setting lama satu-produk agar tak dipakai
// sebagai fallback (mencegah kuota dobel bila produk lama juga ada di peta).
setSetting('agency_seo_product_id', '0');

$msg = 'Konfigurasi Agency SEO disimpan (' . count($map) . ' produk paket).';
if ($invalid > 0) $msg .= ' ' . $invalid . ' entri dilewati (produk tidak valid / draft).';
flash('success', $msg);
redirect('/admin/integrations');
