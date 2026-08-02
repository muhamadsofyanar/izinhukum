<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->string('landing_eyebrow', 120)->nullable()->after('description');
            $table->string('landing_headline', 220)->nullable()->after('landing_eyebrow');
            $table->text('landing_subheadline')->nullable()->after('landing_headline');
            $table->json('landing_benefits')->nullable()->after('landing_subheadline');
            $table->json('landing_process')->nullable()->after('landing_benefits');
            $table->json('landing_faqs')->nullable()->after('landing_process');
            $table->string('seo_title', 200)->nullable()->after('landing_faqs');
            $table->string('seo_description', 320)->nullable()->after('seo_title');
        });

        foreach (DB::table('services')->orderBy('id')->get() as $service) {
            $requirements = $this->decode($service->requirements);
            $packages = DB::table('service_packages')
                ->where('service_id', $service->id)
                ->where('is_active', true)
                ->orderByDesc('is_popular')
                ->orderBy('sort_order')
                ->get();
            $benefits = $packages
                ->flatMap(fn ($package): array => $this->decode($package->features))
                ->filter()
                ->unique()
                ->take(4)
                ->values()
                ->all();
            if ($benefits === []) {
                $benefits = [
                    'Pemeriksaan kebutuhan dan dokumen awal',
                    'Ruang lingkup serta biaya dikonfirmasi sebelum proses',
                    'Pembaruan progres melalui kanal resmi IzinHukum',
                ];
            }
            $minimum = (int) $packages->where('price', '>', 0)->min('price');
            $estimated = $packages->contains(fn ($package): bool => (bool) $package->is_estimated);
            $priceAnswer = $minimum > 0
                ? 'Pilihan paket pada halaman ini dimulai dari Rp'.number_format($minimum, 0, ',', '.').($estimated ? ' dan dapat berubah setelah ruang lingkup diperiksa.' : '. Kebutuhan di luar paket akan dikonfirmasi terlebih dahulu.')
                : 'Biaya ditentukan setelah tim memeriksa kondisi, dokumen, dan ruang lingkup layanan yang dibutuhkan.';

            DB::table('services')->where('id', $service->id)->update([
                'landing_eyebrow' => $service->category,
                'landing_headline' => $this->headline($service->name, $service->short_name, $service->category),
                'landing_subheadline' => $service->description ?: $service->summary,
                'landing_benefits' => json_encode($benefits, JSON_UNESCAPED_UNICODE),
                'landing_process' => json_encode($this->process($service->category), JSON_UNESCAPED_UNICODE),
                'landing_faqs' => json_encode([
                    [
                        'question' => 'Apa saja yang perlu disiapkan untuk '.$service->short_name.'?',
                        'answer' => $requirements !== []
                            ? 'Persiapan awal meliputi '.implode(', ', array_slice($requirements, 0, 5)).'. Tim akan mengonfirmasi dokumen tambahan sesuai kondisi pemohon.'
                            : 'Tim akan memeriksa identitas, data usaha, dan dokumen yang sudah tersedia sebelum memberikan daftar persyaratan yang sesuai.',
                    ],
                    [
                        'question' => 'Berapa biaya layanan '.$service->short_name.'?',
                        'answer' => $priceAnswer,
                    ],
                    [
                        'question' => 'Berapa lama prosesnya?',
                        'answer' => 'Estimasi diberikan setelah kelengkapan dokumen dan ruang lingkup diperiksa. Waktu penyelesaian dapat dipengaruhi respons instansi, kebutuhan revisi, dan kesiapan data klien.',
                    ],
                    [
                        'question' => 'Bagaimana cara memulai?',
                        'answer' => 'Pilih paket atau isi formulir konsultasi pada halaman ini. Permintaan akan memperoleh nomor referensi, lalu pembahasan dilanjutkan secara manual melalui WhatsApp dengan admin IzinHukum.',
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'seo_title' => Str::limit($service->name.' · Konsultasi & Penawaran', 55, ''),
                'seo_description' => Str::limit($service->summary ?: $service->description, 160, ''),
            ]);
        }

        DB::table('system_settings')->updateOrInsert(['key' => 'feature_service_landing_pages'], [
            'value' => '1',
            'is_encrypted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tagline = DB::table('system_settings')->where('key', 'brand_tagline')->first();
        if (! $tagline) {
            DB::table('system_settings')->insert([
                'key' => 'brand_tagline',
                'value' => 'Jalur Pasti, Usaha Aman',
                'is_encrypted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } elseif (in_array(trim((string) $tagline->value), ['', 'Legalitas sampai tuntas'], true)) {
            DB::table('system_settings')->where('key', 'brand_tagline')->update([
                'value' => 'Jalur Pasti, Usaha Aman',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn([
                'landing_eyebrow', 'landing_headline', 'landing_subheadline',
                'landing_benefits', 'landing_process', 'landing_faqs',
                'seo_title', 'seo_description',
            ]);
        });
        DB::table('system_settings')->where('key', 'feature_service_landing_pages')->delete();
        DB::table('system_settings')
            ->where('key', 'brand_tagline')
            ->where('value', 'Jalur Pasti, Usaha Aman')
            ->update(['value' => 'Legalitas sampai tuntas', 'updated_at' => now()]);
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function headline(string $name, string $shortName, string $category): string
    {
        return match ($category) {
            'Pendirian Badan Usaha' => 'Bangun '.$shortName.' dengan struktur dan dokumen yang lebih siap',
            'Organisasi dan Nonprofit' => 'Dirikan '.$shortName.' dengan struktur organisasi yang lebih jelas',
            'Perizinan dan Perubahan' => 'Urus '.$shortName.' tanpa tersesat di tahapan administrasi',
            'Tata Kelola dan Keuangan' => $name.' yang lebih tertata dan siap digunakan',
            'Branding Bisnis' => 'Bangun identitas bisnis yang konsisten dan siap digunakan',
            'Layanan Hukum dan HKI' => 'Lindungi kepentingan Anda melalui '.$shortName.' yang lebih terarah',
            default => $name.' dengan proses yang lebih jelas dan terpantau',
        };
    }

    private function process(string $category): array
    {
        return match ($category) {
            'Pendirian Badan Usaha', 'Organisasi dan Nonprofit' => [
                ['title' => 'Konsultasi struktur', 'description' => 'Konfirmasi bentuk, pendiri, pengurus, kegiatan, dan tujuan penggunaan badan.'],
                ['title' => 'Pemeriksaan dokumen', 'description' => 'Tim memeriksa identitas, nama, alamat, modal, dan data pendukung.'],
                ['title' => 'Penyusunan dan pengajuan', 'description' => 'Dokumen disiapkan serta diajukan sesuai ruang lingkup paket.'],
                ['title' => 'Hasil dan tindak lanjut', 'description' => 'Klien menerima dokumen hasil serta arahan izin atau kewajiban berikutnya.'],
            ],
            'Perizinan dan Perubahan' => [
                ['title' => 'Pemetaan kebutuhan', 'description' => 'Kegiatan, lokasi, KBLI, status badan, dan perubahan yang dibutuhkan diperiksa.'],
                ['title' => 'Kesiapan dokumen', 'description' => 'Tim mengidentifikasi kekurangan dan persyaratan yang harus dilengkapi.'],
                ['title' => 'Pengajuan atau perubahan', 'description' => 'Proses administrasi dijalankan sesuai instansi dan ruang lingkup.'],
                ['title' => 'Tindak lanjut', 'description' => 'Klarifikasi, pembaruan progres, dan hasil disampaikan melalui kanal resmi.'],
            ],
            'Tata Kelola dan Keuangan' => [
                ['title' => 'Pemeriksaan data', 'description' => 'Agenda, periode, dokumen korporasi, atau data transaksi ditelaah.'],
                ['title' => 'Penetapan ruang lingkup', 'description' => 'Keluaran, kebutuhan koreksi, dan batas pekerjaan disepakati.'],
                ['title' => 'Penyusunan', 'description' => 'Dokumen atau laporan disusun berdasarkan data yang telah dikonfirmasi.'],
                ['title' => 'Review dan finalisasi', 'description' => 'Hasil diperiksa bersama sebelum diserahkan sebagai dokumen final.'],
            ],
            'Branding Bisnis' => [
                ['title' => 'Brief bisnis', 'description' => 'Profil, target pasar, karakter, media, dan referensi visual dikonfirmasi.'],
                ['title' => 'Arah konsep', 'description' => 'Tim menyiapkan pendekatan visual sesuai tujuan dan ruang lingkup.'],
                ['title' => 'Review dan penyempurnaan', 'description' => 'Konsep terpilih disempurnakan berdasarkan umpan balik yang disepakati.'],
                ['title' => 'Penyerahan aset', 'description' => 'Aset final diserahkan sesuai keluaran paket.'],
            ],
            default => [
                ['title' => 'Konsultasi awal', 'description' => 'Kebutuhan, tujuan, kondisi, dan dokumen awal dikonfirmasi.'],
                ['title' => 'Pemeriksaan', 'description' => 'Tim memetakan risiko, persyaratan, dan ruang lingkup pekerjaan.'],
                ['title' => 'Pelaksanaan', 'description' => 'Penyusunan atau pengajuan dijalankan sesuai penawaran yang disetujui.'],
                ['title' => 'Hasil dan tindak lanjut', 'description' => 'Hasil akhir dan langkah berikutnya disampaikan kepada klien.'],
            ],
        };
    }
};
