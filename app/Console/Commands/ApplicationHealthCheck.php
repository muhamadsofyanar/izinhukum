<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ApplicationHealthCheck extends Command
{
    protected $signature = 'app:health-check';

    protected $description = 'Memeriksa koneksi database dan storage privat.';

    public function handle(): int
    {
        try {
            DB::select('select 1');
            $requiredSchema = [
                'payment_proofs' => ['invoice_id', 'payment_id', 'status'],
                'inquiries' => ['disposition', 'phone_normalized', 'spam_score'],
                'invoices' => ['payment_scheme', 'payment_recipient', 'payment_terms'],
                'payments' => ['received_by'],
            ];
            foreach ($requiredSchema as $table => $columns) {
                if (! Schema::hasTable($table)) {
                    throw new \RuntimeException("Tabel {$table} belum tersedia. Jalankan: php artisan migrate --force");
                }
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        throw new \RuntimeException("Kolom {$table}.{$column} belum tersedia. Jalankan: php artisan migrate --force");
                    }
                }
            }
            $path = 'health/'.Str::uuid().'.txt';
            Storage::disk('local')->put($path, 'ok');
            Storage::disk('local')->delete($path);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->info('Database, struktur tabel, dan storage privat dalam kondisi siap.');
        return self::SUCCESS;
    }
}
