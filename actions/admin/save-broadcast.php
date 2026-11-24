<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/broadcast');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/broadcast');
}

$campaignId  = !empty($_POST['campaign_id']) ? (int) $_POST['campaign_id'] : 0;
$name        = trim($_POST['name']        ?? '');
$channel     = trim($_POST['channel']     ?? '');
$provider    = trim($_POST['provider']    ?? '');
$subject     = trim($_POST['subject']     ?? '');
$body        = trim($_POST['body']        ?? '');
$segment     = trim($_POST['target_segment'] ?? 'all_users');
$levelId     = !empty($_POST['target_membership_level_id']) ? (int)$_POST['target_membership_level_id'] : null;
$leadMagnetId = !empty($_POST['target_lead_magnet_id']) ? (int)$_POST['target_lead_magnet_id'] : null;
$bodyFormat  = ($channel === 'email' && ($_POST['body_format'] ?? '') === 'html') ? 'html' : 'text';
$sendMode    = trim($_POST['send_mode']   ?? 'now');
$scheduledAt = trim($_POST['scheduled_at'] ?? '');
// Kecualikan pembeli produk (CSV id) + skip email yang sebelumnya gagal.
$excludeProductIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['exclude_products'] ?? [])), fn($v) => $v > 0)));
$excludeCsv  = $excludeProductIds ? implode(',', $excludeProductIds) : null;
$skipFailed  = !empty($_POST['skip_failed_emails']) ? 1 : 0;
// Target "Pembeli Produk" (segment=product_buyers) — produk yang pembelinya jadi target.
$targetProductIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['target_products'] ?? [])), fn($v) => $v > 0)));
$targetProductCsv = $targetProductIds ? implode(',', $targetProductIds) : null;
// Rentang waktu segmen engagement (email_openers/email_clickers): 30/60/90 hari.
// Kosong / nilai lain = kapan saja (NULL).
$engagementDays = in_array((int) ($_POST['engagement_days'] ?? 0), [30, 60, 90], true) ? (int) $_POST['engagement_days'] : null;
if (!in_array($segment, ['email_openers', 'email_clickers'], true)) $engagementDays = null;

// ─── Validasi ─────────────────────────────────────────────────
$errors = [];
if (empty($name))    $errors[] = 'Nama campaign tidak boleh kosong.';
if (empty($body))    $errors[] = 'Isi pesan tidak boleh kosong.';
if (empty($provider)) $errors[] = 'Pilih provider.';
if (!in_array($channel, ['email', 'whatsapp'], true)) $errors[] = 'Channel tidak valid.';
if (!in_array($segment, ['all_users', 'active_members', 'specific_level', 'leads_all', 'leads_by_magnet', 'product_buyers', 'email_openers', 'email_clickers'], true)) $errors[] = 'Segment tidak valid.';
if ($segment === 'specific_level' && empty($levelId)) $errors[] = 'Pilih membership level.';
if ($segment === 'leads_by_magnet' && empty($leadMagnetId)) $errors[] = 'Pilih lead magnet.';
if ($segment === 'product_buyers' && empty($targetProductIds)) $errors[] = 'Pilih minimal satu produk untuk target Pembeli Produk.';
// Segmen engagement email berbasis open/klik → hanya relevan untuk channel email.
if (in_array($segment, ['email_openers', 'email_clickers'], true) && $channel !== 'email') $errors[] = 'Segmen "Pernah buka/klik email" hanya untuk channel Email.';

if (!empty($errors)) {
    flash('error', implode(' ', $errors), 'error');
    redirect('/admin/broadcast');
}

// ─── Tentukan status ──────────────────────────────────────────
$status      = 'draft';
$scheduledTs = null;

if ($sendMode === 'scheduled' && !empty($scheduledAt)) {
    $ts = strtotime($scheduledAt);
    if ($ts && $ts > time()) {
        $status      = 'scheduled';
        $scheduledTs = date('Y-m-d H:i:s', $ts);
    }
}

try {
    $pdo = getDB();

    // Tambah kolom created_by jika belum ada (upgrade otomatis skema lama)
    try {
        $pdo->query("SELECT created_by FROM broadcast_campaigns LIMIT 0");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE broadcast_campaigns ADD COLUMN created_by INT UNSIGNED NULL AFTER scheduled_at");
    }
    // Tambah kolom engagement_days jika belum ada (install belum migrasi 1.2.144)
    try {
        $pdo->query("SELECT engagement_days FROM broadcast_campaigns LIMIT 0");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE broadcast_campaigns ADD COLUMN engagement_days SMALLINT UNSIGNED NULL AFTER target_product_ids");
    }

    if ($campaignId > 0) {
        // ─── MODE UPDATE (hanya draft) ────────────────────────────
        $st = $pdo->prepare("SELECT id, status FROM broadcast_campaigns WHERE id = ? LIMIT 1");
        $st->execute([$campaignId]);
        $existing = $st->fetch();

        if (!$existing) {
            flash('error', 'Campaign tidak ditemukan.', 'error');
            redirect('/admin/broadcast');
        }
        if (($existing['status'] ?? '') !== 'draft') {
            flash('error', 'Hanya campaign berstatus draft yang bisa diedit.', 'error');
            redirect('/admin/broadcast');
        }

        $pdo->prepare(
            "UPDATE broadcast_campaigns SET
                name = ?, channel = ?, provider = ?, subject = ?, body = ?, body_format = ?,
                target_segment = ?, target_membership_level_id = ?, target_lead_magnet_id = ?,
                target_product_ids = ?, engagement_days = ?, exclude_product_ids = ?, skip_failed_emails = ?,
                status = ?, scheduled_at = ?
             WHERE id = ? AND status = 'draft'"
        )->execute([
            $name,
            $channel,
            $provider,
            $subject ?: null,
            $body,
            $bodyFormat,
            $segment,
            $levelId,
            $leadMagnetId,
            $targetProductCsv,
            $engagementDays,
            $excludeCsv,
            $skipFailed,
            $status,
            $scheduledTs,
            $campaignId,
        ]);

        if (function_exists('logActivity')) {
            logActivity('admin', 'broadcast.updated', 'broadcast_campaigns', $campaignId,
                'Campaign diperbarui: ' . $name . ' (' . $channel . ', ' . $segment . ')');
        }

        flash('success', 'Campaign "' . $name . '" berhasil diperbarui.');
    } else {
        // ─── MODE CREATE ──────────────────────────────────────────
        $pdo->prepare(
            "INSERT INTO broadcast_campaigns
             (name, channel, provider, subject, body, body_format, target_segment,
              target_membership_level_id, target_lead_magnet_id, target_product_ids,
              engagement_days, exclude_product_ids, skip_failed_emails,
              status, scheduled_at, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $name,
            $channel,
            $provider,
            $subject ?: null,
            $body,
            $bodyFormat,
            $segment,
            $levelId,
            $leadMagnetId,
            $targetProductCsv,
            $engagementDays,
            $excludeCsv,
            $skipFailed,
            $status,
            $scheduledTs,
            (int) ($_SESSION['user_id'] ?? 0),
        ]);

        if (function_exists('logActivity')) {
            logActivity('admin', 'broadcast.created', 'broadcast_campaigns', null,
                'Campaign dibuat: ' . $name . ' (' . $channel . ', ' . $segment . ')');
        }

        flash('success', 'Campaign "' . $name . '" berhasil disimpan.');
    }
} catch (Exception $e) {
    error_log('Save broadcast error: ' . $e->getMessage());
    flash('error', 'Gagal menyimpan campaign.', 'error');
}

redirect('/admin/broadcast');
