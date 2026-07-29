<?php

namespace App\Console\Commands;

use App\Services\LegacyPaidInvoiceReconciler;
use Illuminate\Console\Command;

class ReconcileLegacyPaidInvoices extends Command
{
    protected $signature = 'finance:reconcile-legacy-paid-invoices';

    protected $description = 'Membuat pembayaran untuk invoice lama yang sudah lunas tanpa kwitansi';

    public function handle(LegacyPaidInvoiceReconciler $reconciler): int
    {
        $created = $reconciler->run();
        $this->info($created.' pembayaran lama berhasil direkonsiliasi.');

        return self::SUCCESS;
    }
}

