<?php
/**
 * Simpan pengaturan integrasi Lisensi Vendor (Fulfillment).
 * Action terpisah (bukan save-settings generik) karena: rahasia tak boleh terhapus
 * saat field dikirim kosong, dan peta produk→plan perlu dirakit jadi JSON.
 */
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

// Menyembunyikan UI saja tidak cukup — endpoint ini tetap bisa di-POST langsung.
// Instalasi yang bukan vendor tak boleh bisa menyalakan integrasi sama sekali.
if (!function_exists('vfAvailable') || !vfAvailable()) {
    redirect('/admin/integrations');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/integrations');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/integrations');
}

try {
    $url = trim((string) ($_POST['vendor_fulfillment_url'] ?? ''));
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
        flash('error', 'URL endpoint vendor tidak valid.', 'error');
        redirect('/admin/integrations');
    }
    setSetting('vendor_fulfillment_url', $url);

    // Rahasia: kosong = PERTAHANKAN yang lama. Tanpa ini, admin yang menyimpan form
    // untuk mengubah hal lain akan menghapus rahasianya tanpa sadar → penerbitan
    // lisensi mati diam-diam.
    $secret = trim((string) ($_POST['vendor_fulfillment_secret'] ?? ''));
    if ($secret !== '') {
        setSetting('vendor_fulfillment_secret', $secret);
    }

    // Peta produk → tujuan vendor (plan + product_code). Hanya produk yang SUDAH
    // dipetakan yang tampil sebagai baris; mengosongkan slug-nya = melepas pemetaan.
    $bersihkanSlug = fn($p) => substr(preg_replace('/[^a-z0-9_-]/i', '', trim((string) $p)), 0, 50);
    // Nilai peta: STRING (legacy = produk 'averion', payload live tak berubah) bila
    // product_code kosong/averion; OBJEK {plan,product_code[,tier]} untuk produk
    // vendor lain. `tier` hanya diisi untuk Relay (menentukan kuota nomor di vendor).
    $mkEntry = function (string $plan, string $code, string $tier) {
        $code = $code !== '' ? $code : 'averion';
        if ($code === 'averion') return $plan;
        $entry = ['plan' => $plan, 'product_code' => $code];
        if ($tier !== '') $entry['tier'] = $tier;
        return $entry;
    };
    $vfCodes = (array) ($_POST['vf_code'] ?? []);
    $vfTiers = (array) ($_POST['vf_tier'] ?? []);
    $map = [];
    foreach ((array) ($_POST['vf_plan'] ?? []) as $pid => $plan) {
        $pid  = (int) $pid;
        $plan = $bersihkanSlug($plan);
        $code = $bersihkanSlug($vfCodes[$pid] ?? '');
        $tier = $bersihkanSlug($vfTiers[$pid] ?? '');
        if ($pid > 0 && $plan !== '') $map[$pid] = $mkEntry($plan, $code, $tier);
    }

    // Baris "tambah produk" (dropdown + slug plan + slug produk vendor + tier).
    // Produk lain tak lagi ditampilkan sebagai baris kosong — daftarnya cuma yang
    // benar dipakai.
    $newPid  = (int) ($_POST['vf_new_product'] ?? 0);
    $newPlan = $bersihkanSlug($_POST['vf_new_plan'] ?? '');
    $newCode = $bersihkanSlug($_POST['vf_new_code'] ?? '');
    $newTier = $bersihkanSlug($_POST['vf_new_tier'] ?? '');
    if ($newPid > 0 && $newPlan !== '') {
        $map[$newPid] = $mkEntry($newPlan, $newCode, $newTier);
    } elseif ($newPid > 0 xor $newPlan !== '') {
        // Setengah terisi = kemungkinan besar lupa, bukan sengaja. Beri tahu daripada
        // diam-diam mengabaikan.
        flash('error', 'Untuk menambah produk: pilih produknya DAN isi slug plan-nya.', 'error');
        redirect('/admin/integrations');
    }

    setSetting('vendor_fulfillment_map', $map ? json_encode($map) : '');

    $enabled = !empty($_POST['vendor_fulfillment_enabled']) ? '1' : '0';
    if ($enabled === '1') {
        if ($url === '' || trim((string) getSetting('vendor_fulfillment_secret', '')) === '') {
            setSetting('vendor_fulfillment_enabled', '0');
            flash('error', 'Isi URL endpoint & rahasia vendor dulu sebelum mengaktifkan integrasi.', 'error');
            redirect('/admin/integrations');
        }
        if (!$map) {
            setSetting('vendor_fulfillment_enabled', '0');
            flash('error', 'Petakan minimal satu produk ke plan vendor sebelum mengaktifkan integrasi.', 'error');
            redirect('/admin/integrations');
        }
    }
    setSetting('vendor_fulfillment_enabled', $enabled);

    flash('success', 'Pengaturan Lisensi Vendor disimpan — integrasi: ' . ($enabled === '1' ? 'AKTIF' : 'nonaktif')
        . ($map ? ' · ' . count($map) . ' produk dipetakan.' : '.'));
} catch (Exception $e) {
    error_log('save-vendor-fulfillment error: ' . $e->getMessage());
    flash('error', 'Gagal menyimpan pengaturan Lisensi Vendor.', 'error');
}

redirect('/admin/integrations');
