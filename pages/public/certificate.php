<?php
require_once __DIR__ . '/../../bootstrap.php';

$uuid = preg_replace('/[^a-f0-9\-]/', '', strtolower($_GET['uuid'] ?? ''));
if ($uuid === '') { http_response_code(404); exit('Sertifikat tidak ditemukan.'); }

try {
    $stmt = getDB()->prepare(
        "SELECT c.*, issuer.name AS issued_by_name FROM certificates c
         LEFT JOIN users issuer ON issuer.id = c.issued_by WHERE c.uuid = ? LIMIT 1"
    );
    $stmt->execute([$uuid]);
    $cert = $stmt->fetch();
} catch (Exception $e) { http_response_code(500); exit('Terjadi kesalahan.'); }

if (!$cert) { http_response_code(404); exit('Sertifikat tidak ditemukan.'); }

$appName    = getSetting('app_name', 'STIFIn Mulia');
$bg         = getSetting('certificate_bg_color', '#1e1b4b');
$accent     = getSetting('certificate_accent_color', '#6366f1');
$sigName    = getSetting('certificate_signatory_name', '');
$sigTitle   = getSetting('certificate_signatory_title', '');
$footer     = getSetting('certificate_footer_text', '');
$verifyUrl  = rtrim(APP_URL, '/') . '/certificate/' . $cert['uuid'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sertifikat — <?= e($cert['recipient_name']) ?></title>
<meta property="og:title" content="Sertifikat — <?= e($cert['recipient_name']) ?> — <?= e($cert['product_name']) ?>">
<?php if (!empty($cert['thumbnail_path'])): ?><meta property="og:image" content="<?= e(UPLOAD_URL . $cert['thumbnail_path']) ?>"><?php endif; ?>
<meta property="og:description" content="No. <?= e($cert['certificate_no']) ?> | Diterbitkan <?= date('d M Y', strtotime($cert['issued_at'])) ?>">
<style>
:root{
  --bg:<?= e($bg) ?>;
  --accent:<?= e($accent) ?>;
  --gold:#e4c67e;
  --gold-line:rgba(228,198,126,.55);
  --gold-faint:rgba(228,198,126,.20);
  --ink:#f6f7fb;
  --muted:#aab2c6;
  --line:#33405e;
}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Segoe UI',system-ui,-apple-system,Arial,sans-serif}
body{background:radial-gradient(1100px 560px at 50% -8%,#161d38,#0b1020 62%);min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:34px 16px;color:var(--ink)}
.cert-wrap{width:100%;max-width:960px;display:flex;flex-direction:column;align-items:center}
.revoked{width:100%;background:#7f1d1d;color:#fecaca;border-radius:10px;padding:11px 16px;margin-bottom:16px;font-size:13px;font-weight:700;text-align:center}

.cert-card{position:relative;width:100%;aspect-ratio:1.414/1;background:var(--bg);border-radius:8px;
  box-shadow:0 30px 70px -24px rgba(0,0,0,.65);overflow:hidden;
  -webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact}
.cert-card::after{content:'';position:absolute;inset:0;pointer-events:none;background:
  radial-gradient(circle at 50% -6%,color-mix(in srgb,var(--accent) 20%,transparent),transparent 46%),
  radial-gradient(circle at 50% 108%,rgba(228,198,126,.07),transparent 42%)}
.frame{position:absolute;inset:16px;border:1px solid var(--gold-line);border-radius:5px;pointer-events:none;z-index:2}
.frame::before{content:'';position:absolute;inset:6px;border:1px solid var(--gold-faint);border-radius:4px}
.corner{position:absolute;width:26px;height:26px;border:2px solid var(--gold);z-index:3}
.corner.tl{top:23px;left:23px;border-right:0;border-bottom:0}
.corner.tr{top:23px;right:23px;border-left:0;border-bottom:0}
.corner.bl{bottom:23px;left:23px;border-right:0;border-top:0}
.corner.br{bottom:23px;right:23px;border-left:0;border-top:0}

.cert-inner{position:relative;z-index:4;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:40px 56px}
.seal{margin-bottom:12px;line-height:0}
.brand{color:var(--gold);font-size:13px;letter-spacing:4px;text-transform:uppercase;font-weight:700}
.title{font-family:Georgia,'Times New Roman',serif;color:var(--ink);font-size:29px;font-weight:700;letter-spacing:3px;text-transform:uppercase;margin-top:9px}
.rule{display:flex;align-items:center;gap:12px;margin:13px 0 18px}
.rule i{display:block;width:74px;height:1px}
.rule i:first-child{background:linear-gradient(90deg,transparent,var(--gold))}
.rule i:last-child{background:linear-gradient(90deg,var(--gold),transparent)}
.rule b{color:var(--gold);font-size:9px;transform:rotate(45deg)}
.label{color:var(--muted);font-size:13px;letter-spacing:.4px}
.name{font-family:Georgia,'Times New Roman',serif;color:var(--gold);font-size:46px;font-weight:700;font-style:italic;line-height:1.08;margin:10px 0 16px}
.product{color:var(--ink);font-size:22px;font-weight:600;margin:8px 0 4px;max-width:82%}

.footer-row{display:flex;align-items:flex-end;justify-content:space-between;gap:26px;width:100%;max-width:660px;margin-top:34px}
.col{flex:1;text-align:center;min-width:0}
.col-val{color:var(--ink);font-size:15px;font-weight:600;min-height:24px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.col-val.sig{font-family:Georgia,serif;font-style:italic;color:var(--gold);font-size:18px}
.col-line{height:1px;background:var(--gold-line);margin:7px 0}
.col-label{color:var(--muted);font-size:10.5px;letter-spacing:1.5px;text-transform:uppercase}
.col.mid{flex:0 0 auto;padding-bottom:3px}
.verified{display:inline-flex;align-items:center;gap:6px;color:var(--gold);font-size:10px;font-weight:700;letter-spacing:1.5px;border:1px solid var(--gold-line);border-radius:999px;padding:6px 13px;white-space:nowrap}
.certno{margin-top:22px;color:var(--muted);font-size:11px;letter-spacing:2px;font-family:ui-monospace,'Courier New',monospace}
.foot-note{color:var(--muted);opacity:.75;font-size:11px;margin-top:6px}

.actions{margin-top:28px;display:flex;gap:12px;flex-wrap:wrap;justify-content:center}
.btn{padding:12px 26px;border-radius:11px;font-size:13px;font-weight:700;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:8px;transition:opacity .2s,transform .1s}
.btn:hover{opacity:.92}.btn:active{transform:scale(.98)}
.btn-p{background:var(--accent);color:#fff}
.btn-s{background:transparent;color:var(--muted);border:1px solid var(--line)}
.verify{margin-top:16px;font-size:11px;color:#5f6b85;font-family:ui-monospace,monospace;word-break:break-all;text-align:center}

@media(max-width:720px){
  .cert-card{aspect-ratio:auto;border-radius:6px}
  .cert-inner{padding:34px 22px}
  .title{font-size:20px;letter-spacing:2px}.name{font-size:32px}.product{font-size:18px;max-width:100%}
  .footer-row{flex-direction:column;gap:20px;align-items:center;margin-top:26px}
  .col{width:100%}.corner{width:18px;height:18px}
}
@media print{
  body{background:#fff;padding:0}
  .no-print{display:none!important}
  .cert-wrap{max-width:100%}
  .cert-card{box-shadow:none;border-radius:0;width:100%;aspect-ratio:1.414/1}
  @page{size:A4 landscape;margin:10mm}
}
</style>
</head>
<body>
<?php
$issuerName  = $sigName !== '' ? $sigName : $appName;
$issuerTitle = $sigName !== '' ? $sigTitle : 'Penerbit';
?>
<div class="cert-wrap">

  <?php if ($cert['status'] === 'revoked'): ?>
  <div class="revoked no-print">Sertifikat ini telah dicabut<?= $cert['revoked_at'] ? ' pada ' . date('d M Y', strtotime($cert['revoked_at'])) : '' ?>.<?= $cert['revoked_reason'] ? ' Alasan: ' . e($cert['revoked_reason']) : '' ?></div>
  <?php endif; ?>

  <div class="cert-card">
    <div class="frame"></div>
    <span class="corner tl"></span><span class="corner tr"></span><span class="corner bl"></span><span class="corner br"></span>

    <div class="cert-inner">
      <div class="seal">
        <svg width="82" height="82" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <circle cx="50" cy="50" r="47" stroke="var(--gold-line)" stroke-width="1"/>
          <circle cx="50" cy="50" r="42" stroke="var(--accent)" stroke-width="2.5"/>
          <circle cx="50" cy="50" r="33" fill="var(--accent)"/>
          <circle cx="50" cy="50" r="33" stroke="var(--gold)" stroke-width="1" stroke-dasharray="1.5 3"/>
          <path d="M37 51 l8.5 8.5 L63 40" stroke="var(--gold)" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>

      <div class="brand"><?= e($appName) ?></div>
      <div class="title">Sertifikat Penyelesaian</div>
      <div class="rule"><i></i><b>&#9670;</b><i></i></div>

      <div class="label">Dengan bangga diberikan kepada</div>
      <div class="name"><?= e($cert['recipient_name']) ?></div>

      <div class="label">atas keberhasilan menyelesaikan</div>
      <div class="product"><?= e($cert['product_name']) ?></div>

      <div class="footer-row">
        <div class="col">
          <div class="col-val"><?= date('d F Y', strtotime($cert['issued_at'])) ?></div>
          <div class="col-line"></div>
          <div class="col-label">Tanggal Terbit</div>
        </div>
        <div class="col mid">
          <span class="verified">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2l2.4 4.9 5.4.8-3.9 3.8.9 5.4L12 19.3 7.2 16.9l.9-5.4L4.2 7.7l5.4-.8z"/></svg>
            Terverifikasi
          </span>
        </div>
        <div class="col">
          <div class="col-val sig"><?= e($issuerName) ?></div>
          <div class="col-line"></div>
          <div class="col-label"><?= e($issuerTitle) ?></div>
        </div>
      </div>

      <div class="certno">No. <?= e($cert['certificate_no']) ?></div>
      <?php if ($footer !== ''): ?><div class="foot-note"><?= e($footer) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="actions no-print">
    <button onclick="window.print()" class="btn btn-p">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Unduh / Cetak
    </button>
    <a href="<?= e(APP_URL) ?>" class="btn btn-s">Kembali ke <?= e($appName) ?></a>
  </div>
  <div class="verify no-print">Verifikasi keaslian: <?= e($verifyUrl) ?></div>

</div>
</body>
</html>
