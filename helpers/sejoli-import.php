<?php
/**
 * ─── Modul Import Massal Sejoli → Averion ────────────────────────────────
 *
 * Menulis LANGSUNG ke tabel users/orders/order_items/payments/member_access.
 * SENYAP TOTAL: TIDAK memanggil jalur onOrderPaid / NotificationDispatcher /
 * komisi / social-proof / Meta CAPI. Tujuannya migrasi data historis, bukan
 * transaksi baru — jadi nol notifikasi ke ratusan/ribuan nomor & email nyata.
 *
 * IDEMPOTEN: dedup order via orders.order_number UNIQUE ('SEJOLI-{INV}'),
 * payment via payments.order_id UNIQUE, akses via member_access uk_user_product.
 * Re-import setelah produk dibuat = akses retroaktif diberikan (tanpa dobel order).
 *
 * Dipakai oleh: pages/admin/data-import.php, actions/admin/data-import-*.php
 */

const SEJOLI_PLACEHOLDER_SLUG = 'transaksi-migrasi-sejoli';
const SEJOLI_PLACEHOLDER_NAME = 'Transaksi Migrasi Sejoli';
const SEJOLI_IMPORT_GATEWAY   = 'sejoli-migrasi';

/**
 * Normalisasi nama produk untuk pencocokan: lowercase, buang semua non-alnum
 * (spasi, |, `, -, dsb.) agar "AdCanvas | Personal" == "adcanvas personal".
 */
function sejoli_norm_name(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/**
 * Deteksi delimiter dari baris header (tab, koma, atau titik-koma).
 */
function sejoli_detect_delim(string $headerLine): string
{
    $tab = substr_count($headerLine, "\t");
    $com = substr_count($headerLine, ',');
    $sem = substr_count($headerLine, ';');
    if ($tab >= $com && $tab >= $sem) return "\t";
    if ($sem > $com) return ';';
    return ',';
}

/**
 * Peta nama kolom header → kolom kanonik. Case-insensitive, dukung sinonim
 * ID & EN. Kolom yang tak dikenal (mis. user_id/product_id internal Sejoli)
 * diabaikan. Return array [canonical => indexKolom].
 */
function sejoli_map_headers(array $header): array
{
    // Sinonim lintas-sumber (Sejoli, AksenSnap, ekspor CSV lain). Kunci header
    // dinormalisasi ke lowercase + non-alnum→'_' sebelum dicocokkan, jadi tulis
    // alias dalam bentuk ternormalisasi (mis. 'buyer_email', bukan 'buyer-email').
    // CATATAN: 'amount' harus lebih dulu dari kolom seperti 'amount_with_code'
    // (kode unik pembayaran) — kolom pertama yang cocok yang dipakai, jadi omzet
    // memakai nominal DASAR, bukan nominal + kode unik.
    $syn = [
        'order_number' => ['order_number', 'order', 'inv', 'invoice', 'no_invoice', 'nomor_invoice', 'invoice_code', 'invoice_number', 'kode_invoice', 'transaction_id', 'trx_id'],
        'created_at'   => ['created_at', 'date', 'tanggal', 'tgl', 'order_date', 'transaction_date', 'tgl_transaksi', 'waktu'],
        'product_name' => ['product_name', 'product', 'produk', 'nama_produk', 'item', 'item_name', 'paket'],
        'email'        => ['email', 'e_mail', 'surel', 'buyer_email', 'customer_email', 'user_email', 'email_pembeli'],
        'name'         => ['display_name', 'name', 'nama', 'customer', 'nama_lengkap', 'buyer_name', 'customer_name', 'full_name', 'fullname', 'nama_pembeli'],
        'phone'        => ['phone', 'whatsapp', 'wa', 'no_wa', 'nohp', 'no_hp', 'hp', 'telepon', 'telp', 'buyer_wa', 'buyer_phone', 'no_whatsapp', 'nomor_wa', 'msisdn'],
        'amount'       => ['amount', 'grand_total', 'total', 'price', 'harga', 'nominal', 'subtotal', 'jumlah_bayar'],
        'quantity'     => ['quantity', 'qty', 'jumlah'],
        'affiliate_name' => ['affiliate_name', 'affiliate', 'afiliasi', 'referrer', 'affiliate_code'],
        'status'       => ['status', 'order_status', 'payment_status', 'status_pembayaran', 'state'],
    ];
    $map = [];
    foreach ($header as $i => $col) {
        $key = strtolower(trim($col));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key);
        $key = trim($key, '_');
        foreach ($syn as $canon => $aliases) {
            if (isset($map[$canon])) continue;
            if (in_array($key, $aliases, true)) { $map[$canon] = $i; break; }
        }
    }
    return $map;
}

/**
 * Normalisasi angka ber-SATU jenis pemisah. >1 kemunculan = ribuan (dibuang).
 * Tepat 1 kemunculan diikuti 3 digit = ribuan (domain IDR: "175.000"=175000);
 * selain itu = desimal.
 */
function sejoli_norm_single_sep(string $s, string $sep): string
{
    if (substr_count($s, $sep) > 1) {
        return str_replace($sep, '', $s);        // ribuan
    }
    $pos   = strpos($s, $sep);
    $after = strlen($s) - $pos - 1;
    if ($after === 3) {
        return str_replace($sep, '', $s);        // ribuan (IDR)
    }
    return str_replace($sep, '.', $s);           // desimal
}

/**
 * Parse nominal uang lintas-format → float rupiah. Menangani pemisah ribuan
 * titik (ID) maupun koma (EN), desimal koma/titik, plus simbol mata uang/spasi.
 * Contoh: "1.750.000"→1750000, "1,750,000"→1750000, "Rp 99.900"→99900,
 * "175.000,50"→175000.5, "175,000.50"→175000.5, "175000"→175000.
 * Heuristik domain IDR: pemisah TUNGGAL diikuti TEPAT 3 digit = ribuan.
 */
function sejoli_parse_amount(string $raw): float
{
    $s = trim($raw);
    if ($s === '') return 0.0;
    $neg = ($s[0] === '-');
    $s = preg_replace('/[^0-9.,]/', '', $s);     // buang simbol/spasi/huruf/minus
    if ($s === '') return 0.0;

    $hasDot = strpos($s, '.') !== false;
    $hasCom = strpos($s, ',') !== false;

    if ($hasDot && $hasCom) {
        // Pemisah yang muncul PALING AKHIR = desimal; yang lain = ribuan.
        if (strrpos($s, ',') > strrpos($s, '.')) {
            $s = str_replace('.', '', $s);        // titik = ribuan
            $s = str_replace(',', '.', $s);       // koma  = desimal
        } else {
            $s = str_replace(',', '', $s);        // koma  = ribuan, titik = desimal
        }
    } elseif ($hasCom) {
        $s = sejoli_norm_single_sep($s, ',');
    } elseif ($hasDot) {
        $s = sejoli_norm_single_sep($s, '.');
    }

    $val = (float) $s;
    return $neg ? -$val : $val;
}

/**
 * Baris mentah (indexed) + headerMap → array kanonik assoc.
 */
function sejoli_row_assoc(array $raw, array $map): array
{
    $get = fn(string $k) => isset($map[$k], $raw[$map[$k]]) ? trim((string) $raw[$map[$k]]) : '';
    $order = $get('order_number');
    // Jika kolom hanya INV numerik (tanpa prefix), beri prefix SEJOLI-.
    if ($order !== '' && ctype_digit($order)) $order = 'SEJOLI-' . $order;
    return [
        'order_number'   => $order,
        'created_at'     => $get('created_at'),
        'product_name'   => $get('product_name'),
        'email'          => strtolower($get('email')),
        'name'           => $get('name'),
        'phone'          => $get('phone'),
        'amount'         => sejoli_parse_amount($get('amount')),
        'quantity'       => max(1, (int) $get('quantity')),
        'affiliate_name' => $get('affiliate_name'),
        'status'         => strtolower($get('status')),
    ];
}

/**
 * Status baris = LUNAS? Whitelist konservatif untuk data uang: bila CSV punya
 * kolom status, HANYA baris yang jelas lunas yang diimpor (baris refund/pending/
 * gagal/batal dilewati — tidak boleh masuk sebagai 'paid'). Bila TIDAK ada kolom
 * status (mis. ekspor Sejoli yang sudah difilter di sumber) → anggap lunas
 * (perilaku lama, kompatibel mundur).
 */
function sejoli_status_is_paid(string $status): bool
{
    $s = trim(strtolower($status));
    if ($s === '') return true;
    return in_array($s, [
        'paid', 'completed', 'complete', 'success', 'successful', 'settled',
        'settlement', 'capture', 'approved', 'done',
        'lunas', 'selesai', 'berhasil', 'sukses', 'dibayar',
    ], true);
}

/**
 * Validasi longgar nomor WA: setelah dinormalisasi (62...), panjang digit
 * masuk akal untuk nomor seluler (10–15 digit total, diawali 62/8/dll).
 */
function sejoli_wa_valid(string $raw): bool
{
    $n = lead_normalize_wa($raw);
    if ($n === '') return false;
    $len = strlen($n);
    return $len >= 9 && $len <= 15;
}

/**
 * Nomor WA ternormalisasi untuk disimpan (users.phone). '' bila invalid.
 */
function sejoli_wa_store(string $raw): string
{
    return sejoli_wa_valid($raw) ? lead_normalize_wa($raw) : '';
}

/**
 * Index produk Averion aktif+draft: nama ternormalisasi → id. Dibaca sekali,
 * lalu di-cache oleh pemanggil. Placeholder migrasi dikecualikan.
 */
function sejoli_product_index(PDO $pdo): array
{
    $idx = [];
    $st = $pdo->query("SELECT id, name FROM products WHERE slug <> " . $pdo->quote(SEJOLI_PLACEHOLDER_SLUG));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $key = sejoli_norm_name($p['name']);
        if ($key !== '' && !isset($idx[$key])) $idx[$key] = (int) $p['id'];
    }
    return $idx;
}

/**
 * ID produk placeholder tersembunyi (draft). Dibuat sekali saat import jalan.
 */
function sejoli_placeholder_product_id(PDO $pdo): int
{
    $st = $pdo->prepare("SELECT id FROM products WHERE slug = ? LIMIT 1");
    $st->execute([SEJOLI_PLACEHOLDER_SLUG]);
    $id = (int) $st->fetchColumn();
    if ($id > 0) return $id;

    $ins = $pdo->prepare("
        INSERT INTO products (name, slug, description, price, original_price, type, status, access_duration_days, sort_order)
        VALUES (?, ?, ?, 0, 0, 'other', 'draft', NULL, 9999)
    ");
    $ins->execute([
        SEJOLI_PLACEHOLDER_NAME,
        SEJOLI_PLACEHOLDER_SLUG,
        'Produk sistem (tersembunyi) untuk menautkan transaksi migrasi Sejoli yang produknya belum dibuat di Averion. Jangan hapus bila ingin re-import memberi akses retroaktif.',
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Resolusi produk untuk sebuah nama: override manual (productMap) → index nama.
 * Return [id|null, matched(bool)].
 */
function sejoli_resolve_product(string $productName, array $prodIdx, array $productMap): array
{
    $norm = sejoli_norm_name($productName);
    if ($norm !== '' && isset($productMap[$norm]) && (int) $productMap[$norm] > 0) {
        return [(int) $productMap[$norm], true];
    }
    if ($norm !== '' && isset($prodIdx[$norm])) {
        return [(int) $prodIdx[$norm], true];
    }
    return [null, false];
}

/**
 * Kunci identitas user untuk dedup dalam preview (tanpa DB): email bila ada,
 * else WA ternormalisasi, else nama+order (fallback unik).
 */
function sejoli_identity_key(array $row): string
{
    if ($row['email'] !== '') return 'e:' . $row['email'];
    $wa = sejoli_wa_store($row['phone']);
    if ($wa !== '') return 'w:' . $wa;
    return 'x:' . $row['order_number'];
}

/**
 * Email non-routable untuk user tanpa email (login mereka lewat WA OTP).
 * Deterministik agar dedup lintas baris & re-import konsisten.
 */
function sejoli_synthetic_email(array $row): string
{
    $wa = sejoli_wa_store($row['phone']);
    if ($wa !== '') return 'wa' . $wa . '@sejoli.local';
    return 'noid-' . substr(sha1($row['order_number']), 0, 12) . '@sejoli.local';
}

/**
 * Cari user existing berdasarkan email lalu WA. Return id|0.
 */
function sejoli_find_user(PDO $pdo, string $email, string $waStore): int
{
    if ($email !== '') {
        $st = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $st->execute([$email]);
        $id = (int) $st->fetchColumn();
        if ($id > 0) return $id;
    }
    if ($waStore !== '') {
        $st = $pdo->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
        $st->execute([$waStore]);
        $id = (int) $st->fetchColumn();
        if ($id > 0) return $id;
    }
    return 0;
}

/**
 * Beri akses migrasi TANPA memperpanjang akses yang sudah hidup.
 *
 * activateAccess() sengaja MENUMPUK expires_at (GREATEST + INTERVAL) karena
 * dirancang untuk perpanjangan langganan berbayar. Di jalur impor itu keliru:
 * re-import adalah operasi NORMAL (justru dianjurkan agar akses retroaktif
 * terbentuk), sehingga tiap pengulangan diam-diam menambah masa aktif produk
 * berjangka (terukur: 3x import = +60 hari pada produk 30 hari).
 * Karena itu: bila akses sudah ADA & masih hidup → jangan disentuh.
 *
 * Return: 'baru' (akses dibuat/dipulihkan) | 'sudah' (dibiarkan) | 'gagal'.
 */
function sejoli_grant_access(PDO $pdo, int $userId, int $productId, int $orderId): string
{
    if ($userId <= 0 || $productId <= 0) return 'gagal';

    $st = $pdo->prepare(
        "SELECT 1 FROM member_access
         WHERE user_id = ? AND product_id = ? AND status = 'active'
           AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1"
    );
    $st->execute([$userId, $productId]);
    if ($st->fetchColumn()) return 'sudah';

    return activateAccess($userId, $productId, $orderId) ? 'baru' : 'gagal';
}

/**
 * ─── Sinkronisasi akses retroaktif TANPA file CSV ────────────────────────
 *
 * Nama produk asli sudah tersimpan sebagai snapshot di order_items.product_name
 * saat impor, jadi akses menyusul TIDAK perlu unggah ulang CSV (file impor
 * memang dihapus setelah selesai). Admin tinggal membuat produk dengan nama
 * yang sama, lalu menekan Sinkronkan.
 *
 * Cakupan SENGAJA dibatasi ke order hasil migrasi (payment_gateway =
 * SEJOLI_IMPORT_GATEWAY, status paid) agar tidak pernah menyentuh order
 * reguler — mis. booking fee yang aksesnya memang belum boleh terbuka.
 *
 * Dua kasus yang ditangani:
 *   1. item masih menunjuk placeholder & namanya kini cocok → relink + akses
 *   2. item sudah menunjuk produk nyata tapi member belum punya akses
 *      (mis. dulu diimpor dengan opsi "beri akses otomatis" MATI) → beri akses
 *
 * @return array baris kandidat [id, order_id, user_id, product_name, product_id]
 */
function sejoli_sync_candidates(PDO $pdo, int $afterId = 0, int $limit = 500): array
{
    $st = $pdo->prepare(
        "SELECT oi.id, oi.order_id, oi.product_id, oi.product_name, o.user_id
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.payment_gateway = ? AND o.status = 'paid' AND oi.id > ?
         ORDER BY oi.id ASC
         LIMIT " . (int) $limit
    );
    $st->execute([SEJOLI_IMPORT_GATEWAY, $afterId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Proses satu batch sinkronisasi. $apply=false → hitung saja (dry-run).
 * Return ['last_id', 'scanned', 'relink', 'akses_baru', 'sudah', 'tak_cocok'].
 */
function sejoli_sync_batch(PDO $pdo, array $rows, array $prodIdx, ?int $placeholderId, bool $apply): array
{
    $out = ['last_id' => 0, 'scanned' => 0, 'relink' => 0, 'akses_baru' => 0, 'sudah' => 0, 'tak_cocok' => 0];

    foreach ($rows as $r) {
        $out['scanned']++;
        $out['last_id'] = (int) $r['id'];

        $itemProductId = (int) $r['product_id'];
        $userId        = (int) $r['user_id'];
        $orderId       = (int) $r['order_id'];
        $isPlaceholder = $placeholderId !== null && $itemProductId === $placeholderId;

        // Produk tujuan: item yang sudah menunjuk produk nyata dipakai apa adanya;
        // item placeholder dicocokkan ulang lewat snapshot nama produk asli.
        $targetId = null;
        if (!$isPlaceholder && $itemProductId > 0) {
            $targetId = $itemProductId;
        } else {
            $norm = sejoli_norm_name((string) $r['product_name']);
            if ($norm !== '' && isset($prodIdx[$norm])) $targetId = (int) $prodIdx[$norm];
        }

        if ($targetId === null) { $out['tak_cocok']++; continue; }

        // Sudah punya akses hidup? → tidak ada pekerjaan.
        $chk = $pdo->prepare(
            "SELECT 1 FROM member_access
             WHERE user_id = ? AND product_id = ? AND status = 'active'
               AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1"
        );
        $chk->execute([$userId, $targetId]);
        $punyaAkses = (bool) $chk->fetchColumn();

        if ($isPlaceholder) $out['relink']++;

        if ($punyaAkses) {
            $out['sudah']++;
        } else {
            $out['akses_baru']++;
        }

        if (!$apply) continue;

        if ($isPlaceholder) {
            $pdo->prepare("UPDATE order_items SET product_id = ? WHERE id = ?")
                ->execute([$targetId, (int) $r['id']]);
        }
        if (!$punyaAkses) {
            sejoli_grant_access($pdo, $userId, $targetId, $orderId);
        }
    }

    return $out;
}

/**
 * ─── Import SATU baris (dalam transaksi milik pemanggil per-baris) ───────
 *
 * $opts:
 *   grantAccess     bool  beri akses bila produk cocok (default true)
 *   includeFree     bool  ikutkan order Rp0 (default true)
 *   recordUnmatched bool  catat order yg produknya tak cocok via placeholder (default true)
 *   productMap      array [normName => averionProductId] override manual
 *
 * Return: ['status' => code, 'detail' => str]
 *   codes: imported | access_reimport | skipped_exists | skipped_free |
 *          skipped_unmatched | skipped_invalid | error
 */
function sejoli_import_row(PDO $pdo, array $row, array $opts, array &$prodIdx, ?int &$placeholderId): array
{
    $grantAccess     = $opts['grantAccess']     ?? true;
    $includeFree      = $opts['includeFree']     ?? true;
    $recordUnmatched = $opts['recordUnmatched'] ?? true;
    $productMap       = $opts['productMap']       ?? [];

    if ($row['order_number'] === '') {
        return ['status' => 'skipped_invalid', 'detail' => 'order_number kosong'];
    }
    // Status bukan-lunas (bila kolom status ada) tak pernah diimpor sebagai paid.
    if ($row['status'] !== '' && !sejoli_status_is_paid($row['status'])) {
        return ['status' => 'skipped_status', 'detail' => $row['status']];
    }
    if (!$includeFree && $row['amount'] <= 0) {
        return ['status' => 'skipped_free', 'detail' => ''];
    }

    [$productId, $matched] = sejoli_resolve_product($row['product_name'], $prodIdx, $productMap);

    // Order sudah ada? (idempoten)
    $st = $pdo->prepare("SELECT id, user_id FROM orders WHERE order_number = ? LIMIT 1");
    $st->execute([$row['order_number']]);
    $existing = $st->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Perbaiki nama placeholder/kosong dari data impor — berlaku JUGA saat
        // order sudah ada (re-impor). Ini menutup celah: impor awal tanpa kolom
        // nama menyimpan 'Member Sejoli'; file re-impor yang berisi nama asli dulu
        // tak pernah sampai ke perbaikan nama karena fungsi keburu return di bawah.
        // KONSERVATIF & idempoten: hanya timpa nama kosong/placeholder, tak pernah
        // menimpa nama yang sudah benar; tidak menyentuh order/payment/akses.
        if (trim((string) $row['name']) !== '') {
            $pdo->prepare(
                "UPDATE users SET name = ? WHERE id = ? AND (name IS NULL OR name = '' OR name = 'Member Sejoli')"
            )->execute([mb_substr($row['name'], 0, 100), (int) $existing['user_id']]);
        }
        // Re-import: order sudah ada. Bila produk kini cocok, tautkan ulang
        // order_item dari placeholder → produk asli (laporan per-produk akurat)
        // lalu beri akses retroaktif (idempoten). Tidak menyentuh order/payment.
        if ($matched) {
            if ($placeholderId === null) $placeholderId = sejoli_placeholder_product_id($pdo);
            $pdo->prepare("UPDATE order_items SET product_id = ? WHERE order_id = ? AND product_id = ?")
                ->execute([(int) $productId, (int) $existing['id'], $placeholderId]);
            if ($grantAccess) {
                // sejoli_grant_access: idempoten & TIDAK memperpanjang akses yang
                // masih hidup (re-import berulang = operasi normal di alur ini).
                $hasil = sejoli_grant_access($pdo, (int) $existing['user_id'], (int) $productId, (int) $existing['id']);
                return ['status' => $hasil === 'gagal' ? 'skipped_exists' : 'access_reimport', 'detail' => $hasil];
            }
        }
        return ['status' => 'skipped_exists', 'detail' => ''];
    }

    // Produk tak cocok & admin memilih tidak mencatat → lewati total.
    if (!$matched && !$recordUnmatched) {
        return ['status' => 'skipped_unmatched', 'detail' => $row['product_name']];
    }

    // ── Resolusi/insert user (JANGAN timpa kredensial/role akun existing) ──
    $waStore = sejoli_wa_store($row['phone']);
    $email   = $row['email'] !== '' ? $row['email'] : sejoli_synthetic_email($row);
    $userId  = sejoli_find_user($pdo, $email, $waStore);

    if ($userId === 0) {
        $name = $row['name'] !== '' ? $row['name'] : 'Member Sejoli';
        $createdAt = sejoli_valid_datetime($row['created_at']);
        try {
            $ins = $pdo->prepare("
                INSERT INTO users (name, email, password, phone, role, status, created_at)
                VALUES (?, ?, NULL, ?, 'user', 'active', ?)
            ");
            $ins->execute([mb_substr($name, 0, 100), $email, ($waStore !== '' ? $waStore : null), $createdAt]);
            $userId = (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            // Race / email duplikat tak terduga → coba temukan lagi.
            $userId = sejoli_find_user($pdo, $email, $waStore);
            if ($userId === 0) return ['status' => 'error', 'detail' => 'gagal buat user: ' . $e->getMessage()];
        }
    } else {
        // Isi WA bila akun existing belum punya (tanpa menimpa yg sudah ada).
        if ($waStore !== '') {
            $pdo->prepare("UPDATE users SET phone = ? WHERE id = ? AND (phone IS NULL OR phone = '')")
                ->execute([$waStore, $userId]);
        }
        // Betulkan nama placeholder/kosong dari data impor. Akun yang lebih dulu
        // terbuat tanpa nama (impor lama / checkout tamu) tersimpan 'Member Sejoli';
        // saat file impor punya nama asli, adopsi. KONSERVATIF: hanya timpa bila
        // nama existing kosong/placeholder — jangan sentuh nama yang sudah benar.
        // Efek: re-impor CSV memperbaiki nama tanpa menggandakan order (dedup by
        // order_number). Sinkron dgn perilaku checkout (submit-public-checkout.php).
        if (trim((string) $row['name']) !== '') {
            $pdo->prepare(
                "UPDATE users SET name = ? WHERE id = ? AND (name IS NULL OR name = '' OR name = 'Member Sejoli')"
            )->execute([mb_substr($row['name'], 0, 100), $userId]);
        }
    }

    // ── Insert order ──
    $amount    = $row['amount'];
    $createdAt = sejoli_valid_datetime($row['created_at']);
    $notes     = 'Migrasi Sejoli.' . ($row['affiliate_name'] !== '' ? ' Afiliasi: ' . $row['affiliate_name'] . '.' : '');

    // ── DEDUP KONTEN (opsional, default ON) ──────────────────────────────────
    // Dedup lama HANYA by order_number → jebol saat SOURCE punya banyak baris
    // untuk transaksi sama tapi order_number BEDA (mis. 7x "Aulora Rp175k" user
    // 6475 dgn inv58564-58574 → 6 order menggelembung). Lapisan kedua ini menolak
    // baris yang IDENTIK dgn order impor yang SUDAH ADA: user + produk (efektif) +
    // nominal + HARI created_at yang sama, khusus gateway impor. Beda HARI dianggap
    // pembelian berulang yang SAH (tak di-skip) → aman utk langganan/renewal.
    // Karena runner commit per-baris, cek DB ini juga menangkap duplikat dalam
    // satu file lintas-chunk. Bisa dimatikan via opts['dedupContent']=false.
    $effProductId = $matched ? (int) $productId : ($placeholderId ?? ($placeholderId = sejoli_placeholder_product_id($pdo)));
    if (($opts['dedupContent'] ?? true)) {
        $dayS = date('Y-m-d 00:00:00', strtotime($createdAt));
        $dayE = date('Y-m-d 23:59:59', strtotime($createdAt));
        $dc = $pdo->prepare(
            "SELECT 1 FROM orders o JOIN order_items oi ON oi.order_id = o.id
             WHERE o.user_id = ? AND oi.product_id = ? AND o.total = ?
               AND o.payment_gateway = ? AND o.created_at BETWEEN ? AND ? LIMIT 1"
        );
        $dc->execute([$userId, $effProductId, $amount, SEJOLI_IMPORT_GATEWAY, $dayS, $dayE]);
        if ($dc->fetchColumn()) {
            return ['status' => 'skipped_dup', 'detail' => 'duplikat konten (user+produk+nominal+tanggal)'];
        }
    }

    $ins = $pdo->prepare("
        INSERT INTO orders (order_number, user_id, subtotal, total, status, notes, paid_at, payment_gateway, created_at)
        VALUES (?, ?, ?, ?, 'paid', ?, ?, ?, ?)
    ");
    $ins->execute([$row['order_number'], $userId, $amount, $amount, $notes, $createdAt, SEJOLI_IMPORT_GATEWAY, $createdAt]);
    $orderId = (int) $pdo->lastInsertId();

    // ── Order item (product_id NOT NULL → placeholder bila tak cocok) ──
    $itemProductId = $productId;
    if (!$matched) {
        if ($placeholderId === null) $placeholderId = sejoli_placeholder_product_id($pdo);
        $itemProductId = $placeholderId;
    }
    $pdo->prepare("
        INSERT INTO order_items (order_id, product_id, product_name, price, quantity)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$orderId, $itemProductId, mb_substr($row['product_name'] ?: SEJOLI_PLACEHOLDER_NAME, 0, 200), $amount, $row['quantity']]);

    // ── Payment (order_id UNIQUE) ──
    $pdo->prepare("
        INSERT INTO payments (order_id, method, amount, status, paid_at, created_at)
        VALUES (?, 'manual', ?, 'success', ?, ?)
    ")->execute([$orderId, $amount, $createdAt, $createdAt]);

    // ── Akses (hanya produk cocok, lifetime kecuali produk set durasi) ──
    if ($matched && $grantAccess) {
        sejoli_grant_access($pdo, $userId, (int) $productId, $orderId);
    }

    return ['status' => 'imported', 'detail' => $matched ? 'akses' : 'placeholder'];
}

/**
 * Validasi & normalisasi datetime dari CSV → 'Y-m-d H:i:s' atau now bila invalid.
 */
function sejoli_valid_datetime(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return date('Y-m-d H:i:s');
    $ts = strtotime($raw);
    if ($ts === false || $ts <= 0) return date('Y-m-d H:i:s');
    return date('Y-m-d H:i:s', $ts);
}

/**
 * ─── Baca file & kembalikan iterator baris kanonik ──────────────────────
 * Return ['error' => str|null, 'rows' => array, 'header_map' => array, 'total' => int].
 * Membaca seluruh file (cukup untuk puluhan ribu baris).
 */
function sejoli_read_rows(string $path): array
{
    if (!is_readable($path)) return ['error' => 'File tidak terbaca.', 'rows' => [], 'header_map' => [], 'total' => 0];

    $fh = fopen($path, 'r');
    if (!$fh) return ['error' => 'Gagal membuka file.', 'rows' => [], 'header_map' => [], 'total' => 0];

    // Deteksi delimiter dari baris pertama.
    $firstLine = fgets($fh);
    if ($firstLine === false) { fclose($fh); return ['error' => 'File kosong.', 'rows' => [], 'header_map' => [], 'total' => 0]; }
    // Buang BOM UTF-8 bila ada.
    $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
    $delim = sejoli_detect_delim($firstLine);
    $header = str_getcsv(rtrim($firstLine, "\r\n"), $delim);
    $map = sejoli_map_headers($header);

    if (!isset($map['order_number'])) {
        fclose($fh);
        return ['error' => 'Kolom order/invoice tidak ditemukan di header. Wajib ada kolom order_number / INV.', 'rows' => [], 'header_map' => $map, 'total' => 0];
    }
    if (!isset($map['product_name']) || !isset($map['amount'])) {
        fclose($fh);
        return ['error' => 'Kolom product_name dan/atau amount (grand_total) tidak ditemukan.', 'rows' => [], 'header_map' => $map, 'total' => 0];
    }

    $rows = [];
    while (($raw = fgetcsv($fh, 0, $delim)) !== false) {
        if (count($raw) === 1 && ($raw[0] === null || trim((string) $raw[0]) === '')) continue;
        $rows[] = sejoli_row_assoc($raw, $map);
    }
    fclose($fh);

    return ['error' => null, 'rows' => $rows, 'header_map' => $map, 'total' => count($rows)];
}

/**
 * ─── Dry-run / pratinjau (TANPA menulis apa pun) ────────────────────────
 * Menghitung ringkasan lengkap sebelum import dijalankan.
 */
function sejoli_preview(PDO $pdo, array $rows, array $opts): array
{
    $includeFree      = $opts['includeFree']     ?? true;
    $recordUnmatched = $opts['recordUnmatched'] ?? true;
    $productMap       = $opts['productMap']       ?? [];

    $prodIdx = sejoli_product_index($pdo);

    $users        = [];      // identity key => true
    $usersNoEmail = [];
    $waInvalid    = 0;
    $trxCount     = 0;
    $trxFree      = 0;
    $omzet        = 0.0;
    $matchedTrx   = 0;
    $unmatchedTrx = 0;
    $unmatchedNames = [];    // norm => ['name' => original, 'count' => n]
    $alreadyImported = 0;
    $skippedFree     = 0;
    $skippedUnmatched= 0;
    $skippedStatus   = 0;

    // Cache order_number yg sudah ada di DB (sekali query set) untuk deteksi re-import.
    $existingOrders = [];
    $orderNumbers = array_values(array_filter(array_map(fn($r) => $r['order_number'], $rows), fn($o) => $o !== ''));
    if ($orderNumbers) {
        // Chunk IN() agar aman utk ribuan baris.
        foreach (array_chunk($orderNumbers, 1000) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("SELECT order_number FROM orders WHERE order_number IN ($ph)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $on) $existingOrders[$on] = true;
        }
    }

    foreach ($rows as $row) {
        if ($row['order_number'] === '') continue;
        if ($row['status'] !== '' && !sejoli_status_is_paid($row['status'])) { $skippedStatus++; continue; }

        $isFree = $row['amount'] <= 0;
        if (!$includeFree && $isFree) { $skippedFree++; continue; }

        [$pid, $matched] = sejoli_resolve_product($row['product_name'], $prodIdx, $productMap);
        if (!$matched && !$recordUnmatched && !isset($existingOrders[$row['order_number']])) {
            $skippedUnmatched++;
            continue;
        }

        // User dedup
        $users[sejoli_identity_key($row)] = true;
        if ($row['email'] === '') $usersNoEmail[sejoli_identity_key($row)] = true;
        if ($row['phone'] !== '' && !sejoli_wa_valid($row['phone'])) $waInvalid++;

        $trxCount++;
        if ($isFree) $trxFree++;
        $omzet += max(0, $row['amount']);

        if ($matched) $matchedTrx++;
        else {
            $unmatchedTrx++;
            $norm = sejoli_norm_name($row['product_name']);
            if (!isset($unmatchedNames[$norm])) $unmatchedNames[$norm] = ['name' => $row['product_name'], 'count' => 0];
            $unmatchedNames[$norm]['count']++;
        }

        if (isset($existingOrders[$row['order_number']])) $alreadyImported++;
    }

    // Urutkan produk tak cocok berdasar jumlah transaksi (desc).
    uasort($unmatchedNames, fn($a, $b) => $b['count'] <=> $a['count']);

    return [
        'total_rows'        => count($rows),
        'trx_diproses'      => $trxCount,
        'trx_baru'          => $trxCount - $alreadyImported,
        'trx_sudah_ada'     => $alreadyImported,
        'trx_gratis'        => $trxFree,
        'trx_dilewati_gratis'    => $skippedFree,
        'trx_dilewati_unmatched' => $skippedUnmatched,
        'trx_dilewati_status'    => $skippedStatus,
        'user_unik'         => count($users),
        'user_tanpa_email'  => count($usersNoEmail),
        'wa_invalid'        => $waInvalid,
        'omzet'             => $omzet,
        'produk_cocok_trx'  => $matchedTrx,
        'produk_tak_cocok_trx' => $unmatchedTrx,
        'produk_tak_cocok'  => array_values($unmatchedNames),
    ];
}

/* ═══════════════════════════════════════════════════════════════════════
 *  PEMBERSIHAN OMZET IMPOR — nol-kan nominal order migrasi per produk
 *  ─────────────────────────────────────────────────────────────────────
 *  Masalah: sebagian transaksi hasil migrasi (Sejoli/AksenSnap) terbawa
 *  nominal yang keliru (mis. produk bonus/gratis ter-impor Rp 7 jt) → omzet
 *  dashboard menggelembung. Admin memilih PRODUK yang nominalnya salah, lalu
 *  order impor yang menempel ke produk itu di-NOL-kan (total, subtotal, harga
 *  item, payment amount). Riwayat transaksi & akses member TETAP utuh.
 *
 *  PENGAMAN MUTLAK: semua operasi dibatasi payment_gateway = SEJOLI_IMPORT_GATEWAY
 *  dan status 'paid'. Order checkout ASLI tidak pernah tersentuh, walau
 *  kebetulan produknya sama.
 *
 *  REVERSIBEL: nilai lama di-backup ke storage/imports/cleanup/{batch}.json
 *  sebelum UPDATE → bisa di-undo kapan pun. Tanpa perubahan skema DB.
 * ═══════════════════════════════════════════════════════════════════════ */

/** Folder backup pembersihan (dibuat on-demand, ditolak dari web). */
function sejoli_cleanup_dir(): string
{
    $dir = __DIR__ . '/../storage/imports/cleanup';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    return $dir;
}

/** ID produk placeholder migrasi (atau null bila belum ada). */
function sejoli_cleanup_placeholder_id(PDO $pdo): ?int
{
    $st = $pdo->prepare("SELECT id FROM products WHERE slug = ? LIMIT 1");
    $st->execute([SEJOLI_PLACEHOLDER_SLUG]);
    $id = (int) $st->fetchColumn();
    return $id > 0 ? $id : null;
}

/**
 * Daftar GRUP yang bisa dipilih admin untuk dibersihkan.
 * - Produk ASLI (ter-match, non-placeholder) → satu baris per produk (key `p:<id>`).
 * - Placeholder (CuanVirtual - Area dll.) menampung banyak produk asli berbeda →
 *   DIPECAH per nama produk snapshot (`order_items.product_name`), satu baris
 *   tiap nama (key `n:<rawurlencode(nama)>`), supaya bisa nol-kan HANYA 1
 *   produk di dalam placeholder, bukan seluruh bucket.
 * Hanya menghitung order migrasi berstatus 'paid' dgn omzet > 0.
 *
 * @return array<int,array{key:string,product_id:int,name:string,group:string,
 *   n_order:int,omzet:float,max_nominal:float}>
 */
function sejoli_cleanup_groups(PDO $pdo): array
{
    $placeholder = sejoli_cleanup_placeholder_id($pdo);
    $out = [];

    // 1) Produk asli (bukan placeholder) — grup per product_id.
    $st = $pdo->prepare(
        "SELECT oi.product_id,
                p.name                       AS pname,
                COUNT(DISTINCT o.id)         AS n_order,
                SUM(oi.price * oi.quantity)  AS omzet,
                MAX(oi.price)                AS max_nominal
         FROM orders o
         JOIN order_items oi ON oi.order_id = o.id
         JOIN products p     ON p.id = oi.product_id
         WHERE o.payment_gateway = ? AND o.status = 'paid' AND p.slug <> ?
         GROUP BY oi.product_id
         HAVING omzet > 0
         ORDER BY omzet DESC"
    );
    $st->execute([SEJOLI_IMPORT_GATEWAY, SEJOLI_PLACEHOLDER_SLUG]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'key'         => 'p:' . (int) $r['product_id'],
            'product_id'  => (int) $r['product_id'],
            'name'        => (string) ($r['pname'] !== null && $r['pname'] !== '' ? $r['pname'] : 'Produk #' . (int) $r['product_id']),
            'group'       => 'product',
            'n_order'     => (int) $r['n_order'],
            'omzet'       => (float) $r['omzet'],
            'max_nominal' => (float) $r['max_nominal'],
        ];
    }

    // 2) Placeholder — grup per nama produk snapshot (sub-produk di dalamnya).
    if ($placeholder) {
        $st = $pdo->prepare(
            "SELECT oi.product_name,
                    COUNT(DISTINCT o.id)         AS n_order,
                    SUM(oi.price * oi.quantity)  AS omzet,
                    MAX(oi.price)                AS max_nominal
             FROM orders o
             JOIN order_items oi ON oi.order_id = o.id
             WHERE o.payment_gateway = ? AND o.status = 'paid' AND oi.product_id = ?
             GROUP BY oi.product_name
             HAVING omzet > 0
             ORDER BY omzet DESC"
        );
        $st->execute([SEJOLI_IMPORT_GATEWAY, $placeholder]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $nm = (string) ($r['product_name'] ?? '');
            $out[] = [
                'key'         => 'n:' . rawurlencode($nm),
                'product_id'  => $placeholder,
                'name'        => ($nm !== '' ? $nm : '(tanpa nama)'),
                'group'       => 'placeholder',
                'n_order'     => (int) $r['n_order'],
                'omzet'       => (float) $r['omzet'],
                'max_nominal' => (float) $r['max_nominal'],
            ];
        }
    }

    return $out;
}

/**
 * Parse daftar key seleksi → target ternormalisasi.
 * key `p:<id>`      → ['pid'=>id, 'name'=>null]   (seluruh produk asli)
 * key `n:<rawname>` → ['pid'=>placeholderId, 'name'=>nama]  (1 sub-produk placeholder)
 *
 * @return array<int,array{pid:int,name:?string}>
 */
function sejoli_cleanup_parse_keys(array $keys, PDO $pdo): array
{
    $placeholder = sejoli_cleanup_placeholder_id($pdo);
    $targets = [];
    foreach ($keys as $k) {
        $k = (string) $k;
        if (strncmp($k, 'p:', 2) === 0) {
            $id = (int) substr($k, 2);
            if ($id > 0) $targets[] = ['pid' => $id, 'name' => null];
        } elseif (strncmp($k, 'n:', 2) === 0 && $placeholder) {
            $targets[] = ['pid' => $placeholder, 'name' => rawurldecode(substr($k, 2))];
        }
    }
    return $targets;
}

/**
 * Bangun kondisi SQL (OR antar target) + parameternya untuk klausa WHERE.
 * Caller menambahkan filter gateway/status/price sendiri.
 *
 * @return array{0:string,1:array}  [condSql, params]; '0' bila kosong.
 */
function sejoli_cleanup_cond(array $targets): array
{
    $parts = [];
    $params = [];
    foreach ($targets as $t) {
        if ($t['name'] === null) {
            $parts[] = '(oi.product_id = ?)';
            $params[] = (int) $t['pid'];
        } else {
            $parts[] = '(oi.product_id = ? AND oi.product_name = ?)';
            $params[] = (int) $t['pid'];
            $params[] = (string) $t['name'];
        }
    }
    if (!$parts) return ['0', []];
    return ['(' . implode(' OR ', $parts) . ')', $params];
}

/**
 * Pratinjau: untuk sekumpulan key seleksi, berapa order & omzet akan dinol-kan.
 * Tidak menulis apa pun.
 *
 * @return array{orders:int,items:int,omzet:float}
 */
function sejoli_cleanup_preview(PDO $pdo, array $keys): array
{
    $targets = sejoli_cleanup_parse_keys($keys, $pdo);
    if (!$targets) return ['orders' => 0, 'items' => 0, 'omzet' => 0.0];
    [$cond, $cp] = sejoli_cleanup_cond($targets);
    $st = $pdo->prepare(
        "SELECT COUNT(DISTINCT o.id)         AS orders,
                COUNT(oi.id)                 AS items,
                SUM(oi.price * oi.quantity)  AS omzet
         FROM orders o
         JOIN order_items oi ON oi.order_id = o.id
         WHERE o.payment_gateway = ? AND o.status = 'paid'
           AND oi.price > 0 AND $cond"
    );
    $st->execute(array_merge([SEJOLI_IMPORT_GATEWAY], $cp));
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'orders' => (int) ($r['orders'] ?? 0),
        'items'  => (int) ($r['items'] ?? 0),
        'omzet'  => (float) ($r['omzet'] ?? 0),
    ];
}

/**
 * Terapkan nol-kan nominal untuk product_id terpilih.
 * - order_items.price → 0 (hanya item produk terpilih, di order migrasi paid)
 * - orders.subtotal/total → dihitung ulang dari sisa item (aman utk multi-item)
 * - payments.amount → disamakan dgn total baru
 * Backup penuh ditulis ke JSON sebelum commit. Dibungkus transaksi.
 *
 * @return array{batch:string,orders:int,items:int,omzet:float}
 */
function sejoli_cleanup_apply(PDO $pdo, array $keys, int $adminId): array
{
    $sel = sejoli_cleanup_parse_keys($keys, $pdo);
    $empty = ['batch' => '', 'orders' => 0, 'items' => 0, 'omzet' => 0.0];
    if (!$sel) return $empty;
    [$cond, $cp] = sejoli_cleanup_cond($sel);

    // Kumpulkan item target (hanya order migrasi paid, harga > 0).
    $st = $pdo->prepare(
        "SELECT oi.id AS item_id, oi.order_id, oi.price, oi.quantity
         FROM orders o
         JOIN order_items oi ON oi.order_id = o.id
         WHERE o.payment_gateway = ? AND o.status = 'paid'
           AND oi.price > 0 AND $cond
         ORDER BY oi.order_id, oi.id"
    );
    $st->execute(array_merge([SEJOLI_IMPORT_GATEWAY], $cp));
    $targets = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$targets) return $empty;

    $byOrder = [];   // order_id => [ ['id'=>item_id, 'old_price'=>x], ... ]
    $omzet   = 0.0;
    foreach ($targets as $t) {
        $byOrder[(int) $t['order_id']][] = ['id' => (int) $t['item_id'], 'old_price' => (float) $t['price']];
        $omzet += (float) $t['price'] * (int) $t['quantity'];
    }

    $batch  = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $backup = [
        'batch'       => $batch,
        'created_at'  => date('Y-m-d H:i:s'),
        'admin_id'    => $adminId,
        'keys'        => array_values($keys),
        'action'      => 'zero',
        'reverted_at' => null,
        'orders'      => [],
    ];

    $pdo->beginTransaction();
    try {
        $lockOrder = $pdo->prepare("SELECT subtotal, discount, total FROM orders WHERE id = ? AND payment_gateway = ? FOR UPDATE");
        $getPays   = $pdo->prepare("SELECT id, amount FROM payments WHERE order_id = ?");
        $zeroItem  = $pdo->prepare("UPDATE order_items SET price = 0 WHERE id = ?");
        $sumItems  = $pdo->prepare("SELECT COALESCE(SUM(price * quantity), 0) FROM order_items WHERE order_id = ?");
        $updOrder  = $pdo->prepare("UPDATE orders SET subtotal = ?, total = ? WHERE id = ? AND payment_gateway = ?");
        $updPay    = $pdo->prepare("UPDATE payments SET amount = ? WHERE order_id = ?");

        foreach ($byOrder as $oid => $items) {
            $lockOrder->execute([$oid, SEJOLI_IMPORT_GATEWAY]);
            $ord = $lockOrder->fetch(PDO::FETCH_ASSOC);
            if (!$ord) continue; // pengaman ganda: bukan order migrasi

            $getPays->execute([$oid]);
            $pays = $getPays->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $it) { $zeroItem->execute([$it['id']]); }

            $sumItems->execute([$oid]);
            $newSubtotal = (float) $sumItems->fetchColumn();
            $newTotal    = max(0.0, $newSubtotal - (float) $ord['discount']);
            $updOrder->execute([$newSubtotal, $newTotal, $oid, SEJOLI_IMPORT_GATEWAY]);
            if ($pays) { $updPay->execute([$newTotal, $oid]); }

            $backup['orders'][] = [
                'order_id'     => (int) $oid,
                'old_subtotal' => (float) $ord['subtotal'],
                'old_total'    => (float) $ord['total'],
                'new_total'    => $newTotal,
                'items'        => $items,
                'payments'     => array_map(fn($p) => ['id' => (int) $p['id'], 'old_amount' => (float) $p['amount']], $pays),
            ];
        }

        // Tulis cadangan SEBELUM commit — gagal tulis = rollback (tanpa cadangan, jangan ubah).
        $file = sejoli_cleanup_dir() . '/' . $batch . '.json';
        if (@file_put_contents($file, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            throw new RuntimeException('Gagal menulis berkas cadangan.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @unlink(sejoli_cleanup_dir() . '/' . $batch . '.json');
        throw $e;
    }

    return ['batch' => $batch, 'orders' => count($byOrder), 'items' => count($targets), 'omzet' => $omzet];
}

/**
 * Daftar batch pembersihan (terbaru dulu) untuk ditampilkan + tombol undo.
 *
 * @return array<int,array{batch:string,created_at:string,orders:int,omzet:float,reverted_at:?string}>
 */
function sejoli_cleanup_batches(): array
{
    $dir = sejoli_cleanup_dir();
    $out = [];
    foreach (glob($dir . '/*.json') ?: [] as $f) {
        $j = json_decode((string) @file_get_contents($f), true);
        if (!is_array($j)) continue;
        $omzet = 0.0;
        foreach ($j['orders'] ?? [] as $o) {
            $omzet += (float) ($o['old_total'] ?? 0) - (float) ($o['new_total'] ?? 0);
        }
        $out[] = [
            'batch'       => (string) ($j['batch'] ?? basename($f, '.json')),
            'created_at'  => (string) ($j['created_at'] ?? ''),
            'orders'      => count($j['orders'] ?? []),
            'omzet'       => $omzet,
            'reverted_at' => $j['reverted_at'] ?? null,
        ];
    }
    usort($out, fn($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));
    return $out;
}

/**
 * Undo: kembalikan nilai lama dari backup JSON. Idempoten (batch yang sudah
 * di-undo ditolak). Hanya menyentuh order migrasi.
 *
 * @return array{orders:int}
 */
function sejoli_cleanup_undo(PDO $pdo, string $batch): array
{
    $batch = preg_replace('/[^0-9A-Za-z\-]/', '', $batch);
    $file  = sejoli_cleanup_dir() . '/' . $batch . '.json';
    if ($batch === '' || !is_file($file)) {
        throw new RuntimeException('Berkas cadangan tidak ditemukan.');
    }
    $j = json_decode((string) @file_get_contents($file), true);
    if (!is_array($j) || empty($j['orders'])) {
        throw new RuntimeException('Berkas cadangan rusak atau kosong.');
    }
    if (!empty($j['reverted_at'])) {
        throw new RuntimeException('Batch ini sudah dikembalikan sebelumnya.');
    }

    $restored = 0;
    $pdo->beginTransaction();
    try {
        $chkOrder = $pdo->prepare("SELECT 1 FROM orders WHERE id = ? AND payment_gateway = ? LIMIT 1");
        $rItem    = $pdo->prepare("UPDATE order_items SET price = ? WHERE id = ?");
        $rOrder   = $pdo->prepare("UPDATE orders SET subtotal = ?, total = ? WHERE id = ? AND payment_gateway = ?");
        $rPay     = $pdo->prepare("UPDATE payments SET amount = ? WHERE id = ?");
        foreach ($j['orders'] as $o) {
            $oid = (int) ($o['order_id'] ?? 0);
            if ($oid <= 0) continue;
            $chkOrder->execute([$oid, SEJOLI_IMPORT_GATEWAY]);
            if (!$chkOrder->fetchColumn()) continue; // bukan lagi order migrasi — lewati
            foreach ($o['items'] ?? [] as $it) {
                $rItem->execute([(float) ($it['old_price'] ?? 0), (int) ($it['id'] ?? 0)]);
            }
            $rOrder->execute([(float) ($o['old_subtotal'] ?? 0), (float) ($o['old_total'] ?? 0), $oid, SEJOLI_IMPORT_GATEWAY]);
            foreach ($o['payments'] ?? [] as $p) {
                $rPay->execute([(float) ($p['old_amount'] ?? 0), (int) ($p['id'] ?? 0)]);
            }
            $restored++;
        }
        $j['reverted_at'] = date('Y-m-d H:i:s');
        @file_put_contents($file, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['orders' => $restored];
}
