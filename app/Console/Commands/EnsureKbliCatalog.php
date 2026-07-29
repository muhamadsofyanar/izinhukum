<?php

namespace App\Console\Commands;

use App\Models\KbliCode;
use Database\Seeders\KbliSeeder;
use Illuminate\Console\Command;

class EnsureKbliCatalog extends Command
{
    protected $signature = 'kbli:ensure';

    protected $description = 'Memastikan katalog KBLI 2025 berisi 1.559 kode resmi.';

    public function handle(): int
    {
        $expectedCount = $this->expectedCount();
        $currentCount = KbliCode::query()->where('version', '2025')->count();

        if ($currentCount === $expectedCount) {
            $this->components->info("Katalog KBLI 2025 sudah lengkap ({$expectedCount} kode).");

            return self::SUCCESS;
        }

        $this->components->warn(
            "Katalog KBLI 2025 belum lengkap ({$currentCount}/{$expectedCount}). Sinkronisasi dijalankan.",
        );

        $exitCode = $this->call('db:seed', [
            '--class' => KbliSeeder::class,
            '--force' => true,
        ]);

        $finalCount = KbliCode::query()->where('version', '2025')->count();

        if ($exitCode !== self::SUCCESS || $finalCount !== $expectedCount) {
            $this->components->error(
                "Sinkronisasi KBLI gagal. Katalog berisi {$finalCount}/{$expectedCount} kode.",
            );

            return self::FAILURE;
        }

        $this->components->info("Katalog KBLI 2025 berhasil disinkronkan ({$expectedCount} kode).");

        return self::SUCCESS;
    }

    private function expectedCount(): int
    {
        $path = database_path('data/kbli-2025.json');

        if (! is_file($path)) {
            throw new \RuntimeException('Dataset database/data/kbli-2025.json tidak ditemukan.');
        }

        $dataset = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $expectedCount = (int) ($dataset['metadata']['code_count'] ?? 0);

        if ($expectedCount !== 1559 || count($dataset['records'] ?? []) !== 1559) {
            throw new \RuntimeException('Dataset KBLI 2025 tidak valid atau tidak lengkap.');
        }

        return $expectedCount;
    }
}
