<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('products')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/products');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/admin/products');
}

$productId = (int) ($_POST['product_id'] ?? 0);
if ($productId <= 0) {
    flash('error', 'Produk tidak valid.', 'error');
    redirect('/admin/products');
}

try {
    $pdo = getDB();

    $src = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $src->execute([$productId]);
    $p = $src->fetch();
    if (!$p) {
        flash('error', 'Produk tidak ditemukan.', 'error');
        redirect('/admin/products');
    }

    // Slug unik: base-copy, base-copy-2, base-copy-3, ...
    $base      = preg_replace('/-copy(-\d+)?$/', '', (string) $p['slug']);
    $candidate = $base . '-copy';
    $i = 1;
    $chk = $pdo->prepare('SELECT COUNT(*) FROM products WHERE slug = ?');
    while (true) {
        $chk->execute([$candidate]);
        if ((int) $chk->fetchColumn() === 0) break;
        $i++;
        $candidate = $base . '-copy-' . $i;
    }
    $newSlug  = $candidate;
    $newName  = mb_substr((string) $p['name'] . ' (Salinan)', 0, 200);

    $pdo->beginTransaction();

    // ─── Produk: salin sebagai draft (thumbnail di-share, aman karena
    //     delete-product tidak meng-unlink file dari disk) ───────────
    $ins = $pdo->prepare(
        'INSERT INTO products
         (name, slug, type, catalog_category, status, price, original_price, price_myr, language,
          dime_enabled, dime_step_qty, dime_increment, dime_max_price,
          pricing_type, flexible_label, flexible_min, flexible_presets, show_on_support,
          access_duration_days, affiliate_commission, thumbnail,
          landing_page_url, lp_type, canvas_id, checkout_template_id, show_coupon,
          checkout_mode, public_form_template,
          short_description, description,
          license_enabled, license_seats, license_validity_days,
          certificate_enabled, certificate_auto_issue, certificate_title_template,
          reviews_enabled)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        // jv_partner_user_id/jv_partner_percent SENGAJA tak dibawa — kontrak bagi hasil
        // harus di-set ulang sadar oleh admin per produk, bukan auto-carry ke duplikat.
    );
    $ins->execute([
        $newName, $newSlug, $p['type'], $p['catalog_category'] ?? null, 'draft',
        $p['price'], $p['original_price'], $p['price_myr'] ?? null, $p['language'] ?? 'id',
        $p['dime_enabled'] ?? 0, $p['dime_step_qty'] ?? 10, $p['dime_increment'] ?? 0, $p['dime_max_price'] ?? null,
        $p['pricing_type'] ?? 'fixed', $p['flexible_label'] ?? null, $p['flexible_min'] ?? 0, $p['flexible_presets'] ?? null, $p['show_on_support'] ?? 0,
        $p['access_duration_days'], $p['affiliate_commission'], $p['thumbnail'],
        $p['landing_page_url'], $p['lp_type'], $p['canvas_id'], $p['checkout_template_id'], $p['show_coupon'] ?? 1,
        $p['checkout_mode'], $p['public_form_template'],
        $p['short_description'], $p['description'],
        $p['license_enabled'], $p['license_seats'], $p['license_validity_days'],
        $p['certificate_enabled'], $p['certificate_auto_issue'], $p['certificate_title_template'],
        $p['reviews_enabled'],
    ]);
    $newProductId = (int) $pdo->lastInsertId();

    // ─── Kelas + materi (produk class punya konten di classes→materials) ──
    $clsCount = 0; $matCount = 0;
    $classes = $pdo->prepare('SELECT * FROM classes WHERE product_id = ? ORDER BY id ASC');
    $classes->execute([$productId]);
    $rows = $classes->fetchAll();

    $insClass = $pdo->prepare(
        'INSERT INTO classes (product_id, category_id, title, description, thumbnail, status, sort_order, unlock_after_days)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $matSel = $pdo->prepare('SELECT * FROM materials WHERE class_id = ? ORDER BY id ASC');
    $insMat = $pdo->prepare(
        'INSERT INTO materials (class_id, title, content, description, content_type, content_url, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($rows as $c) {
        $insClass->execute([
            $newProductId, $c['category_id'], $c['title'], $c['description'],
            $c['thumbnail'], $c['status'], $c['sort_order'], (int) ($c['unlock_after_days'] ?? 0),
        ]);
        $newClassId = (int) $pdo->lastInsertId();
        $clsCount++;

        $matSel->execute([(int) $c['id']]);
        foreach ($matSel->fetchAll() as $m) {
            $insMat->execute([
                $newClassId, $m['title'], $m['content'], ($m['description'] ?? null),
                $m['content_type'], $m['content_url'], $m['sort_order'],
            ]);
            $matCount++;
        }
    }

    $pdo->commit();

    if (function_exists('logActivity')) {
        logActivity('admin', 'product.duplicate', 'product', $newProductId,
            'Duplikat dari "' . $p['name'] . '" (' . $clsCount . ' kelas, ' . $matCount . ' materi).');
    }
    flash('success', 'Produk "' . $p['name'] . '" diduplikat sebagai draft'
        . ($clsCount > 0 ? ' beserta ' . $clsCount . ' kelas & ' . $matCount . ' materi' : '') . '.');

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Duplicate product error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat menduplikat produk. Silakan coba lagi.', 'error');
}

redirect('/admin/products');
