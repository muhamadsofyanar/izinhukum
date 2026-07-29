<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('services') || ! Schema::hasTable('service_packages')) {
            return;
        }

        // Migrasi tidak boleh mengubah harga atau status katalog yang telah
        // diputuskan admin. Katalog awal hanya dipasang melalui seeder eksplisit.
    }

    public function down(): void
    {
        // Katalog tidak dihapus saat rollback agar data dan harga pengguna tetap aman.
    }
};
