<?php
// ════════════════════════════════════════════════════════════════════════
// Hermes Agent — Ingest Offers (Upsell / Product Bump / Bonus Kilat)
//   GET  /api/hermes/offers                       → daftar semua offer (3 tipe)
//   GET  /api/hermes/offers?type=upsell           → filter per tipe
//   GET  /api/hermes/offers?type=bump&main=<id>   → offer utk satu produk pemicu
//   POST /api/hermes/offers                        → buat/perbarui (upsert by pasangan produk)
//
// Prinsip tergembok (selaras ingest email-sequences / funnel-gate):
//   - Offer masuk SELALU status 'inactive' (aktivasi tetap admin). Field 'status' ditolak.
//   - Idempoten via kunci ALAMI (bukan external_ref, tanpa migrasi):
//       upsell      → unik per main_product_id (1 upsell/produk pemicu)
//       bump/flash  → unik per pasangan (main_product_id, produk penawaran)
//   - TIDAK boleh mengubah offer yang sudah 'active' (admin nonaktifkan dulu) → 409.
//   - Hermes HANYA menautkan produk yang SUDAH ADA + menulis copy/harga; tak buat produk.
//   - dry_run: validasi + preview + confirm_hash, TANPA menulis.
// Auth: Bearer token + hermes_enabled (tier master; ingest draft tak butuh manage).
// ════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../helpers/hermes.php';

// Konfigurasi per tipe — satu sumber kebenaran agar logika tetap DRY.
$HERMES_OFFER_TYPES = [
    'upsell' => [
        'table'          => 'product_upsells',
        'offer_col'      => 'upsell_product_id',
        'price_col'      => 'upsell_price',   // wajib > 0
        'price_required' => true,
        'has_sort'       => false,
        'identity'       => ['main_product_id'],              // 1 upsell / produk pemicu
        'label'          => 'Upsell',
    ],
    'bump' => [
        'table'          => 'product_bumps',
        'offer_col'      => 'bump_product_id',
        'price_col'      => 'bump_price',     // opsional
        'price_required' => false,
        'has_sort'       => true,
        'identity'       => ['main_product_id', 'bump_product_id'],
        'label'          => 'Product Bump',
    ],
    'flash_bonus' => [
        'table'          => 'flash_bonus_offers',
        'offer_col'      => 'bonus_product_id',
        'price_col'      => null,             // tanpa harga (bonus gratis)
        'price_required' => false,
        'has_sort'       => true,
        'identity'       => ['main_product_id', 'bonus_product_id'],
        'label'          => 'Bonus Kilat',
    ],
];

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    hermesApiSend(405, ['ok' => false, 'error' => 'Metode tidak diizinkan. Gunakan GET atau POST.']);
}
if ($method === 'POST' && !str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    hermesApiSend(415, ['ok' => false, 'error' => 'Content-Type harus application/json.']);
}
hermesApiAuthorize(false); // master tier (draft ingest)
$pdo = getDB();

/** Serialisasi satu baris offer + nama produk (untuk readback). */
function hermesOfferOutput(PDO $pdo, string $type, array $cfg, array $row): array
{
    $offerCol = $cfg['offer_col'];
    $out = [
        'type'              => $type,
        'id'                => (int) $row['id'],
        'main_product_id'   => (int) $row['main_product_id'],
        'offer_product_id'  => (int) $row[$offerCol],
        'headline'          => $row['headline'],
        'description'       => $row['description'],
        'status'            => $row['status'],
        'created_at'        => $row['created_at'],
    ];
    if ($cfg['price_col'] !== null) {
        $out['price'] = $row[$cfg['price_col']] !== null ? (float) $row[$cfg['price_col']] : null;
    }
    if ($cfg['has_sort']) {
        $out['sort_order'] = (int) ($row['sort_order'] ?? 0);
    }
    // Nama produk (bantu Hermes verifikasi tautan).
    $pn = $pdo->prepare('SELECT id, name FROM products WHERE id IN (?, ?)');
    $pn->execute([(int) $row['main_product_id'], (int) $row[$offerCol]]);
    $names = [];
    foreach ($pn->fetchAll() as $p) $names[(int) $p['id']] = $p['name'];
    $out['main_product_name']  = $names[(int) $row['main_product_id']] ?? null;
    $out['offer_product_name'] = $names[(int) $row[$offerCol]] ?? null;
    return $out;
}

/** Ambil kolom select untuk satu tipe (termasuk price/sort bila ada). */
function hermesOfferSelectCols(array $cfg): string
{
    $cols = ['id', 'main_product_id', $cfg['offer_col'], 'headline', 'description', 'status', 'created_at'];
    if ($cfg['price_col'] !== null) $cols[] = $cfg['price_col'];
    if ($cfg['has_sort']) $cols[] = 'sort_order';
    return implode(', ', $cols);
}

// ─── GET: readback ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $typeFilter = trim((string) ($_GET['type'] ?? ''));
    $mainFilter = (int) ($_GET['main'] ?? 0);
    if ($typeFilter !== '' && !isset($HERMES_OFFER_TYPES[$typeFilter])) {
        hermesApiSend(422, ['ok' => false, 'error' => 'type tidak valid. Pilihan: ' . implode(', ', array_keys($HERMES_OFFER_TYPES)) . '.']);
    }
    $result = [];
    foreach ($HERMES_OFFER_TYPES as $type => $cfg) {
        if ($typeFilter !== '' && $typeFilter !== $type) continue;
        $sql = 'SELECT ' . hermesOfferSelectCols($cfg) . ' FROM ' . $cfg['table'];
        $params = [];
        if ($mainFilter > 0) { $sql .= ' WHERE main_product_id = ?'; $params[] = $mainFilter; }
        $sql .= $cfg['has_sort'] ? ' ORDER BY main_product_id ASC, sort_order ASC, id ASC' : ' ORDER BY id DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $r) $result[] = hermesOfferOutput($pdo, $type, $cfg, $r);
    }
    hermesApiSend(200, ['ok' => true, 'offers' => $result]);
}

// ─── POST: validasi ──────────────────────────────────────────────────────
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) hermesApiSend(400, ['ok' => false, 'error' => 'Body JSON tidak valid.']);
$allowed = ['type', 'main_product_id', 'offer_product_id', 'price', 'headline', 'description', 'sort_order', 'dry_run', 'confirm_hash'];
$unknown = array_values(array_diff(array_keys($in), $allowed));
if ($unknown) hermesApiSend(400, ['ok' => false, 'error' => 'Field tidak diizinkan: ' . implode(', ', $unknown) . '. (status selalu inactive, tak bisa diatur di sini.)']);

$type = (string) ($in['type'] ?? '');
if (!isset($HERMES_OFFER_TYPES[$type])) hermesApiSend(422, ['ok' => false, 'error' => 'type wajib & valid. Pilihan: ' . implode(', ', array_keys($HERMES_OFFER_TYPES)) . '.']);
$cfg = $HERMES_OFFER_TYPES[$type];

$mainId  = (int) ($in['main_product_id'] ?? 0);
$offerId = (int) ($in['offer_product_id'] ?? 0);
if ($mainId <= 0 || $offerId <= 0) hermesApiSend(422, ['ok' => false, 'error' => 'main_product_id & offer_product_id wajib (> 0).']);
if ($mainId === $offerId) hermesApiSend(422, ['ok' => false, 'error' => 'offer_product_id tidak boleh sama dengan main_product_id.']);

// Kedua produk harus ADA & AKTIF (konsisten dgn action admin).
$chk = $pdo->prepare("SELECT id FROM products WHERE id IN (?, ?) AND status = 'active'");
$chk->execute([$mainId, $offerId]);
if (count($chk->fetchAll()) !== 2) hermesApiSend(422, ['ok' => false, 'error' => 'main_product_id / offer_product_id tidak ditemukan atau tidak berstatus active.']);

// Harga.
$price = null;
if ($cfg['price_col'] !== null) {
    $hasPrice = array_key_exists('price', $in) && $in['price'] !== null && $in['price'] !== '';
    if ($cfg['price_required']) {
        if (!$hasPrice) hermesApiSend(422, ['ok' => false, 'error' => 'price wajib untuk tipe ' . $type . ' (harga spesial upsell, > 0).']);
        $price = (float) $in['price'];
        if ($price <= 0) hermesApiSend(422, ['ok' => false, 'error' => 'price harus > 0.']);
    } elseif ($hasPrice) {
        $price = max(0.0, (float) $in['price']);
    }
    if ($price !== null && $price > 999999999999.99) hermesApiSend(422, ['ok' => false, 'error' => 'price melebihi batas.']);
} elseif (array_key_exists('price', $in) && $in['price'] !== null && $in['price'] !== '') {
    hermesApiSend(422, ['ok' => false, 'error' => 'Tipe ' . $type . ' tidak memakai price (bonus gratis).']);
}

$headline = substr(trim((string) ($in['headline'] ?? '')), 0, 200);
$desc     = substr(trim((string) ($in['description'] ?? '')), 0, 1000);
$sortOrder = $cfg['has_sort'] ? max(0, (int) ($in['sort_order'] ?? 0)) : 0;

// ─── confirm_hash (preview == apply) ─────────────────────────────────────
$canon = json_encode([
    'type' => $type, 'main_product_id' => $mainId, 'offer_product_id' => $offerId,
    'price' => $price, 'headline' => $headline, 'description' => $desc,
    'sort_order' => ($cfg['has_sort'] ? $sortOrder : null),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$confirmHash = hash('sha256', (string) $canon);

$preview = [
    'type' => $type, 'main_product_id' => $mainId, 'offer_product_id' => $offerId,
    'headline' => $headline, 'description' => $desc, 'status' => 'inactive',
];
if ($cfg['price_col'] !== null) $preview['price'] = $price;
if ($cfg['has_sort']) $preview['sort_order'] = $sortOrder;

$dry = !empty($in['dry_run']);
if ($dry) {
    hermesApiSend(200, ['ok' => true, 'dry_run' => true, 'preview' => $preview, 'confirm_hash' => $confirmHash]);
}
if (isset($in['confirm_hash']) && !hash_equals($confirmHash, (string) $in['confirm_hash'])) {
    hermesApiSend(409, ['ok' => false, 'error' => 'confirm_hash tidak cocok dengan payload. Jalankan dry_run lagi.', 'confirm_hash' => $confirmHash]);
}

// ─── Apply (upsert by kunci alami) ───────────────────────────────────────
$table    = $cfg['table'];
$offerCol = $cfg['offer_col'];

// Query identity: upsell by main; bump/flash by (main, offer).
$idWhere  = []; $idParams = [];
foreach ($cfg['identity'] as $col) {
    $idWhere[] = "$col = ?";
    $idParams[] = ($col === $offerCol) ? $offerId : $mainId;
}
$idSql = implode(' AND ', $idWhere);

try {
    $ex = $pdo->prepare("SELECT id, status FROM $table WHERE $idSql LIMIT 1");
    $ex->execute($idParams);
    $existing = $ex->fetch();
    if ($existing && $existing['status'] === 'active') {
        hermesApiSend(409, ['ok' => false, 'error' => $cfg['label'] . ' ini sudah AKTIF. Nonaktifkan dulu di admin sebelum mengubah via Hermes.']);
    }

    // Rakit kolom dinamis per tipe.
    $pdo->beginTransaction();
    if ($existing) {
        $set = ['headline = ?', 'description = ?', "status = 'inactive'", $offerCol . ' = ?'];
        $vals = [$headline ?: null, $desc ?: null, $offerId];
        if ($cfg['price_col'] !== null) { $set[] = $cfg['price_col'] . ' = ?'; $vals[] = $price; }
        if ($cfg['has_sort'])          { $set[] = 'sort_order = ?';          $vals[] = $sortOrder; }
        $vals[] = (int) $existing['id'];
        $pdo->prepare("UPDATE $table SET " . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
        $rowId = (int) $existing['id'];
    } else {
        $cols = ['main_product_id', $offerCol, 'headline', 'description', 'status'];
        $ph   = ['?', '?', '?', '?', "'inactive'"];
        $vals = [$mainId, $offerId, $headline ?: null, $desc ?: null];
        if ($cfg['price_col'] !== null) { $cols[] = $cfg['price_col']; $ph[] = '?'; $vals[] = $price; }
        if ($cfg['has_sort'])          { $cols[] = 'sort_order';      $ph[] = '?'; $vals[] = $sortOrder; }
        try {
            $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')')->execute($vals);
            $rowId = (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            // Race kunci alami (UNIQUE) → ambil pemenang lalu update.
            if ($e->getCode() === '23000') {
                $pdo->rollBack();
                $r2 = $pdo->prepare("SELECT id, status FROM $table WHERE $idSql LIMIT 1");
                $r2->execute($idParams);
                $w = $r2->fetch();
                if (!$w) throw $e;
                if ($w['status'] === 'active') hermesApiSend(409, ['ok' => false, 'error' => $cfg['label'] . ' ini sudah AKTIF. Nonaktifkan dulu.']);
                $set = ['headline = ?', 'description = ?', "status = 'inactive'", $offerCol . ' = ?'];
                $vals = [$headline ?: null, $desc ?: null, $offerId];
                if ($cfg['price_col'] !== null) { $set[] = $cfg['price_col'] . ' = ?'; $vals[] = $price; }
                if ($cfg['has_sort'])          { $set[] = 'sort_order = ?';          $vals[] = $sortOrder; }
                $vals[] = (int) $w['id'];
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE $table SET " . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
                $rowId = (int) $w['id'];
            } else {
                throw $e;
            }
        }
    }
    $pdo->commit();
    hermesLog('offer upsert type=' . $type . ' main=' . $mainId . ' offer=' . $offerId . ' id=' . $rowId . ' ' . ($existing ? 'update' : 'create'));

    $st = $pdo->prepare('SELECT ' . hermesOfferSelectCols($cfg) . " FROM $table WHERE id = ?");
    $st->execute([$rowId]);
    hermesApiSend(200, ['ok' => true, 'created' => !$existing, 'confirm_hash' => $confirmHash, 'offer' => hermesOfferOutput($pdo, $type, $cfg, $st->fetch())]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    hermesLog('500 offer gagal type=' . $type . ' : ' . $e->getMessage());
    hermesApiSend(500, ['ok' => false, 'error' => 'Gagal menyimpan offer.']);
}
