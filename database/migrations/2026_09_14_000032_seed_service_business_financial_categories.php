<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $categories = [
            ['type' => 'income', 'name' => 'Pendapatan jasa', 'slug' => 'pendapatan-jasa'],
            ['type' => 'expense', 'name' => 'Biaya notaris mitra', 'slug' => 'biaya-notaris-mitra'],
            ['type' => 'expense', 'name' => 'Ongkir dan kurir', 'slug' => 'ongkir-dan-kurir'],
            ['type' => 'expense', 'name' => 'Internet dan kuota', 'slug' => 'internet-dan-kuota'],
            ['type' => 'expense', 'name' => 'Iklan dan pemasaran', 'slug' => 'iklan-dan-pemasaran'],
            ['type' => 'expense', 'name' => 'Administrasi dan legal', 'slug' => 'administrasi-dan-legal'],
            ['type' => 'expense', 'name' => 'Operasional lainnya', 'slug' => 'operasional-lainnya'],
        ];

        foreach ($categories as $category) {
            DB::table('financial_categories')->updateOrInsert(
                ['type' => $category['type'], 'slug' => $category['slug']],
                [...$category, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('financial_categories')->whereIn('slug', [
            'pendapatan-jasa',
            'biaya-notaris-mitra',
            'ongkir-dan-kurir',
            'internet-dan-kuota',
            'iklan-dan-pemasaran',
            'administrasi-dan-legal',
            'operasional-lainnya',
        ])->delete();
    }
};
