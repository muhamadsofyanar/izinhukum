<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/certificates');
}

$allowed = ['certificate_signatory_name','certificate_signatory_title','certificate_footer_text','certificate_bg_color','certificate_accent_color'];
foreach ($allowed as $key) {
    if (!isset($_POST[$key])) continue;
    $value = trim((string) $_POST[$key]);
    if (in_array($key, ['certificate_bg_color', 'certificate_accent_color'], true) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) continue;
    if (in_array($key, ['certificate_signatory_name','certificate_signatory_title','certificate_footer_text'], true)) $value = mb_substr(strip_tags($value), 0, 255);
    setSetting($key, $value, 'certificate');
}

logActivity('admin', 'certificate.settings_update', 'settings', null, 'Update pengaturan sertifikat');
flash('success', 'Pengaturan sertifikat disimpan.');
redirect('/admin/certificates');
