<?php
// POST /actions/admin/ai-generate-knowledge
// Tombol "Generate draft dengan AI" di form produk: susun draft AI Knowledge
// (bekal jualan untuk AI Chat) dari data produk yang sedang diisi di form.
// Memakai provider & API key konfigurasi AI Chat (aiCompleteSimple).
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('products')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Tidak memiliki akses.']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.']);
    exit;
}

// ── Lepas lock session setelah auth/CSRF: aiCompleteSimple() memanggil provider
// AI (bisa beberapa detik). Tanpa ini, lock file-sesi menahan request lain dari
// admin yang sama → form/panel terasa "loading". Tak ada tulis session setelahnya.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$name  = mb_substr(trim($_POST['name'] ?? ''), 0, 200);
$desc  = mb_substr(trim(strip_tags($_POST['description'] ?? '')), 0, 2000);
$sdesc = mb_substr(trim(strip_tags($_POST['short_description'] ?? '')), 0, 500);
$price = (float) ($_POST['price'] ?? 0);

if ($name === '') {
    echo json_encode(['success' => false, 'message' => 'Nama produk wajib diisi dulu.']);
    exit;
}
if ((string) getSetting('ai_chat_api_key', '') === '') {
    echo json_encode(['success' => false, 'message' => 'API key AI belum diatur. Buka Admin → AI Chat untuk mengisinya.']);
    exit;
}

$system = 'Kamu adalah copywriter penjualan berpengalaman untuk produk digital Indonesia. '
    . 'Tugasmu menyusun "catatan jualan" internal yang akan dipakai chatbot AI toko untuk merekomendasikan dan menjual produk. '
    . 'Tulis dalam Bahasa Indonesia, format teks polos (tanpa markdown), ringkas dan konkret. Jangan mengarang fitur yang tidak disebut di data.';

$user = "Susun catatan jualan untuk produk berikut dengan struktur PERSIS ini:\n"
    . "MANFAAT UTAMA: (3-4 poin singkat, satu per baris diawali '- ')\n"
    . "COCOK UNTUK: (1-2 kalimat siapa target pembelinya)\n"
    . "JAWABAN KEBERATAN UMUM: (2-3 pasang, format 'Keberatan: ... -> Jawaban: ...')\n\n"
    . "DATA PRODUK:\n"
    . 'Nama: ' . $name . "\n"
    . ($price > 0 ? 'Harga: ' . formatRupiah($price) . "\n" : '')
    . ($sdesc !== '' ? 'Deskripsi singkat: ' . $sdesc . "\n" : '')
    . ($desc !== '' ? 'Deskripsi lengkap: ' . $desc . "\n" : '');

$draft = aiCompleteSimple($system, $user, 700);

if ($draft === null) {
    echo json_encode(['success' => false, 'message' => 'AI tidak merespons. Cek API key & provider di Admin → AI Chat, lalu coba lagi.']);
    exit;
}

echo json_encode(['success' => true, 'draft' => mb_substr($draft, 0, 4000)]);
