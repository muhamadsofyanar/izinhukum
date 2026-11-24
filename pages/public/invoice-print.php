<?php
// Halaman invoice standalone (tanpa sidebar/topbar). Print → Save as PDF.
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

// PERTAHANKAN '-'/'_' + case (order import/migrasi 'INV-20-...-hex'). Prepared statement.
$orderNumber = preg_replace('/[^A-Za-z0-9_-]/', '', trim($_GET['order_number'] ?? ''));
if ($orderNumber === '') { http_response_code(404); exit('Invoice tidak ditemukan.'); }

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        "SELECT o.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone
         FROM orders o JOIN users u ON u.id = o.user_id
         WHERE o.order_number = ? LIMIT 1"
    );
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch();
} catch (Exception $e) {
    error_log('invoice-print error: ' . $e->getMessage());
    http_response_code(500); exit('Terjadi kesalahan.');
}

if (!$order) { http_response_code(404); exit('Invoice tidak ditemukan.'); }
if (!isAdmin() && (int) $order['user_id'] !== (int) ($_SESSION['user_id'] ?? 0)) {
    http_response_code(403); exit('Akses ditolak.');
}

$itemsStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY is_bump ASC, id ASC");
$itemsStmt->execute([(int) $order['id']]);
$items = $itemsStmt->fetchAll();

$payStmt = $pdo->prepare("SELECT * FROM payments WHERE order_id = ? LIMIT 1");
$payStmt->execute([(int) $order['id']]);
$payment = $payStmt->fetch() ?: [];

$seller = [
    'name'    => getSetting('invoice_seller_name', '') ?: getSetting('app_name', 'STIFIn Mulia'),
    'address' => getSetting('invoice_seller_address', ''),
    'city'    => getSetting('invoice_seller_city', ''),
    'phone'   => getSetting('invoice_seller_phone', ''),
    'email'   => getSetting('invoice_seller_email', ''),
    'npwp'    => getSetting('invoice_seller_npwp', ''),
    'website' => getSetting('invoice_seller_website', ''),
];
$showNpwp   = getSetting('invoice_show_npwp', '0') === '1';
$showLogo   = getSetting('invoice_show_logo', '1') === '1';
$footerNote = getSetting('invoice_footer_note', 'Terima kasih atas kepercayaan Anda.');
$brandColor = getSetting('brand_color', '#6366f1');
$appLogo    = getSetting('app_logo', '');

$statusConfig = [
    'paid'      => ['label' => 'LUNAS',      'color' => '#16a34a'],
    'pending'   => ['label' => 'PENDING',    'color' => '#d97706'],
    'cancelled' => ['label' => 'DIBATALKAN', 'color' => '#dc2626'],
    'failed'    => ['label' => 'GAGAL',      'color' => '#dc2626'],
];
$statusInfo = $statusConfig[$order['status']] ?? ['label' => strtoupper($order['status']), 'color' => '#6b7280'];

$methodLabels = ['manual' => 'Transfer Manual', 'simulation' => 'Simulasi', 'duitku' => 'Duitku', 'xendit' => 'Xendit', 'moota' => 'Moota'];
$methodLabel  = $methodLabels[$payment['method'] ?? ''] ?? ucfirst($payment['method'] ?? 'Manual');
if (!empty($order['payment_channel'])) $methodLabel .= ' — ' . strtoupper($order['payment_channel']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= e($order['order_number']) ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:13px;color:#1f2937;background:#f3f4f6;padding:24px}
.invoice-wrap{max-width:794px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 1px 8px rgba(0,0,0,.08);overflow:hidden}
.inv-header{background:<?= e($brandColor) ?>;color:#fff;padding:28px 36px;display:flex;justify-content:space-between;align-items:flex-start}
.inv-header .logo-area img{max-height:48px;max-width:160px}
.inv-header .logo-area .app-name{font-size:22px;font-weight:700;letter-spacing:-.5px}
.inv-header .inv-meta{text-align:right}
.inv-header .inv-title{font-size:26px;font-weight:800;letter-spacing:1px;text-transform:uppercase;opacity:.95}
.inv-header .inv-number{font-size:13px;opacity:.85;margin-top:4px;font-family:monospace}
.inv-header .inv-date{font-size:12px;opacity:.75;margin-top:2px}
.status-badge{display:inline-block;padding:3px 12px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;border:1.5px solid currentColor}
.inv-body{padding:28px 36px}
.party-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:28px}
.party-box h3{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#6b7280;margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid #e5e7eb}
.party-box p{font-size:13px;line-height:1.6;color:#374151}
.party-box .party-name{font-weight:700;font-size:15px;color:#111827}
.inv-table{width:100%;border-collapse:collapse;margin-bottom:20px}
.inv-table thead th{padding:10px 12px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:#374151;border-bottom:2px solid <?= e($brandColor) ?>40;background:<?= e($brandColor) ?>14}
.inv-table tbody tr{border-bottom:1px solid #f3f4f6}
.inv-table tbody td{padding:12px;color:#374151}
.bump-row td{background:#fffbeb}
.totals-wrap{display:flex;justify-content:flex-end;margin-bottom:28px}
.totals-table{width:280px}
.totals-table tr td{padding:5px 0;font-size:13px;color:#374151}
.totals-table tr td:last-child{text-align:right;font-weight:500}
.totals-table .total-row td{padding-top:10px;border-top:2px solid #e5e7eb;font-size:16px;font-weight:700;color:#111827}
.totals-table .discount-row td{color:#16a34a}
.payment-info{background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:14px 16px;margin-bottom:24px}
.payment-info h3{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#6b7280;margin-bottom:10px}
.payment-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.pi-label{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px}
.pi-value{font-size:13px;font-weight:600;color:#111827}
.inv-footer{border-top:1px solid #e5e7eb;padding:16px 36px;display:flex;justify-content:space-between;align-items:center;background:#f9fafb}
.footer-note{font-size:11px;color:#6b7280}
.verify-url{font-size:10px;color:#9ca3af;font-family:monospace}
.action-bar{max-width:794px;margin:16px auto 14px;display:flex;gap:14px;justify-content:flex-end}
.btn{padding:9px 20px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.btn-primary{background:<?= e($brandColor) ?>;color:#fff}
.btn-secondary{background:#fff;color:#374151;border:1px solid #d1d5db}
@media print{
  body{background:#fff!important;padding:0!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .invoice-wrap{box-shadow:none!important;border-radius:0!important;max-width:100%!important}
  .action-bar{display:none!important}
  @page{size:A4 portrait;margin:10mm 12mm}
  .inv-table tbody tr,.totals-wrap,.payment-info{page-break-inside:avoid}
}
</style>
</head>
<body>
<div class="action-bar">
  <a href="<?= e(APP_URL) ?><?= isAdmin() ? '/admin/transactions' : '/transactions' ?>" class="btn btn-secondary">← Kembali</a>
  <button onclick="window.print()" class="btn btn-primary">Download / Print PDF</button>
</div>

<div class="invoice-wrap">
  <div class="inv-header">
    <div class="logo-area">
      <?php if ($showLogo && !empty($appLogo)): ?>
        <img src="<?= e($appLogo) ?>" alt="<?= e($seller['name']) ?>">
      <?php else: ?>
        <div class="app-name"><?= e($seller['name']) ?></div>
      <?php endif; ?>
    </div>
    <div class="inv-meta">
      <div class="inv-title">Invoice</div>
      <div class="inv-number">#<?= e($order['order_number']) ?></div>
      <div class="inv-date"><?= date('d F Y', strtotime($order['created_at'])) ?></div>
      <div style="margin-top:10px">
        <span class="status-badge" style="color:<?= e($statusInfo['color']) ?>;background:<?= e($statusInfo['color']) ?>18"><?= e($statusInfo['label']) ?></span>
      </div>
    </div>
  </div>

  <div class="inv-body">
    <div class="party-grid">
      <div class="party-box">
        <h3>Dari</h3>
        <p class="party-name"><?= e($seller['name']) ?></p>
        <?php if ($seller['email'] !== ''): ?><p><?= e($seller['email']) ?></p><?php endif; ?>
        <?php if ($seller['address'] !== ''): ?><p><?= e($seller['address']) ?><?= $seller['city'] !== '' ? ', ' . e($seller['city']) : '' ?></p><?php endif; ?>
        <?php if ($seller['phone'] !== ''): ?><p><?= e($seller['phone']) ?></p><?php endif; ?>
        <?php if ($showNpwp && $seller['npwp'] !== ''): ?><p style="color:#6b7280;font-size:11px;margin-top:4px">NPWP: <?= e($seller['npwp']) ?></p><?php endif; ?>
      </div>
      <div class="party-box">
        <h3>Kepada</h3>
        <p class="party-name"><?= e($order['user_name']) ?></p>
        <p><?= e($order['user_email']) ?></p>
        <?php if (!empty($order['user_phone'])): ?><p><?= e($order['user_phone']) ?></p><?php endif; ?>
      </div>
    </div>

    <table class="inv-table">
      <thead><tr>
        <th style="width:55%">Produk</th>
        <th style="width:10%;text-align:center">Qty</th>
        <th style="width:17.5%;text-align:right">Harga Satuan</th>
        <th style="width:17.5%;text-align:right">Subtotal</th>
      </tr></thead>
      <tbody>
        <?php foreach ($items as $item): ?>
        <tr class="<?= $item['is_bump'] ? 'bump-row' : '' ?>">
          <td><?= e($item['product_name']) ?><?php if ($item['is_bump']): ?> <span style="font-size:10px;color:#d97706;font-weight:600">(Add-on)</span><?php endif; ?></td>
          <td style="text-align:center"><?= (int) $item['quantity'] ?></td>
          <td style="text-align:right"><?= e(formatRupiah((float) $item['price'])) ?></td>
          <td style="text-align:right"><?= e(formatRupiah((float) $item['price'] * (int) $item['quantity'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="totals-wrap">
      <table class="totals-table">
        <tr><td>Subtotal</td><td><?= e(formatRupiah((float) $order['subtotal'])) ?></td></tr>
        <?php if ((float) $order['discount'] > 0): ?>
        <tr class="discount-row">
          <td>Diskon<?php if (!empty($order['coupon_code'])): ?> <span style="font-size:11px;background:#dcfce7;color:#16a34a;padding:1px 6px;border-radius:10px"><?= e($order['coupon_code']) ?></span><?php endif; ?></td>
          <td>− <?= e(formatRupiah((float) $order['discount'])) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ((float) ($order['setup_fee'] ?? 0) > 0): ?>
        <tr><td>Biaya setup awal</td><td>+ <?= e(formatRupiah((float) $order['setup_fee'])) ?></td></tr>
        <?php endif; ?>
        <tr class="total-row"><td>Total</td><td><?= e(formatRupiah((float) $order['total'])) ?></td></tr>
      </table>
    </div>

    <div class="payment-info">
      <h3>Informasi Pembayaran</h3>
      <div class="payment-grid">
        <div><div class="pi-label">Metode</div><div class="pi-value"><?= e($methodLabel) ?></div></div>
        <div><div class="pi-label">Status</div><div class="pi-value" style="color:<?= e($statusInfo['color']) ?>"><?= e($statusInfo['label']) ?></div></div>
        <div><div class="pi-label">Tanggal Bayar</div><div class="pi-value"><?= $order['paid_at'] ? date('d M Y, H:i', strtotime($order['paid_at'])) : '—' ?></div></div>
        <?php if (!empty($payment['gateway_ref'])): ?>
        <div style="grid-column:span 2"><div class="pi-label">Referensi Gateway</div><div class="pi-value" style="font-family:monospace;font-size:12px"><?= e($payment['gateway_ref']) ?></div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="inv-footer">
    <div class="footer-note"><?= e($footerNote) ?></div>
    <div class="verify-url"><?= e(rtrim(APP_URL, '/')) ?>/transactions/<?= e($order['order_number']) ?></div>
  </div>
</div>

<?php if (!empty($_GET['autoprint'])): ?>
<script>window.addEventListener('load',function(){setTimeout(function(){window.print();},600);});</script>
<?php endif; ?>
</body>
</html>
