<?php

use Database\Seeders\ServiceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('services') || ! Schema::hasTable('service_packages')) {
            return;
        }

        (new ServiceSeeder())->run();

        DB::table('services')->update([
            'is_active' => true,
            'updated_at' => now(),
        ]);

        DB::table('service_packages')->update([
            'is_active' => true,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Katalog tidak dihapus saat rollback agar data dan harga pengguna tetap aman.
    }
};
