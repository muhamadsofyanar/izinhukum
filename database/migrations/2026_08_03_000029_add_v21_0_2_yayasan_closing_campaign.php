<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->repairMarketingCampaignSchema();

        $now = now();
        $serviceId = DB::table('services')->where('slug', 'pendirian-yayasan')->value('id');

        if (! $serviceId) {
            $serviceId = DB::table('services')->insertGetId([
                'name' => 'Yayasan',
                'slug' => 'pendirian-yayasan',
                'short_name' => 'Yayasan',
                'category' => 'Organisasi dan Nonprofit',
                'summary' => 'Badan hukum berbasis kekayaan yang dipisahkan untuk tujuan sosial, keagamaan, atau kemanusiaan.',
                'description' => 'Pendampingan pendirian Yayasan mulai dari pemeriksaan tujuan, nama, struktur organ, akta, hingga pengesahan badan hukum dan pemetaan izin sesuai kegiatan.',
                'requirements' => json_encode($this->requirements(), JSON_UNESCAPED_UNICODE),
                'icon' => 'users',
                'is_featured' => true,
                'is_active' => true,
                'sort_order' => 8,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('services')->where('id', $serviceId)->update([
            'summary' => 'Badan hukum berbasis kekayaan yang dipisahkan untuk tujuan sosial, keagamaan, atau kemanusiaan.',
            'description' => 'Pendampingan pendirian Yayasan mulai dari pemeriksaan tujuan, nama, struktur organ, akta, hingga pengesahan badan hukum dan pemetaan izin sesuai kegiatan.',
            'requirements' => json_encode($this->requirements(), JSON_UNESCAPED_UNICODE),
            'landing_eyebrow' => 'Pendirian Yayasan · Konsultasi awal gratis',
            'landing_headline' => 'Dirikan Yayasan dengan Struktur yang Jelas Sejak Awal',
            'landing_subheadline' => 'Tim IzinHukum membantu memeriksa tujuan, alternatif nama, kedudukan, kekayaan awal, serta susunan Pembina, Pengurus, dan Pengawas sebelum akta dan pengesahan badan hukum diproses.',
            'landing_benefits' => json_encode([
                'Struktur organ Yayasan diperiksa sebelum penyusunan akta',
                'Ruang lingkup, biaya, dan dokumen dijelaskan sejak awal',
                'Pengesahan badan hukum diproses melalui jalur resmi AHU',
                'Kebutuhan izin lanjutan dipetakan sesuai kegiatan Yayasan',
            ], JSON_UNESCAPED_UNICODE),
            'landing_process' => json_encode([
                ['title' => 'Cek kesiapan', 'description' => 'Konfirmasi tujuan Yayasan, kota kedudukan, alternatif nama, kekayaan awal, dan rencana kegiatan.'],
                ['title' => 'Periksa struktur dan dokumen', 'description' => 'Tim memeriksa data Pembina, Pengurus, Pengawas, identitas, NPWP, serta dokumen pendukung.'],
                ['title' => 'Akta dan pengesahan', 'description' => 'Minuta dan akta disiapkan, lalu permohonan pengesahan badan hukum diajukan melalui sistem AHU.'],
                ['title' => 'Serah hasil dan tindak lanjut', 'description' => 'Dokumen hasil diserahkan bersama arahan NPWP, NIB, atau izin sektoral yang relevan dengan kegiatan Yayasan.'],
            ], JSON_UNESCAPED_UNICODE),
            'landing_faqs' => json_encode($this->faqs(), JSON_UNESCAPED_UNICODE),
            'seo_title' => 'Pendirian Yayasan · Akta & Pengesahan Badan Hukum',
            'seo_description' => 'Jasa pendirian Yayasan: pemeriksaan nama, struktur Pembina, Pengurus dan Pengawas, akta, pengesahan AHU, serta arahan izin lanjutan.',
            'is_featured' => true,
            'is_active' => true,
            'updated_at' => $now,
        ]);

        $basicPackageId = $this->upsertPackage(
            $serviceId,
            'Pendirian Yayasan',
            'Paket akta dan pengesahan badan hukum Yayasan.',
            4000000,
            2800000,
            2400000,
            $this->basicFeatures(),
            true,
            1,
            $now,
        );
        $this->upsertPackage(
            $serviceId,
            'Pendirian Yayasan + Izin',
            'Pendirian plus pemetaan dan pendampingan izin sesuai kegiatan.',
            5500000,
            3850000,
            3300000,
            $this->permitFeatures(),
            false,
            2,
            $now,
        );

        $couponId = DB::table('coupons')->where('code', 'YAYASAN300')->value('id');
        $coupon = [
            'name' => 'Promo Pendirian Yayasan Agustus 2026',
            'description' => 'Potongan Rp300.000 untuk paket Pendirian Yayasan. Kuota 20 lead selama periode campaign.',
            'discount_type' => 'fixed',
            'discount_value' => 300000,
            'maximum_discount' => 300000,
            'minimum_subtotal' => 4000000,
            'starts_at' => '2026-08-03 00:00:00',
            'ends_at' => '2026-08-17 23:59:59',
            'maximum_redemptions' => 20,
            'applies_to_all_services' => false,
            'is_active' => true,
            'updated_at' => $now,
        ];

        if ($couponId) {
            DB::table('coupons')->where('id', $couponId)->update($coupon);
        } else {
            $couponId = DB::table('coupons')->insertGetId([
                ...$coupon,
                'created_by' => null,
                'code' => 'YAYASAN300',
                'created_at' => $now,
            ]);
        }

        DB::table('coupon_service')->insertOrIgnore([
            'coupon_id' => $couponId,
            'service_id' => $serviceId,
        ]);

        $campaign = [
            'name' => 'Promo Pendirian Yayasan Agustus 2026',
            'service_id' => $serviceId,
            'coupon_id' => $couponId,
            'source' => 'whatsapp',
            'medium' => 'broadcast-yayasan',
            'landing_headline' => 'Dirikan Yayasan Lebih Siap, Hemat Rp300.000',
            'landing_subheadline' => 'Cek kesiapan nama, tujuan, kedudukan, kekayaan awal, serta struktur Pembina, Pengurus, dan Pengawas. Promo berlaku untuk 20 pendaftar hingga 17 Agustus 2026.',
            'cta_text' => 'Ambil Promo Yayasan',
            'is_landing_enabled' => true,
            'start_date' => '2026-08-03',
            'end_date' => '2026-08-17',
            'budget' => 0,
            'spend' => 0,
            'status' => 'active',
            'notes' => 'Fokus closing Yayasan. Respons lead secepat mungkin, cek 5 data awal, lalu kirim penawaran resmi. Kupon YAYASAN300 otomatis terisi pada landing.',
            'updated_at' => $now,
        ];

        if (DB::table('marketing_campaigns')->where('slug', 'yayasan-agustus-2026')->exists()) {
            DB::table('marketing_campaigns')->where('slug', 'yayasan-agustus-2026')->update($campaign);
        } else {
            DB::table('marketing_campaigns')->insert([
                ...$campaign,
                'slug' => 'yayasan-agustus-2026',
                'landing_views' => 0,
                'created_by' => null,
                'created_at' => $now,
            ]);
        }

        // Menjaga paket dasar sebagai pilihan pertama pada form campaign.
        DB::table('service_packages')->where('id', $basicPackageId)->update(['is_popular' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('marketing_campaigns', 'coupon_id')) {
            Schema::table('marketing_campaigns', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('coupon_id');
            });
        }

        // service_id sengaja dipertahankan karena merupakan bagian dari skema
        // inti V20; blok up() di atas hanya memperbaiki instalasi yang drift.

        // Konten, paket, campaign, dan kupon tidak dihapus saat rollback agar
        // lead, penggunaan promo, serta perubahan admin tetap dapat diaudit.
    }

    /**
     * Memulihkan seluruh skema campaign yang dipakai V20–V21.
     *
     * Beberapa database produksi sudah mencatat migrasi 000027 sebagai "Ran",
     * tetapi tabelnya berasal dari revisi skema yang lebih awal. Pemeriksaan
     * lengkap mencegah proses retry berhenti satu per satu pada kolom berikutnya.
     */
    private function repairMarketingCampaignSchema(): void
    {
        if (! Schema::hasTable('marketing_campaigns')) {
            Schema::create('marketing_campaigns', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 160);
                $table->string('slug', 180)->unique();
                $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
                $table->string('source', 120)->default('whatsapp')->index();
                $table->string('medium', 120)->default('broadcast')->index();
                $table->string('landing_headline', 180)->nullable();
                $table->text('landing_subheadline')->nullable();
                $table->string('cta_text', 80)->default('Konsultasi sekarang');
                $table->boolean('is_landing_enabled')->default(true)->index();
                $table->unsignedBigInteger('landing_views')->default(0);
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->unsignedBigInteger('budget')->default(0);
                $table->unsignedBigInteger('spend')->default(0);
                $table->string('status', 24)->default('active')->index();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        } else {
            $columns = array_fill_keys(Schema::getColumnListing('marketing_campaigns'), true);

            Schema::table('marketing_campaigns', function (Blueprint $table) use ($columns): void {
                if (! isset($columns['name'])) {
                    $table->string('name', 160)->default('Campaign');
                }
                if (! isset($columns['slug'])) {
                    $table->string('slug', 180)->nullable()->unique();
                }
                if (! isset($columns['service_id'])) {
                    $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
                }
                if (! isset($columns['coupon_id'])) {
                    $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
                }
                if (! isset($columns['source'])) {
                    $table->string('source', 120)->default('whatsapp')->index();
                }
                if (! isset($columns['medium'])) {
                    $table->string('medium', 120)->default('broadcast')->index();
                }
                if (! isset($columns['landing_headline'])) {
                    $table->string('landing_headline', 180)->nullable();
                }
                if (! isset($columns['landing_subheadline'])) {
                    $table->text('landing_subheadline')->nullable();
                }
                if (! isset($columns['cta_text'])) {
                    $table->string('cta_text', 80)->default('Konsultasi sekarang');
                }
                if (! isset($columns['is_landing_enabled'])) {
                    $table->boolean('is_landing_enabled')->default(true)->index();
                }
                if (! isset($columns['landing_views'])) {
                    $table->unsignedBigInteger('landing_views')->default(0);
                }
                if (! isset($columns['start_date'])) {
                    $table->date('start_date')->nullable();
                }
                if (! isset($columns['end_date'])) {
                    $table->date('end_date')->nullable();
                }
                if (! isset($columns['budget'])) {
                    $table->unsignedBigInteger('budget')->default(0);
                }
                if (! isset($columns['spend'])) {
                    $table->unsignedBigInteger('spend')->default(0);
                }
                if (! isset($columns['status'])) {
                    $table->string('status', 24)->default('active')->index();
                }
                if (! isset($columns['notes'])) {
                    $table->text('notes')->nullable();
                }
                if (! isset($columns['created_by'])) {
                    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                }
                if (! isset($columns['created_at'])) {
                    $table->timestamp('created_at')->nullable();
                }
                if (! isset($columns['updated_at'])) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        if (! Schema::hasColumn('inquiries', 'marketing_campaign_id')) {
            Schema::table('inquiries', function (Blueprint $table): void {
                $table->foreignId('marketing_campaign_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete();
            });
        }
    }

    private function upsertPackage(
        int $serviceId,
        string $name,
        string $tagline,
        int $price,
        int $minimumEndUserPrice,
        int $partnerPrice,
        array $features,
        bool $popular,
        int $sortOrder,
        mixed $now,
    ): int {
        $packageId = DB::table('service_packages')
            ->where('service_id', $serviceId)
            ->where('name', $name)
            ->value('id');
        $contentAttributes = [
            'tagline' => $tagline,
            'features' => json_encode($features, JSON_UNESCAPED_UNICODE),
            'is_popular' => $popular,
            'is_active' => true,
            'sort_order' => $sortOrder,
            'updated_at' => $now,
        ];

        if ($packageId) {
            // Harga produksi yang pernah disunting admin tidak ditimpa.
            DB::table('service_packages')->where('id', $packageId)->update($contentAttributes);

            return (int) $packageId;
        }

        return (int) DB::table('service_packages')->insertGetId([
            ...$contentAttributes,
            'service_id' => $serviceId,
            'name' => $name,
            'price' => $price,
            'minimum_end_user_price' => $minimumEndUserPrice,
            'partner_price' => $partnerPrice,
            'original_price' => null,
            'price_suffix' => null,
            'is_estimated' => false,
            'created_at' => $now,
        ]);
    }

    private function requirements(): array
    {
        return [
            'Alternatif nama Yayasan minimal 3 kata',
            'Tujuan sosial, keagamaan, dan/atau kemanusiaan',
            'Alamat Yayasan (kabupaten/kota)',
            'Data kekayaan awal yang dipisahkan dari kekayaan pendiri',
            'Data Pembina',
            'Data Pengurus: Ketua, Sekretaris, dan Bendahara',
            'Data Pengawas',
            'KTP dan NPWP pihak terkait',
        ];
    }

    private function basicFeatures(): array
    {
        return [
            'Konsultasi tujuan, kegiatan, dan struktur Yayasan',
            'Pengecekan dan pemesanan nama Yayasan',
            'Pemeriksaan susunan Pembina, Pengurus, dan Pengawas',
            'Persiapan minuta pendirian',
            'Akta pendirian Yayasan',
            'Pengajuan pengesahan badan hukum melalui AHU',
            'Dokumen hasil dan arahan kewajiban berikutnya',
        ];
    }

    private function permitFeatures(): array
    {
        return [
            'Seluruh ruang lingkup Paket Pendirian Yayasan',
            'Pendampingan NPWP badan',
            'Pemetaan kegiatan dan KBLI yang relevan',
            'Pendampingan NIB apabila diperlukan dan dapat diterapkan',
            'Identifikasi izin sektoral sesuai kegiatan Yayasan',
            'Daftar tindak lanjut untuk izin tambahan di luar paket',
            'Biaya tambahan selalu dikonfirmasi sebelum dikerjakan',
        ];
    }

    private function faqs(): array
    {
        return [
            ['question' => 'Apa saja yang diperiksa sebelum pendirian Yayasan?', 'answer' => 'Tim memeriksa tujuan, alternatif nama, kedudukan, kekayaan awal, rencana kegiatan, susunan Pembina, Pengurus, dan Pengawas, serta identitas pihak terkait.'],
            ['question' => 'Apakah Yayasan memiliki anggota?', 'answer' => 'Yayasan dibentuk dari kekayaan yang dipisahkan dan tidak memiliki anggota. Tata kelolanya dijalankan melalui organ Pembina, Pengurus, dan Pengawas.'],
            ['question' => 'Siapa saja yang perlu ada dalam struktur Yayasan?', 'answer' => 'Struktur Yayasan mencakup Pembina, Pengurus, dan Pengawas. Data, peran, serta kemungkinan rangkap jabatan akan diperiksa sebelum penyusunan akta.'],
            ['question' => 'Apakah kekayaan awal termasuk biaya jasa?', 'answer' => 'Tidak. Kekayaan awal merupakan aset yang dipisahkan sebagai kekayaan Yayasan. Biaya layanan IzinHukum ditampilkan terpisah dan dikonfirmasi sebelum proses dimulai.'],
            ['question' => 'Apakah nama Yayasan pasti disetujui?', 'answer' => 'Persetujuan nama tetap menjadi kewenangan sistem dan instansi berwenang. Karena itu, siapkan beberapa alternatif nama agar pemeriksaan dapat berjalan lebih cepat.'],
            ['question' => 'Apa yang termasuk Paket Pendirian Yayasan?', 'answer' => 'Paket mencakup konsultasi struktur, pemeriksaan nama dan data, persiapan minuta, akta, pengajuan pengesahan badan hukum melalui AHU, serta arahan tindak lanjut.'],
            ['question' => 'Kapan memilih paket Yayasan + Izin?', 'answer' => 'Pilih paket ini bila Yayasan membutuhkan pemetaan KBLI, NPWP, NIB bila relevan, atau identifikasi izin sektoral. Kebutuhan final bergantung pada kegiatan yang akan dijalankan.'],
            ['question' => 'Apakah semua Yayasan wajib memiliki NIB?', 'answer' => 'Kebutuhan NIB dan izin lain ditentukan oleh kegiatan Yayasan. Tim akan memetakan kegiatan terlebih dahulu dan tidak menjanjikan izin yang tidak relevan.'],
            ['question' => 'Berapa lama proses pendirian Yayasan?', 'answer' => 'Estimasi diberikan setelah data dan dokumen diperiksa. Waktu dapat dipengaruhi ketersediaan nama, kesiapan para pihak, revisi dokumen, dan proses pada instansi.'],
            ['question' => 'Bagaimana cara memakai promo YAYASAN300?', 'answer' => 'Pilih paket Yayasan pada halaman ini. Kode YAYASAN300 otomatis terisi selama periode dan kuota promo masih tersedia, lalu potongan dicatat saat formulir dikirim.'],
        ];
    }
};
