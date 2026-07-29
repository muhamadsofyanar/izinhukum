<?php

namespace App\Console\Commands;

use App\Services\LegacyPaidInvoiceReconciler;
use Illuminate\Console\Command;

class ReconcileLegacyPaidInvoices extends Command
{
    protected $signature = 'finance:reconcile-legacy-paid-invoices';

    protected $description = 'Membuat pembayaran dan kwitansi untuk invoice lama berstatus lunas yang belum lengkap.';

    public function handle(LegacyPaidInvoiceReconciler $reconciler): int
    {
        $created = $reconciler->run();

        if ($created > 0) {
            $this->info($created.' pembayaran rekonsiliasi berhasil dibuat.');
        } else {
            $this->info('Tidak ada invoice lama yang perlu direkonsiliasi.');
        }

        return self::SUCCESS;
    }
}
