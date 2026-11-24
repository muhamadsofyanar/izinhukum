<?php
// Helper ringan: hitung jumlah order pending yang sudah melewati batas waktu.
// Di-require_once HANYA di pages/admin/transactions.php (lazy check), bukan di bootstrap.

if (!function_exists('getExpiredOrderCount')) {
    function getExpiredOrderCount(): int
    {
        try {
            $expiryHours = (int) getSetting('order_expiry_hours', '0');
            if ($expiryHours <= 0) return 0;

            $pdo  = getDB();
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM orders
                 WHERE status = 'pending'
                   AND created_at <= NOW() - INTERVAL ? HOUR"
            );
            $stmt->execute([$expiryHours]);
            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            error_log('getExpiredOrderCount error: ' . $e->getMessage());
            return 0;
        }
    }
}
