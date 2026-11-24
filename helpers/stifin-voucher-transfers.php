<?php

require_once __DIR__ . '/stifin-promoters.php';

function stifinVoucherTransferEnabled(): bool
{
    return getenv('STIFIN_VOUCHER_TRANSFER') === '1';
}

function stifinVoucherTransferSchema(PDO $db): bool
{
    return (bool) $db->query("SHOW COLUMNS FROM stifin_voucher_queue LIKE 'transfer_status'")->fetchColumn();
}

function stifinVoucherTransferPayload(array $job, array $balance, string $apiUser): array
{
    $invoice = (string) $job['order_number'];
    return [
        'KodeID' => (string) $job['transfer_promoter'],
        'Jumlah' => (string) $job['quantity'],
        'JmlFree' => '0',
        'SaldoJ' => (string) $balance['voucher'],
        'SaldoF' => (string) $balance['free'],
        'Dispos' => $invoice,
        'Ket' => 'Pembayaran invoice ' . $invoice,
        'UserID' => $apiUser,
    ];
}

function stifinValidateVoucherTransferResponse(string $response): void
{
    if (trim($response) === '') {
        return;
    }
    $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    if ($result === null) {
        return;
    }
    if (!is_array($result)) {
        throw new RuntimeException('Format respons transfer tidak dikenali.');
    }

    $status = $result['status'] ?? null;
    $error = $result['error'] ?? null;
    if (($result['success'] ?? null) === false || $status === false
        || (is_string($status) && in_array(strtolower(trim($status)), ['error', 'failed', 'failure', 'gagal'], true))
        || !in_array($error, [null, false, ''], true)) {
        throw new RuntimeException('API STIFIn menolak transfer.');
    }
}

function stifinProcessVoucherTransfers(PDO $db): void
{
    if (!stifinVoucherTransferEnabled()) {
        return;
    }
    $branch = stifinPromoterBranchCode();
    $apiUser = trim((string) getenv('STIFIN_API_USER_ID'));
    if (!preg_match('/\A[A-Z0-9]{2,12}-CAB-[A-Z0-9]{1,8}\z/', $branch)
        || $branch === 'XXX-CAB-00' || $apiUser === '') {
        throw new RuntimeException('Konfigurasi cabang dan UserID API STIFIn belum lengkap.');
    }

    $lock = 'stifin-transfer:' . $branch;
    $acquire = $db->prepare('SELECT GET_LOCK(?, 0)');
    $acquire->execute([$lock]);
    $locked = (int) $acquire->fetchColumn() === 1;
    $acquire->closeCursor();
    if (!$locked) {
        return;
    }

    try {
        $jobs = $db->prepare("SELECT q.*, o.order_number FROM stifin_voucher_queue q
            JOIN orders o ON o.id = q.order_id AND o.user_id = q.user_id
            WHERE q.transfer_status = 'pending' AND o.status = 'paid'
            ORDER BY q.transfer_checked_at, q.id LIMIT 5");
        $jobs->execute();
        $pending = $jobs->fetchAll(PDO::FETCH_ASSOC);
        if (!$pending) {
            return;
        }
        $activePromoters = [];
        foreach (stifinBranchPromoters($branch) as $promoter) {
            if ($promoter['active'] === true) {
                $activePromoters[$promoter['code']] = true;
            }
        }
        foreach ($pending as $job) {
            stifinProcessVoucherTransfer($db, $job, $branch, $apiUser, $activePromoters);
        }
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lock]);
        $release->closeCursor();
    }
}

function stifinProcessVoucherTransfer(PDO $db, array $job, string $branch, string $apiUser, array $activePromoters): void
{
    $id = (int) $job['id'];
    $quantity = stifinVoucherQuantity($job['quantity']);
    $code = $job['transfer_promoter'];
    if ($job['transfer_branch'] !== $branch || !$quantity || !$job['order_number']
        || !$code || !isset($activePromoters[$code]) || $code !== stifinLinkedPromoterCode($db, (int) $job['user_id'])) {
        $db->prepare("UPDATE stifin_voucher_queue SET transfer_status = 'review',
            transfer_note = 'Periksa cabang, akun promotor, jumlah, dan nomor invoice sebelum transfer manual.'
            WHERE id = ? AND transfer_status = 'pending'")->execute([$id]);
        return;
    }

    try {
        $balance = stifinPromoterBalance($code);
        if ($balance['free'] === null) {
            throw new RuntimeException('Saldo Free belum tersedia.');
        }
    } catch (RuntimeException $error) {
        $db->prepare("UPDATE stifin_voucher_queue SET transfer_checked_at = NOW(),
            transfer_note = 'Saldo awal belum dapat dibaca. Akan diperiksa lagi.'
            WHERE id = ? AND transfer_status = 'pending'")->execute([$id]);
        return;
    }

    // Simpan status sebelum POST. Jika proses terputus, periksa mutasi di STIFIn sebelum tindakan manual.
    $claim = $db->prepare("UPDATE stifin_voucher_queue q
        JOIN orders o ON o.id = q.order_id AND o.user_id = q.user_id
        SET q.transfer_status = 'review', q.transfer_checked_at = NOW(), q.transfer_note = 'Periksa mutasi STIFIn berdasarkan nomor invoice.'
        WHERE q.id = ? AND q.transfer_status = 'pending' AND o.status = 'paid'");
    $claim->execute([$id]);
    if ($claim->rowCount() !== 1) {
        return;
    }

    try {
        $response = stifinPromoterApiRequest('/voucherPos/editCabVoucher/' . rawurlencode($branch),
            stifinVoucherTransferPayload($job, $balance, $apiUser));
        stifinValidateVoucherTransferResponse($response);
        $after = stifinPromoterBalance($code);
        if ($after['voucher'] === $balance['voucher'] + $quantity && $after['free'] === $balance['free']) {
            $db->prepare("UPDATE stifin_voucher_queue SET transfer_status = 'sent', transfer_note = NULL,
                fulfilled_at = NOW() WHERE id = ? AND transfer_status = 'review'")->execute([$id]);
        }
    } catch (Throwable $error) {
        error_log('[stifin-voucher] Hasil transfer perlu diverifikasi di STIFIn untuk antrean #' . $id . '.');
    }
}
