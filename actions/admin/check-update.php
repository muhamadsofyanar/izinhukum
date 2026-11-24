<?php
// GET/POST /actions/admin/check-update
// Proxy AJAX dari halaman admin ke License Server /api/check-update.
// Hasil di-cache di session selama 24 jam untuk menghindari spam ke server.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Pembaruan STIFIn Mulia dipasang melalui GitHub.']);
