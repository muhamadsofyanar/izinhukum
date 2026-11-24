<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../helpers/stifin-official-reports.php';

try {
    switch ($argv[1] ?? '') {
        case 'voucher':
            if (stifinVoucherTransferEnabled()) {
                stifinProcessVoucherTransfers(getDB());
            }
            break;
        case 'results':
            stifinProcessOfficialHistory(getDB(), stifinPromoterBranchCode());
            break;
    }
} catch (Throwable $error) {
    error_log('STIFIn background worker failed.');
    exit(1);
}
