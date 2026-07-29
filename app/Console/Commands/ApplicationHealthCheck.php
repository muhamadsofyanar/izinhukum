<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ApplicationHealthCheck extends Command
{
    protected $signature = 'app:health-check';

    protected $description = 'Memeriksa koneksi database dan storage privat.';

    public function handle(): int
    {
        try {
            DB::select('select 1');
            $path = 'health/'.Str::uuid().'.txt';
            Storage::disk('local')->put($path, 'ok');
            Storage::disk('local')->delete($path);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->info('Database terhubung dan storage privat dapat ditulis.');
        return self::SUCCESS;
    }
}
