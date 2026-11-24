<?php

function stifinVoucherProductId(): int
{
    $configured = (int) getenv('STIFIN_VOUCHER_PRODUCT_ID');
    return $configured > 0 ? $configured : max(0, (int) getSetting('stifin_voucher_product_id', '0'));
}

function stifinVoucherQuantity(mixed $value): int
{
    if (!is_string($value) && !is_int($value)) {
        return 0;
    }
    return (int) filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
}

function stifinQueuePaidVouchers(PDO $db, int $orderId, int $userId): void
{
    $productId = stifinVoucherProductId();
    if ($productId === 0 || $userId <= 0) {
        return;
    }

    $stmt = $db->prepare('SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = ? AND product_id = ?');
    $stmt->execute([$orderId, $productId]);
    $quantity = (int) $stmt->fetchColumn();
    if ($quantity <= 0) {
        return;
    }
    if (stifinVoucherTransferEnabled()) {
        $branch = stifinPromoterBranchCode();
        $promoter = stifinLinkedPromoterCode($db, $userId);
        $db->prepare("INSERT INTO stifin_voucher_queue
            (order_id, user_id, quantity, transfer_branch, transfer_promoter, transfer_status)
            VALUES (?, ?, ?, ?, ?, 'pending')
            ON DUPLICATE KEY UPDATE order_id = stifin_voucher_queue.order_id")
            ->execute([$orderId, $userId, $quantity, $branch, $promoter]);
        return;
    }
    $db->prepare('INSERT INTO stifin_voucher_queue (order_id, user_id, quantity) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE order_id = stifin_voucher_queue.order_id')->execute([$orderId, $userId, $quantity]);
}

function stifinDistributeVouchers(PDO $db): void
{
    if (stifinVoucherProductId() === 0) {
        return;
    }

    $lock = 'stifin-vouchers:' . substr(hash('sha256', (string) $db->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
    $stmt = $db->prepare('SELECT GET_LOCK(?, 3)');
    $stmt->execute([$lock]);
    $locked = (int) $stmt->fetchColumn() === 1;
    $stmt->closeCursor();
    if (!$locked) {
        return;
    }

    try {
        $localOnly = stifinVoucherTransferSchema($db) ? ' AND q.transfer_status IS NULL' : '';
        for ($i = 0; $i < 100; $i++) {
            $db->beginTransaction();
            try {
                $job = $db->query("SELECT q.id, q.quantity, q.order_id, q.user_id FROM stifin_voucher_queue q
                    JOIN orders o ON o.id = q.order_id AND o.user_id = q.user_id
                    WHERE q.fulfilled_at IS NULL AND o.status = 'paid'{$localOnly} ORDER BY q.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if (!$job) {
                    $db->commit();
                    break;
                }
                $order = $db->prepare("SELECT status FROM orders WHERE id = ? AND user_id = ? FOR UPDATE");
                $order->execute([$job['order_id'], $job['user_id']]);
                if ($order->fetchColumn() !== 'paid') {
                    $db->commit();
                    continue;
                }
                $quantity = (int) $job['quantity'];
                $stock = $db->query("SELECT id FROM stifin_voucher_stock WHERE queue_id IS NULL ORDER BY id LIMIT {$quantity} FOR UPDATE")
                    ->fetchAll(PDO::FETCH_COLUMN);
                if (count($stock) !== $quantity) {
                    $db->commit();
                    break;
                }
                $assign = $db->prepare('UPDATE stifin_voucher_stock SET queue_id = ? WHERE id = ? AND queue_id IS NULL');
                foreach ($stock as $id) {
                    $assign->execute([$job['id'], $id]);
                    if ($assign->rowCount() !== 1) {
                        throw new RuntimeException('Persediaan voucher sudah berubah.');
                    }
                }
                $db->prepare('UPDATE stifin_voucher_queue SET fulfilled_at = NOW() WHERE id = ?')->execute([$job['id']]);
                $db->commit();
            } catch (Throwable $error) {
                $db->rollBack();
                throw $error;
            }
        }
    } finally {
        $stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$lock]);
        $stmt->closeCursor();
    }
}

function stifinImportVoucherCodes(PDO $db, string $raw): int
{
    $codes = array_unique(array_filter(array_map('trim', preg_split('/\R/u', $raw) ?: []), static fn ($code) => $code !== ''));
    if (!$codes || count($codes) > 500) {
        throw new InvalidArgumentException('Masukkan 1 sampai 500 kode, satu kode per baris.');
    }
    foreach ($codes as $code) {
        if (!preg_match('/\A[!-~]{1,128}\z/', $code)) {
            throw new InvalidArgumentException('Kode harus berupa 1 sampai 128 karakter ASCII tanpa spasi.');
        }
    }
    $stmt = $db->prepare('INSERT INTO stifin_voucher_stock (code) VALUES (?) ON DUPLICATE KEY UPDATE id = id');
    $db->beginTransaction();
    try {
        $count = 0;
        foreach ($codes as $code) {
            $stmt->execute([$code]);
            $count += (int) ($stmt->rowCount() === 1);
        }
        $db->commit();
        return $count;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}
