<?php

use Database\Seeders\ServiceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('partner_applications', 'password')) {
            Schema::table('partner_applications', function (Blueprint $table): void {
                $table->string('password')->nullable()->after('email');
            });
        }

        (new ServiceSeeder())->run();

        $now = now();
        $adminEmail = mb_strtolower(trim((string) env('ADMIN_EMAIL', '')));
        $adminPassword = (string) env('ADMIN_PASSWORD', '');

        if ($adminEmail !== '' && $adminPassword !== '' && ! DB::table('users')->where('email', $adminEmail)->exists()) {
            DB::table('users')->insert([
                'role' => 'admin',
                'name' => 'Administrator IzinHukum',
                'email' => $adminEmail,
                'password' => Hash::make($adminPassword),
                'is_active' => true,
                'email_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $authorId = DB::table('users')->where('role', 'admin')->value('id');
        foreach ($this->articles() as $article) {
            DB::table('articles')->updateOrInsert(
                ['slug' => $article['slug']],
                [
                    ...$article,
                    'author_id' => $authorId,
                    'status' => 'published',
                    'published_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('articles')->whereIn('slug', array_column($this->articles(), 'slug'))->delete();
        if (Schema::hasColumn('partner_applications', 'password')) {
            Schema::table('partner_applications', function (Blueprint $table): void {
                $table->dropColumn('password');
            });
        }
    }

    private function articles(): array
    {
        $items = [
            ['Panduan Memilih Bentuk Badan Usaha yang Tepat', 'Kenali perbedaan PT, PT Perorangan, CV, Firma, dan badan usaha lain sebelum memulai proses pendirian.', "Pemilihan bentuk badan usaha perlu disesuaikan dengan jumlah pendiri, skala kegiatan, kebutuhan modal, tanggung jawab hukum, dan rencana pengembangan bisnis.\n\nPT cocok untuk usaha yang membutuhkan badan hukum serta struktur kepemilikan saham. PT Perorangan ditujukan bagi usaha mikro dan kecil yang memenuhi ketentuan dan didirikan oleh satu orang. CV dan Firma merupakan bentuk persekutuan yang memiliki karakter tanggung jawab berbeda.\n\nSebelum menentukan pilihan, siapkan gambaran kegiatan usaha, komposisi pendiri, alamat, modal, serta KBLI yang akan digunakan."],
            ['Apa Itu NIB dan Mengapa Usaha Membutuhkannya?', 'NIB menjadi identitas pelaku usaha dalam sistem OSS dan menjadi titik awal pemenuhan perizinan berbasis risiko.', "Nomor Induk Berusaha atau NIB diterbitkan melalui sistem OSS dan berfungsi sebagai identitas pelaku usaha.\n\nNIB tidak selalu menjadi satu-satunya dokumen yang dibutuhkan. Bergantung pada tingkat risiko dan bidang kegiatan, pelaku usaha mungkin perlu memenuhi Sertifikat Standar, izin, persetujuan lingkungan, atau persyaratan sektoral lain.\n\nKarena itu, pemilihan KBLI dan pengisian data harus dilakukan dengan cermat."],
            ['Cara Menentukan KBLI untuk Kegiatan Usaha', 'KBLI yang tepat membantu menentukan ruang lingkup kegiatan, tingkat risiko, dan perizinan yang harus dipenuhi.', "KBLI mengelompokkan kegiatan ekonomi berdasarkan aktivitas yang dijalankan. Penentuan kode sebaiknya dimulai dari produk atau jasa utama, proses operasional, cara penjualan, serta pihak yang dilayani.\n\nHindari memilih kode hanya karena namanya terdengar mirip. Baca uraian dan ruang lingkup tiap kode, lalu periksa persyaratan tambahannya."],
            ['Dokumen Dasar untuk Mendirikan Perseroan Terbatas', 'Siapkan nama, identitas pendiri, alamat, struktur saham, modal, dan rencana kegiatan agar proses pendirian PT lebih lancar.', "Pendirian PT umumnya dimulai dengan penentuan nama, kedudukan, alamat lengkap, maksud dan tujuan, struktur pemegang saham, pengurus, serta modal perseroan.\n\nKelengkapan sejak awal mengurangi risiko koreksi akta, ketidaksesuaian OSS, dan keterlambatan proses administrasi."],
            ['Perbedaan Yayasan, Perkumpulan, dan Koperasi', 'Ketiganya memiliki tujuan, struktur, dan dasar keanggotaan yang berbeda sehingga tidak dapat dipertukarkan begitu saja.', "Yayasan menggunakan kekayaan yang dipisahkan untuk tujuan sosial, keagamaan, atau kemanusiaan dan tidak berbasis anggota. Perkumpulan merupakan badan hukum berbasis anggota.\n\nKoperasi juga berbasis anggota, tetapi menjalankan kegiatan berdasarkan prinsip koperasi untuk memenuhi kepentingan ekonomi anggotanya."],
            ['Kapan Perusahaan Perlu Mengubah Akta?', 'Perubahan nama, alamat, modal, pemegang saham, pengurus, dan kegiatan usaha tertentu perlu dicatat melalui mekanisme yang sesuai.', "Perusahaan perlu meninjau akta ketika terjadi perubahan data penting seperti nama, kedudukan, modal, komposisi pemegang saham, susunan direksi atau komisaris, dan maksud serta tujuan.\n\nData pada OSS, pajak, perbankan, dan dokumen operasional juga perlu diselaraskan."],
        ];

        return array_map(fn (array $item): array => [
            'title' => $item[0],
            'slug' => Str::slug($item[0]),
            'excerpt' => $item[1],
            'body' => $item[2],
            'seo_title' => $item[0].' | IzinHukum',
            'meta_description' => $item[1],
        ], $items);
    }
};
