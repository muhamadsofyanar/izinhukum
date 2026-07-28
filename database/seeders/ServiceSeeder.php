<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $legalBasic = [
            'Pengecekan nama',
            'Pemesanan nama',
            'Persiapan minuta',
            'Akta pendirian',
            'SK Menteri',
            'Bonus hingga 20 KBLI',
        ];

        $permit = [
            'Paket pendirian badan usaha',
            'NPWP badan',
            'SKT Pajak',
            'NIB dan Sertifikat Standar (non-tinggi)',
            'K3L, SPPL, dan UMK Tata Ruang',
            'Bonus hingga 20 KBLI',
            'Dukungan pembukaan rekening bank',
            'Kartu nama direktur',
            'Stempel perusahaan',
            'EFIN badan',
        ];

        $virtualOffice = [
            'Paket pendirian dan perizinan',
            'Virtual Office selama 1 tahun',
            'Pilihan lokasi premium Jakarta',
            'Zonasi komersial dan dapat diajukan PKP',
            'Layanan resepsionis dan korespondensi',
            '60 jam meeting room',
            'Akses meeting di 6 lokasi',
            'Meeting room hingga 12 orang',
            'Bonus hingga 20 KBLI',
            'Dukungan pembukaan rekening bank',
            'Kartu nama direktur dan stempel',
            'EFIN badan',
        ];

        $create = function (array $serviceData, array $packages): void {
            $service = Service::updateOrCreate(
                ['slug' => $serviceData['slug']],
                $serviceData,
            );

            foreach ($packages as $index => $package) {
                $service->packages()->updateOrCreate(
                    ['name' => $package['name']],
                    [
                        'tagline' => $package['tagline'] ?? null,
                        'price' => $package['price'],
                        'original_price' => $package['original_price'] ?? null,
                        'price_suffix' => $package['price_suffix'] ?? null,
                        'features' => $package['features'],
                        'is_estimated' => $package['is_estimated'] ?? false,
                        'is_popular' => $package['is_popular'] ?? false,
                        'is_active' => true,
                        'sort_order' => $index + 1,
                    ],
                );
            }
        };

        $create([
            'name' => 'PT (Perseroan Terbatas)',
            'slug' => 'pendirian-pt',
            'short_name' => 'PT',
            'category' => 'Pendirian Badan Usaha',
            'summary' => 'Pendirian Perseroan Terbatas untuk bisnis yang membutuhkan badan hukum dan struktur kepemilikan saham.',
            'description' => 'Kami mendampingi proses pendirian PT dari pengecekan nama, akta, pengesahan badan hukum, hingga perizinan berusaha sesuai paket yang dipilih.',
            'icon' => 'building',
            'is_featured' => true,
            'is_active' => true,
            'sort_order' => 1,
        ], [
            ['name' => 'Pendirian PT', 'tagline' => 'Mau urus izin usaha sendiri.', 'price' => 3000000, 'features' => $legalBasic],
            ['name' => 'Pendirian PT + Izin', 'tagline' => 'Tanpa repot, dengan alamat sendiri.', 'price' => 5500000, 'features' => $permit, 'is_popular' => true],
            ['name' => 'Pendirian PT + Virtual Office', 'tagline' => 'Paket lengkap dengan kantor prestisius.', 'price' => 7500000, 'features' => $virtualOffice],
        ]);

        $create([
            'name' => 'PT Perorangan',
            'slug' => 'pendirian-pt-perorangan',
            'short_name' => 'PT Perorangan',
            'category' => 'Pendirian Badan Usaha',
            'summary' => 'Badan hukum untuk usaha mikro dan kecil yang dapat didirikan oleh satu WNI.',
            'description' => 'Pilihan ringkas untuk pelaku UMK yang ingin memisahkan harta pribadi dan usaha melalui badan hukum perseroan perorangan.',
            'icon' => 'user',
            'is_featured' => true,
            'is_active' => true,
            'sort_order' => 2,
        ], [
            ['name' => 'Pendirian PT Perorangan', 'tagline' => 'Untuk WNI yang memiliki KTP dan NPWP.', 'price' => 2000000, 'features' => ['Pengecekan dan pemesanan nama', 'Persiapan minuta', 'Pernyataan pendaftaran', 'SK Menteri', 'Bonus hingga 20 KBLI']],
            ['name' => 'PT Perorangan + Izin', 'tagline' => 'Tanpa repot, dengan alamat sendiri.', 'price' => 3500000, 'features' => array_merge(['Pendirian PT Perorangan'], array_slice($permit, 1)), 'is_popular' => true],
            ['name' => 'PT Perorangan + Virtual Office', 'tagline' => 'Harga promo paket lengkap.', 'price' => 4500000, 'original_price' => 5500000, 'features' => array_merge(['PT Perorangan dan perizinan'], array_slice($virtualOffice, 1))],
        ]);

        $create([
            'name' => 'PT PMA (Penanaman Modal Asing)',
            'slug' => 'pendirian-pt-pma',
            'short_name' => 'PT PMA',
            'category' => 'Pendirian Badan Usaha',
            'summary' => 'Pendirian perusahaan dengan kepemilikan modal asing sesuai ketentuan penanaman modal Indonesia.',
            'description' => 'Pendampingan struktur pemegang saham, akta, pengesahan, hingga perizinan berusaha untuk perusahaan penanaman modal asing.',
            'icon' => 'globe',
            'is_featured' => true,
            'is_active' => true,
            'sort_order' => 3,
        ], [
            ['name' => 'Pendirian PT PMA', 'tagline' => 'Paket akta dan pengesahan badan hukum.', 'price' => 5000000, 'features' => $legalBasic],
            ['name' => 'Pendirian PT PMA + Izin', 'tagline' => 'Paket pendirian dan perizinan.', 'price' => 10000000, 'features' => $permit, 'is_popular' => true],
            ['name' => 'PT PMA + Izin + Virtual Office', 'tagline' => 'Solusi lengkap untuk mulai beroperasi.', 'price' => 12000000, 'features' => $virtualOffice],
        ]);

        foreach ([
            ['CV (Commanditaire Vennootschap)', 'pendirian-cv', 'CV', 'Badan usaha persekutuan yang praktis untuk usaha dengan sekutu aktif dan pasif.', 2250000, 4500000, 6500000, 4],
            ['Firma', 'pendirian-firma', 'Firma', 'Persekutuan untuk menjalankan usaha bersama dengan satu nama perusahaan.', 2250000, 4500000, 6500000, 5],
            ['Persekutuan Perdata', 'pendirian-persekutuan-perdata', 'Persekutuan Perdata', 'Persekutuan berdasarkan perjanjian para pihak untuk menjalankan profesi atau usaha bersama.', 2250000, 4500000, 6500000, 6],
        ] as [$name, $slug, $shortName, $summary, $basicPrice, $permitPrice, $voPrice, $sort]) {
            $create([
                'name' => $name,
                'slug' => $slug,
                'short_name' => $shortName,
                'category' => 'Pendirian Badan Usaha',
                'summary' => $summary,
                'description' => "Pengurusan {$shortName} dari pengecekan nama, akta, pencatatan kementerian, sampai perizinan usaha sesuai kebutuhan.",
                'icon' => 'briefcase',
                'is_featured' => in_array($shortName, ['CV', 'Firma'], true),
                'is_active' => true,
                'sort_order' => $sort,
            ], [
                ['name' => "Pendirian {$shortName}", 'tagline' => 'Mau urus izin usaha sendiri.', 'price' => $basicPrice, 'features' => $legalBasic],
                ['name' => "Pendirian {$shortName} + Izin", 'tagline' => 'Tanpa repot, dengan alamat sendiri.', 'price' => $permitPrice, 'features' => $permit, 'is_popular' => true],
                ['name' => "Pendirian {$shortName} + Virtual Office", 'tagline' => 'Paket lengkap dengan kantor prestisius.', 'price' => $voPrice, 'features' => $virtualOffice],
            ]);
        }

        foreach ([
            ['Perkumpulan', 'pendirian-perkumpulan', 'Badan hukum berbasis anggota untuk tujuan sosial, profesi, atau kegiatan bersama.', 7],
            ['Yayasan', 'pendirian-yayasan', 'Badan hukum nirlaba berbasis kekayaan yang dipisahkan untuk tujuan sosial, keagamaan, atau kemanusiaan.', 8],
        ] as [$name, $slug, $summary, $sort]) {
            $create([
                'name' => $name,
                'slug' => $slug,
                'short_name' => $name,
                'category' => 'Organisasi dan Nonprofit',
                'summary' => $summary,
                'description' => "Pendampingan pendirian {$name} mulai dari nama, minuta dan akta, hingga pengesahan badan hukum dan perizinan.",
                'icon' => 'users',
                'is_featured' => $name === 'Yayasan',
                'is_active' => true,
                'sort_order' => $sort,
            ], [
                ['name' => "Pendirian {$name}", 'tagline' => 'Paket akta dan pengesahan.', 'price' => 3000000, 'features' => $legalBasic],
                ['name' => "Pendirian {$name} + Izin", 'tagline' => 'Paket pendirian dan perizinan.', 'price' => 5500000, 'features' => $permit, 'is_popular' => true],
            ]);
        }

        $create([
            'name' => 'NIB & OSS',
            'slug' => 'jasa-nib-oss',
            'short_name' => 'NIB & OSS',
            'category' => 'Perizinan dan Perubahan',
            'summary' => 'Pengurusan Nomor Induk Berusaha dan perizinan berbasis risiko melalui sistem OSS.',
            'description' => 'Tim memeriksa KBLI, tingkat risiko, dan dokumen usaha sebelum memproses NIB serta perizinan yang relevan.',
            'icon' => 'file-check',
            'is_featured' => true,
            'is_active' => true,
            'sort_order' => 9,
        ], [
            ['name' => 'Jasa NIB & OSS', 'tagline' => 'Layanan seluruh Indonesia.', 'price' => 2000000, 'features' => ['Pengurusan NIB di OSS', 'PKKPR', 'Sertifikat Standar MR jika diperlukan', 'SPPL', 'UMK Tata Ruang']],
        ]);

        $create([
            'name' => 'Sewa Virtual Office',
            'slug' => 'virtual-office',
            'short_name' => 'Virtual Office',
            'category' => 'Perizinan dan Perubahan',
            'summary' => 'Alamat kantor komersial dengan fasilitas korespondensi dan ruang rapat.',
            'description' => 'Pilihan lokasi virtual office melalui mitra, dengan fasilitas resepsionis, surat-menyurat, dan meeting room sesuai paket.',
            'icon' => 'map-pin',
            'is_featured' => false,
            'is_active' => true,
            'sort_order' => 10,
        ], [
            ['name' => 'Virtual Office Non-SCBD', 'tagline' => 'Pilihan lokasi di Jakarta.', 'price' => 2300000, 'price_suffix' => '/tahun', 'features' => ['Pilihan lokasi seluruh Jakarta', '60 jam meeting room', 'Akses meeting di 6 lokasi termasuk SCBD', 'Layanan resepsionis', 'Layanan surat-menyurat', 'Zonasi komersial', 'Dapat diajukan PKP']],
            ['name' => 'Virtual Office SCBD', 'tagline' => 'Lokasi Gedung Bursa Efek Indonesia.', 'price' => 3590000, 'price_suffix' => '/tahun', 'features' => ['Gedung Bursa Efek Indonesia SCBD', '60 jam meeting room', 'Akses meeting di 6 lokasi', 'Layanan resepsionis', 'Layanan surat-menyurat', 'Zonasi komersial', 'Dapat diajukan PKP'], 'is_popular' => true],
        ]);

        $create([
            'name' => 'Perubahan Anggaran Dasar',
            'slug' => 'perubahan-anggaran-dasar',
            'short_name' => 'Perubahan Akta',
            'category' => 'Perizinan dan Perubahan',
            'summary' => 'Perubahan data, susunan, modal, maksud dan tujuan, atau ketentuan lain dalam akta perusahaan.',
            'description' => 'Layanan mencakup akta perubahan dan proses persetujuan atau pemberitahuan kepada kementerian sesuai jenis perubahan.',
            'icon' => 'edit',
            'is_featured' => false,
            'is_active' => true,
            'sort_order' => 11,
        ], [
            ['name' => 'Perubahan Anggaran Dasar PT', 'tagline' => 'Layanan seluruh Indonesia.', 'price' => 5000000, 'features' => ['Akta perubahan PT', 'SK Menteri', 'Perjanjian jual beli saham bila diperlukan dan tidak termasuk Akta Jual Beli Saham', 'Konsultasi gratis']],
            ['name' => 'Perubahan Anggaran Dasar CV', 'tagline' => 'Layanan seluruh Indonesia.', 'price' => 3000000, 'features' => ['Akta perubahan CV', 'SK Menteri', 'Perjanjian jual beli saham bila diperlukan', 'Konsultasi gratis']],
        ]);

        $create([
            'name' => 'Pembubaran Perusahaan',
            'slug' => 'pembubaran-perusahaan',
            'short_name' => 'Pembubaran',
            'category' => 'Perizinan dan Perubahan',
            'summary' => 'Pendampingan proses pembubaran dan penutupan administrasi badan usaha.',
            'description' => 'Ruang lingkup dan biaya final ditentukan setelah jenis badan usaha, status pajak, kewajiban, dan dokumen perusahaan diperiksa.',
            'icon' => 'archive',
            'is_featured' => false,
            'is_active' => true,
            'sort_order' => 12,
        ], [
            ['name' => 'Pembubaran Perusahaan', 'tagline' => 'Harga menyesuaikan kondisi perusahaan.', 'price' => 4000000, 'price_suffix' => 'mulai', 'features' => ['Pemeriksaan dokumen perusahaan', 'Penyusunan dokumen pembubaran', 'Pendampingan proses administrasi', 'Penawaran final setelah konsultasi'], 'is_estimated' => true],
        ]);

        $create([
            'name' => 'Perjanjian Perkawinan / Prenuptial Agreement',
            'slug' => 'perjanjian-perkawinan',
            'short_name' => 'Perjanjian Perkawinan',
            'category' => 'Layanan Hukum dan HKI',
            'summary' => 'Penyusunan perjanjian perkawinan sesuai kebutuhan pasangan dan ketentuan hukum.',
            'description' => 'Konsultasi ruang lingkup, penyusunan naskah, koordinasi akta, dan pendampingan pencatatan sesuai kebutuhan.',
            'icon' => 'heart',
            'is_featured' => false,
            'is_active' => true,
            'sort_order' => 13,
        ], [
            ['name' => 'Perjanjian Perkawinan', 'tagline' => 'Harga final setelah konsultasi kebutuhan.', 'price' => 6000000, 'features' => ['Konsultasi kebutuhan para pihak', 'Penyusunan draf perjanjian', 'Koordinasi pembuatan akta', 'Pendampingan pencatatan'], 'is_estimated' => true],
        ]);

        $create([
            'name' => 'Pendaftaran Merek',
            'slug' => 'pendaftaran-merek',
            'short_name' => 'Daftar Merek',
            'category' => 'Layanan Hukum dan HKI',
            'summary' => 'Pemeriksaan awal dan pengajuan perlindungan merek untuk produk atau jasa.',
            'description' => 'Biaya ditampilkan per kelas. Hasil pemeriksaan awal tidak menjamin merek disetujui karena keputusan tetap berada pada instansi berwenang.',
            'icon' => 'badge',
            'is_featured' => false,
            'is_active' => true,
            'sort_order' => 14,
        ], [
            ['name' => 'Pendaftaran Merek UMK', 'tagline' => 'Per kelas merek.', 'price' => 1500000, 'price_suffix' => '/kelas', 'features' => ['Pemeriksaan awal merek', 'Penentuan kelas', 'Persiapan dokumen', 'Pengajuan pendaftaran', 'Pemantauan administrasi'], 'is_estimated' => true],
            ['name' => 'Pendaftaran Merek Umum', 'tagline' => 'Per kelas merek.', 'price' => 3800000, 'price_suffix' => '/kelas', 'features' => ['Pemeriksaan awal merek', 'Penentuan kelas', 'Persiapan dokumen', 'Pengajuan pendaftaran', 'Pemantauan administrasi'], 'is_estimated' => true],
        ]);
    }
}
