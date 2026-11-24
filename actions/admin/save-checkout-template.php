<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/checkout-templates');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/admin/checkout-templates');
}

$action     = trim($_POST['action'] ?? '');
$templateId = (int) ($_POST['template_id'] ?? 0);

// ── DELETE ────────────────────────────────────────────────────
if ($action === 'delete') {
    if ($templateId <= 0) {
        flash('error', 'Template tidak ditemukan.', 'error');
        redirect('/admin/checkout-templates');
    }

    try {
        $pdo = getDB();

        $chk = $pdo->prepare("SELECT id, name, is_default FROM checkout_templates WHERE id = ? LIMIT 1");
        $chk->execute([$templateId]);
        $tpl = $chk->fetch();

        if (!$tpl) {
            flash('error', 'Template tidak ditemukan.', 'error');
            redirect('/admin/checkout-templates');
        }

        if ($tpl['is_default']) {
            flash('error', 'Tidak dapat menghapus template default.', 'error');
            redirect('/admin/checkout-templates');
        }

        // Lepas dari produk yang menggunakannya
        $pdo->prepare("UPDATE products SET checkout_template_id = NULL WHERE checkout_template_id = ?")
            ->execute([$templateId]);

        $pdo->prepare("DELETE FROM checkout_templates WHERE id = ?")
            ->execute([$templateId]);

        logActivity('admin', 'checkout_template.delete', 'checkout_template', $templateId, 'Template: ' . $tpl['name']);
        flash('success', 'Template "' . $tpl['name'] . '" berhasil dihapus.');

    } catch (Exception $e) {
        error_log('Save checkout template (delete) error: ' . $e->getMessage());
        flash('error', 'Gagal menghapus template.', 'error');
    }

    redirect('/admin/checkout-templates');
}

// ── SAVE (INSERT / UPDATE) ───────────────────────────────────
if ($action !== 'save') {
    redirect('/admin/checkout-templates');
}

$name           = trim($_POST['name'] ?? '');
$allowedLayouts = ['standard', 'minimal', 'two_step', 'sidebar_sticky'];
$layoutType     = trim($_POST['layout_type'] ?? 'standard');
$showPhone      = isset($_POST['show_phone'])   ? 1 : 0;
$showAddress    = isset($_POST['show_address'])  ? 1 : 0;
$showNotes      = isset($_POST['show_notes'])    ? 1 : 0;
// Toggle kupon dipindah ke produk (products.show_coupon). Kolom template ini
// dipertahankan demi kompatibilitas skema, tapi tak lagi dipakai → selalu 1.
$showCoupon     = 1;
$fbEnabled      = isset($_POST['footer_badge_enabled']) ? 1 : 0;
$fbText         = mb_substr(trim($_POST['footer_badge_text'] ?? ''), 0, 100);
$fbIcon         = mb_substr(trim($_POST['footer_badge_icon'] ?? ''), 0, 500);
$fbUrl          = mb_substr(trim($_POST['footer_badge_url']  ?? ''), 0, 500);
// URL ikon/tautan hanya boleh http(s) — cegah javascript:/data: (XSS di src/href).
if ($fbIcon !== '' && !preg_match('#^https?://#i', $fbIcon)) $fbIcon = '';
if ($fbUrl  !== '' && !preg_match('#^https?://#i', $fbUrl))  $fbUrl  = '';
$thankYou       = trim($_POST['custom_thank_you_message'] ?? '');
$isDefault      = isset($_POST['is_default'])    ? 1 : 0;

// Validasi
$errors = [];
if (strlen($name) < 2) {
    $errors[] = 'Nama template minimal 2 karakter.';
} elseif (strlen($name) > 100) {
    $errors[] = 'Nama template maksimal 100 karakter.';
}

if (!in_array($layoutType, $allowedLayouts, true)) {
    $errors[] = 'Varian layout tidak valid.';
}

if (!empty($errors)) {
    flash('error', implode(' ', $errors), 'error');
    redirect('/admin/checkout-templates');
}

try {
    $pdo = getDB();

    // Jika set sebagai default, unset yang lain dulu
    if ($isDefault) {
        $pdo->prepare("UPDATE checkout_templates SET is_default = 0 WHERE id != ?")
            ->execute([$templateId > 0 ? $templateId : 0]);
    }

    if ($templateId > 0) {
        // Edit
        $chk = $pdo->prepare("SELECT id FROM checkout_templates WHERE id = ? LIMIT 1");
        $chk->execute([$templateId]);
        if (!$chk->fetch()) {
            flash('error', 'Template tidak ditemukan.', 'error');
            redirect('/admin/checkout-templates');
        }

        // Cek nama unik (kecuali diri sendiri)
        $nameChk = $pdo->prepare("SELECT COUNT(*) FROM checkout_templates WHERE name = ? AND id != ?");
        $nameChk->execute([$name, $templateId]);
        if ((int)$nameChk->fetchColumn() > 0) {
            flash('error', 'Nama template sudah digunakan.', 'error');
            redirect('/admin/checkout-templates');
        }

        $pdo->prepare(
            "UPDATE checkout_templates
             SET name = ?, layout_type = ?, show_phone = ?, show_address = ?, show_notes = ?,
                 show_coupon = ?, footer_badge_enabled = ?, footer_badge_text = ?, footer_badge_icon = ?, footer_badge_url = ?,
                 custom_thank_you_message = ?, is_default = ?
             WHERE id = ?"
        )->execute([$name, $layoutType, $showPhone, $showAddress, $showNotes,
                    $showCoupon, $fbEnabled, $fbText ?: null, $fbIcon ?: null, $fbUrl ?: null,
                    $thankYou ?: null, $isDefault, $templateId]);

        logActivity('admin', 'checkout_template.update', 'checkout_template', $templateId, 'Template: ' . $name);
        flash('success', 'Template "' . $name . '" berhasil diperbarui.');

    } else {
        // Insert baru
        $nameChk = $pdo->prepare("SELECT COUNT(*) FROM checkout_templates WHERE name = ?");
        $nameChk->execute([$name]);
        if ((int)$nameChk->fetchColumn() > 0) {
            flash('error', 'Nama template sudah digunakan.', 'error');
            redirect('/admin/checkout-templates');
        }

        $pdo->prepare(
            "INSERT INTO checkout_templates
             (name, layout_type, show_phone, show_address, show_notes, show_coupon,
              footer_badge_enabled, footer_badge_text, footer_badge_icon, footer_badge_url,
              custom_thank_you_message, is_default)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$name, $layoutType, $showPhone, $showAddress, $showNotes, $showCoupon,
                    $fbEnabled, $fbText ?: null, $fbIcon ?: null, $fbUrl ?: null,
                    $thankYou ?: null, $isDefault]);

        $newId = (int)$pdo->lastInsertId();
        logActivity('admin', 'checkout_template.create', 'checkout_template', $newId, 'Template: ' . $name);
        flash('success', 'Template "' . $name . '" berhasil ditambahkan.');
    }

} catch (Exception $e) {
    error_log('Save checkout template error: ' . $e->getMessage());
    flash('error', 'Gagal menyimpan template. Silakan coba lagi.', 'error');
}

redirect('/admin/checkout-templates');
