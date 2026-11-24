<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/licenses');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/admin/licenses');
}

$action    = $_POST['action'] ?? '';
$licenseId = (int) ($_POST['license_id'] ?? 0);

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT * FROM product_licenses WHERE id = ? LIMIT 1");
    $stmt->execute([$licenseId]);
    $license = $stmt->fetch();

    if (!$license) {
        flash('error', 'License tidak ditemukan.', 'error');
        redirect('/admin/licenses');
    }

    switch ($action) {
        case 'suspend':
            $pdo->prepare("UPDATE product_licenses SET status = 'suspended' WHERE id = ?")->execute([$licenseId]);
            logActivity('admin', 'license.suspend', 'license', $licenseId, 'License ' . $license['license_key']);
            flash('success', 'License berhasil disuspend.');
            break;

        case 'unsuspend':
            $pdo->prepare("UPDATE product_licenses SET status = 'active' WHERE id = ?")->execute([$licenseId]);
            logActivity('admin', 'license.unsuspend', 'license', $licenseId, 'License ' . $license['license_key']);
            flash('success', 'License berhasil diaktifkan kembali.');
            break;

        case 'revoke':
            $pdo->prepare("UPDATE product_licenses SET status = 'revoked' WHERE id = ?")->execute([$licenseId]);
            logActivity('admin', 'license.revoke', 'license', $licenseId, 'License ' . $license['license_key']);
            flash('success', 'License berhasil direvoke.');
            break;

        case 'extend':
            $days = max(1, (int) ($_POST['extend_days'] ?? 30));
            // Lifetime (expires_at NULL) → tetap lifetime, tidak perlu extend.
            if ($license['expires_at']) {
                $pdo->prepare(
                    "UPDATE product_licenses SET expires_at = DATE_ADD(expires_at, INTERVAL ? DAY) WHERE id = ?"
                )->execute([$days, $licenseId]);
                // Jika sebelumnya berstatus expired & kini belum lewat → aktifkan kembali.
                $pdo->prepare(
                    "UPDATE product_licenses SET status = 'active'
                     WHERE id = ? AND status = 'expired' AND expires_at > NOW()"
                )->execute([$licenseId]);
                logActivity('admin', 'license.extend', 'license', $licenseId, "+{$days} hari");
                flash('success', "Masa berlaku diperpanjang {$days} hari.");
            } else {
                flash('info', 'License ini berlaku selamanya — tidak perlu diperpanjang.', 'info');
            }
            break;

        case 'add_note':
            $notes = trim($_POST['notes'] ?? '');
            $pdo->prepare("UPDATE product_licenses SET notes = ? WHERE id = ?")
                ->execute([$notes !== '' ? $notes : null, $licenseId]);
            flash('success', 'Catatan disimpan.');
            break;

        case 'deactivate_seat':
            $activationId = (int) ($_POST['activation_id'] ?? 0);
            $pdo->beginTransaction();
            try {
                $upd = $pdo->prepare(
                    "UPDATE license_activations SET status = 'deactivated', deactivated_at = NOW()
                     WHERE id = ? AND license_id = ? AND status = 'active'"
                );
                $upd->execute([$activationId, $licenseId]);

                // Kurangi used_seats hanya jika ada baris yang benar-benar berubah.
                if ($upd->rowCount() > 0) {
                    $pdo->prepare(
                        "UPDATE product_licenses SET used_seats = GREATEST(0, used_seats - 1) WHERE id = ?"
                    )->execute([$licenseId]);
                }
                $pdo->commit();
                flash('success', 'Aktivasi berhasil dicabut.');
            } catch (Exception $txEx) {
                $pdo->rollBack();
                throw $txEx;
            }
            break;

        case 'delete':
            $pdo->beginTransaction();
            try {
                // Bersihkan aktivasi terkait dulu, lalu hapus lisensinya.
                $pdo->prepare("DELETE FROM license_activations WHERE license_id = ?")->execute([$licenseId]);
                $pdo->prepare("DELETE FROM product_licenses WHERE id = ?")->execute([$licenseId]);
                $pdo->commit();
            } catch (Exception $txEx) {
                $pdo->rollBack();
                throw $txEx;
            }
            logActivity('admin', 'license.delete', 'license', $licenseId, 'License ' . $license['license_key']);
            flash('success', 'License berhasil dihapus.');
            redirect('/admin/licenses'); // detail sudah tidak ada → kembali ke daftar
            break;

        default:
            flash('error', 'Aksi tidak dikenal.', 'error');
    }

} catch (Exception $e) {
    error_log('manage-license error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat memproses aksi.', 'error');
}

redirect('/admin/licenses/' . $licenseId);
