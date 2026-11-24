<?php
// ════════════════════════════════════════════════════════════════
// Magic-login akses instan — /akses-cepat/{token}
// Publik, tanpa login. Token sekali-pakai + kedaluwarsa 30 menit (helpers/magic-login.php).
// Dikirim ke pembeli BERAKUN-LAMA lewat email + WA saat pembayaran berhasil, agar
// mereka tak tersendat "harus login dulu". Hanya role 'user' (admin/staff ditolak).
// Login manual TETAP berfungsi — ini jalur tambahan, bukan pengganti.
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';

$token = (string) ($_GET['token'] ?? '');
$ctx   = consumeMagicLoginToken($token);

if ($ctx !== null) {
    // Token valid → login-kan user (mirror actions/auth/login.php).
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $ctx['user']['id'];
    $_SESSION['name']    = $ctx['user']['name'];
    $_SESSION['email']   = $ctx['user']['email'];
    $_SESSION['role']    = $ctx['user']['role'];

    if (function_exists('logActivity')) {
        logActivity('user', 'auth.magic_login', 'user', (int) $ctx['user']['id'], 'Login via magic-link akses: ' . $ctx['user']['email']);
    }

    $productId = (int) ($ctx['product_id'] ?? 0);
    redirect($productId > 0 ? '/member/' . $productId : '/member');
}

// ─── Token tidak valid / kedaluwarsa / sudah dipakai ────────────
http_response_code(410);
$appName    = getSetting('app_name', 'STIFIn Mulia');
$brandColor = getSetting('brand_color', '#6366f1');
$loginUrl   = baseUrl('login');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title>Link Akses Tidak Berlaku — <?= e($appName) ?></title>
  <style>
    :root { --brand: <?= e($brandColor) ?>; }
    * { box-sizing: border-box; }
    body { margin:0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
           background:#f4f5f7; color:#1f2430; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
    .card { background:#fff; border:1px solid #e6e8ee; border-radius:14px; max-width:440px; width:100%;
            padding:36px 32px; text-align:center; box-shadow:0 1px 3px rgba(16,24,40,.06); }
    .ic { width:56px; height:56px; margin:0 auto 18px; color:var(--brand); }
    h1 { font-size:19px; margin:0 0 10px; font-weight:700; }
    p { font-size:14px; line-height:1.6; color:#5a6473; margin:0 0 20px; }
    .btn { display:inline-block; background:var(--brand); color:#fff; text-decoration:none; padding:12px 28px;
           border-radius:10px; font-weight:700; font-size:14px; }
    .hint { font-size:12.5px; color:#8a94a6; margin-top:18px; }
  </style>
</head>
<body>
  <div class="card">
    <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <circle cx="12" cy="12" r="9"></circle>
      <path d="M12 7v5l3 2"></path>
    </svg>
    <h1>Link akses sudah tidak berlaku</h1>
    <p>Link akses cepat hanya berlaku <?= (int) MAGIC_LOGIN_TTL_MINUTES ?> menit dan hanya bisa dipakai satu kali demi keamanan akun kamu. Silakan masuk lewat halaman login seperti biasa — pesanan dan akses produk kamu tetap aman.</p>
    <a class="btn" href="<?= e($loginUrl) ?>">Masuk ke Akun</a>
    <div class="hint">Lupa password? Di halaman login ada tautan <strong>Lupa Password</strong> untuk menyetel ulang.</div>
  </div>
</body>
</html>
