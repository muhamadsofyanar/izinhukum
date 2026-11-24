<?php
// POST /actions/admin/hermes-token — generate/putar ulang token Hermes Agent.
// Simpan HANYA hash-nya; token mentah ditaruh sekali di session lalu ditampilkan
// SEKALI di halaman Hermes Agent (tak bisa dilihat ulang).
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/hermes-agent');
}

$raw = hermesGenerateToken();              // simpan hash, kembalikan token mentah
$_SESSION['hermes_token_once'] = $raw;      // tampil sekali di halaman berikutnya

flash('success', 'Token baru dibuat. Salin sekarang — tidak bisa dilihat ulang.');
redirect('/admin/hermes-agent');
