<?php

namespace Database\Seeders;

use App\Models\KbliCode;
use Illuminate\Database\Seeder;

class KbliSeeder extends Seeder
{
    public function run(): void
    {
        $codes = [
            ['code' => '46900', 'title' => 'Perdagangan Besar Berbagai Macam Barang', 'risk_level' => 'Menengah Rendah', 'licensing' => 'NIB dan Sertifikat Standar'],
            ['code' => '47111', 'title' => 'Perdagangan Eceran Berbagai Macam Barang yang Utamanya Makanan, Minuman atau Tembakau di Minimarket/Supermarket/Hypermarket', 'risk_level' => 'Menengah Rendah', 'licensing' => 'NIB dan Sertifikat Standar'],
            ['code' => '47911', 'title' => 'Perdagangan Eceran Melalui Media untuk Komoditi Makanan, Minuman, Tembakau, Kimia, Farmasi, Kosmetik dan Alat Laboratorium', 'risk_level' => 'Menengah Rendah', 'licensing' => 'NIB dan Sertifikat Standar'],
            ['code' => '47919', 'title' => 'Perdagangan Eceran Melalui Media untuk Berbagai Macam Barang Lainnya', 'risk_level' => 'Rendah', 'licensing' => 'NIB'],
            ['code' => '56101', 'title' => 'Restoran', 'risk_level' => 'Menengah Rendah', 'licensing' => 'NIB dan Sertifikat Standar'],
            ['code' => '56102', 'title' => 'Rumah/Warung Makan', 'risk_level' => 'Menengah Rendah', 'licensing' => 'NIB dan Sertifikat Standar'],
            ['code' => '62010', 'title' => 'Aktivitas Pemrograman Komputer', 'risk_level' => 'Rendah', 'licensing' => 'NIB'],
            ['code' => '62020', 'title' => 'Aktivitas Konsultasi Komputer dan Manajemen Fasilitas Komputer', 'risk_level' => 'Rendah', 'licensing' => 'NIB'],
            ['code' => '63122', 'title' => 'Portal Web dan/atau Platform Digital dengan Tujuan Komersial', 'risk_level' => 'Menengah Tinggi', 'licensing' => 'NIB dan Sertifikat Standar terverifikasi'],
            ['code' => '68111', 'title' => 'Real Estat yang Dimiliki Sendiri atau Disewa', 'risk_level' => 'Rendah', 'licensing' => 'NIB'],
            ['code' => '70209', 'title' => 'Aktivitas Konsultasi Manajemen Lainnya', 'risk_level' => 'Rendah', 'licensing' => 'NIB'],
            ['code' => '73100', 'title' => 'Periklanan', 'risk_level' => 'Rendah', 'licensing' => 'NIB'],
            ['code' => '74130', 'title' => 'Aktivitas Desain Komunikasi Visual/Desain Grafis', 'risk_level' => 'Rendah', 'licensing' => 'NIB'],
            ['code' => '82301', 'title' => 'Jasa Penyelenggara Pertemuan, Perjalanan Insentif, Konferensi dan Pameran', 'risk_level' => 'Menengah Rendah', 'licensing' => 'NIB dan Sertifikat Standar'],
            ['code' => '85499', 'title' => 'Pendidikan Lainnya Swasta', 'risk_level' => 'Menengah Tinggi', 'licensing' => 'NIB dan perizinan sektor'],
        ];

        foreach ($codes as $code) {
            KbliCode::updateOrCreate(
                ['code' => $code['code']],
                [
                    ...$code,
                    'description' => 'Data awal untuk demonstrasi pencarian. Verifikasi klasifikasi dan tingkat risiko terbaru sebelum pengajuan OSS.',
                    'is_sample' => true,
                ],
            );
        }
    }
}
