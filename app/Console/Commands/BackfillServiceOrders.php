<?php

namespace App\Console\Commands;

use App\Services\ServiceOrderService;
use Illuminate\Console\Command;

class BackfillServiceOrders extends Command
{
    protected $signature = 'app:backfill-orders {--dry-run : Hanya menghitung tanpa mengubah data}';

    protected $description = 'Membuat order dari permintaan lama dan menghubungkan invoice secara idempoten.';

    public function handle(ServiceOrderService $orders): int
    {
        $result = $orders->backfill((bool) $this->option('dry-run'));

        $this->table(
            ['Diperiksa', 'Order dibuat', 'Invoice dihubungkan'],
            [[$result['checked'], $result['created'], $result['linked_invoices']]],
        );

        $this->info($this->option('dry-run') ? 'Pemeriksaan selesai.' : 'Sinkronisasi order selesai.');

        return self::SUCCESS;
    }
}
