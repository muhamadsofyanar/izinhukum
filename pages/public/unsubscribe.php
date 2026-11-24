<?php
// ════════════════════════════════════════════════════════════════
// Berhenti berlangganan email promosi — /unsubscribe/{token}
// Publik, tanpa login. Token ber-HMAC atas email (helpers/broadcast.php).
// GET  = tampilkan status + tombol. POST = terapkan (unsub/resub) lalu PRG.
// Hanya memengaruhi broadcast EMAIL; email transaksional tetap terkirim.
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';

$token = (string) ($_GET['token'] ?? '');
$email = unsubscribeVerify($token);

$appName    = getSetting('app_name', 'STIFIn Mulia');
$brandColor = getSetting('brand_color', '#6366f1');

// ─── Token tidak valid ───────────────────────────────────────────
if ($email === '') {
    http_response_code(404);
    $invalid = true;
} else {
    $invalid = false;
    $pdo = getDB();
    if (function_exists('ensureUserEmailOptOutColumn')) ensureUserEmailOptOutColumn($pdo);

    // POST: terapkan aksi lalu redirect (PRG) agar refresh tak mengulang.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = ($_POST['action'] ?? '') === 'resub' ? 'resub' : 'unsub';
        $optVal = $action === 'unsub' ? 1 : 0;
        try {
            $pdo->prepare("UPDATE users SET email_opt_out = ? WHERE email = ?")->execute([$optVal, $email]);
        } catch (Throwable $e) {
            error_log('unsubscribe error: ' . $e->getMessage());
        }
        header('Location: ' . rtrim(APP_URL, '/') . '/unsubscribe/' . rawurlencode($token) . '?done=' . $action);
        exit;
    }

    // Status akun saat ini (bila ada).
    $st = $pdo->prepare("SELECT email_opt_out FROM users WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    $row      = $st->fetch();
    $hasUser  = $row !== false;
    $optedOut = $hasUser && (int) $row['email_opt_out'] === 1;
    $done     = ($_GET['done'] ?? '') === 'resub' ? 'resub' : (($_GET['done'] ?? '') === 'unsub' ? 'unsub' : '');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title>Berhenti Berlangganan — <?= e($appName) ?></title>
  <style>
    body { margin:0; font-family: Arial, Helvetica, sans-serif; background:#f3f4f6; color:#111827; }
    .wrap { max-width:460px; margin:0 auto; padding:48px 20px; }
    .card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:28px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    h1 { font-size:19px; margin:0 0 6px; }
    p { font-size:14px; line-height:1.6; color:#4b5563; margin:0 0 14px; }
    .muted { color:#9ca3af; font-size:12px; }
    .email { font-weight:700; color:#111827; word-break:break-all; }
    .btn { display:inline-block; width:100%; box-sizing:border-box; text-align:center; padding:12px 16px; border-radius:10px; font-size:15px; font-weight:700; border:0; cursor:pointer; }
    .btn-primary { color:#fff; background:<?= e($brandColor) ?>; }
    .btn-ghost { color:#374151; background:#fff; border:1px solid #d1d5db; }
    .ok { background:#ecfdf5; border:1px solid #a7f3d0; color:#047857; border-radius:10px; padding:12px 14px; font-size:13px; margin-bottom:16px; }
    form { margin:0; }
    .ic { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; margin-bottom:14px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <?php if ($invalid): ?>
        <div class="ic" style="background:#fef2f2;color:#dc2626">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <h1>Tautan tidak valid</h1>
        <p>Tautan berhenti berlangganan ini tidak sah atau sudah kedaluwarsa. Gunakan tautan terbaru dari email yang kamu terima.</p>
      <?php else: ?>
        <div class="ic" style="background: color-mix(in srgb, <?= e($brandColor) ?> 12%, transparent); color:<?= e($brandColor) ?>">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v16H4z" opacity="0"/><path d="M22 6l-10 7L2 6"/><rect x="2" y="4" width="20" height="16" rx="2"/></svg>
        </div>

        <?php if ($done === 'unsub'): ?>
          <div class="ok">Berhasil. Kamu tidak akan menerima email promosi lagi.</div>
        <?php elseif ($done === 'resub'): ?>
          <div class="ok">Berhasil. Kamu berlangganan email promosi lagi.</div>
        <?php endif; ?>

        <?php if ($optedOut): ?>
          <h1>Kamu sudah berhenti berlangganan</h1>
          <p>Email <span class="email"><?= e($email) ?></span> <strong>tidak</strong> akan menerima email promosi dari <?= e($appName) ?>. Email penting seperti konfirmasi pesanan &amp; akses produk tetap dikirim.</p>
          <form method="POST" action="<?= e(rtrim(APP_URL, '/')) ?>/unsubscribe/<?= e($token) ?>">
            <input type="hidden" name="action" value="resub">
            <button type="submit" class="btn btn-ghost">Langganan lagi</button>
          </form>
        <?php else: ?>
          <h1>Berhenti berlangganan email promosi?</h1>
          <p>Email <span class="email"><?= e($email) ?></span> akan <strong>berhenti</strong> menerima email promosi dari <?= e($appName) ?>. Email penting seperti konfirmasi pesanan &amp; akses produk tetap dikirim.</p>
          <form method="POST" action="<?= e(rtrim(APP_URL, '/')) ?>/unsubscribe/<?= e($token) ?>">
            <input type="hidden" name="action" value="unsub">
            <button type="submit" class="btn btn-primary">Ya, berhenti berlangganan</button>
          </form>
          <?php if (!$hasUser): ?>
          <p class="muted" style="margin-top:14px">Catatan: email ini belum terhubung ke akun pembeli. Permintaan tetap kami hormati untuk akun dengan email yang sama.</p>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>

      <p class="muted" style="margin-top:22px;text-align:center">Dikirim oleh <?= e($appName) ?></p>
    </div>
  </div>
</body>
</html>
