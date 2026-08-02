<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('code', 32)->unique();
            $table->text('description')->nullable();
            $table->string('discount_type', 16);
            $table->unsignedBigInteger('discount_value');
            $table->unsignedBigInteger('maximum_discount')->nullable();
            $table->unsignedBigInteger('minimum_subtotal')->default(0);
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->unsignedInteger('maximum_redemptions')->nullable();
            $table->boolean('applies_to_all_services')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('coupon_service', function (Blueprint $table): void {
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['coupon_id', 'service_id']);
        });

        Schema::table('inquiries', function (Blueprint $table): void {
            $table->foreignId('coupon_id')->nullable()->after('partner_referral_id')->constrained()->nullOnDelete();
            $table->string('coupon_code', 32)->nullable()->after('referral_code')->index();
            $table->string('coupon_discount_type', 16)->nullable()->after('coupon_code');
            $table->unsignedBigInteger('coupon_discount_value')->default(0)->after('coupon_discount_type');
            $table->unsignedBigInteger('coupon_discount_amount')->default(0)->after('coupon_discount_value');
        });

        Schema::table('service_orders', function (Blueprint $table): void {
            $table->foreignId('coupon_id')->nullable()->after('partner_referral_id')->constrained()->nullOnDelete();
            $table->string('coupon_code', 32)->nullable()->after('referral_code')->index();
            $table->string('coupon_discount_type', 16)->nullable()->after('coupon_code');
            $table->unsignedBigInteger('coupon_discount_value')->default(0)->after('coupon_discount_type');
            $table->unsignedBigInteger('coupon_discount_amount')->default(0)->after('coupon_discount_value');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('coupon_id')->nullable()->after('referred_by_partner_id')->constrained()->nullOnDelete();
            $table->string('coupon_code', 32)->nullable()->after('referral_code')->index();
            $table->string('coupon_discount_type', 16)->nullable()->after('coupon_code');
            $table->unsignedBigInteger('coupon_discount_value')->default(0)->after('coupon_discount_type');
        });

        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inquiry_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('coupon_code', 32)->index();
            $table->unsignedBigInteger('discount_amount');
            $table->timestamp('redeemed_at')->index();
            $table->timestamps();
            $table->index(['coupon_id', 'redeemed_at']);
        });

        $this->publishAdditionalServices();
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['coupon_code', 'coupon_discount_type', 'coupon_discount_value']);
        });

        Schema::table('service_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['coupon_code', 'coupon_discount_type', 'coupon_discount_value', 'coupon_discount_amount']);
        });

        Schema::table('inquiries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['coupon_code', 'coupon_discount_type', 'coupon_discount_value', 'coupon_discount_amount']);
        });

        Schema::dropIfExists('coupon_service');
        Schema::dropIfExists('coupons');

        // Katalog tidak dihapus saat rollback agar order, inquiry, dan harga
        // yang sudah disesuaikan admin tidak ikut hilang.
    }

    private function publishAdditionalServices(): void
    {
        $services = [
            [
                'service' => [
                    'name' => 'Pengukuhan PKP',
                    'slug' => 'pengukuhan-pkp',
                    'short_name' => 'PKP',
                    'category' => 'Perizinan dan Perubahan',
                    'summary' => 'Pendampingan persiapan dan pengajuan pengukuhan Pengusaha Kena Pajak sesuai kondisi usaha.',
                    'description' => 'Tim memeriksa kesiapan administrasi perpajakan, alamat, kegiatan usaha, serta dokumen pendukung sebelum pengajuan PKP.',
                    'requirements' => ['NPWP badan dan data pengurus', 'NIB dan dokumen legalitas badan', 'Bukti tempat kegiatan usaha', 'Dokumen transaksi dan administrasi pajak yang tersedia'],
                    'icon' => 'file-check',
                    'is_featured' => true,
                    'sort_order' => 15,
                ],
                'package' => [
                    'name' => 'Pendampingan Pengukuhan PKP',
                    'tagline' => 'Penawaran setelah pemeriksaan kesiapan dokumen.',
                    'features' => ['Pemeriksaan kesiapan awal', 'Daftar dokumen sesuai kondisi usaha', 'Pendampingan pengajuan', 'Tindak lanjut klarifikasi administrasi'],
                ],
            ],
            [
                'service' => [
                    'name' => 'Layanan RUPS',
                    'slug' => 'layanan-rups',
                    'short_name' => 'RUPS',
                    'category' => 'Tata Kelola dan Keuangan',
                    'summary' => 'Persiapan RUPS tahunan atau RUPS lainnya beserta dokumen korporasi yang diperlukan.',
                    'description' => 'Ruang lingkup disesuaikan dengan agenda, anggaran dasar, komposisi pemegang saham, dan kebutuhan akta atau pemberitahuan perusahaan.',
                    'requirements' => ['Anggaran dasar dan perubahan terakhir', 'Daftar pemegang saham', 'Agenda RUPS', 'Dokumen pendukung keputusan yang akan dibahas'],
                    'icon' => 'users',
                    'is_featured' => true,
                    'sort_order' => 16,
                ],
                'package' => [
                    'name' => 'Pendampingan RUPS',
                    'tagline' => 'Penawaran menyesuaikan agenda dan dokumen perusahaan.',
                    'features' => ['Pemeriksaan agenda dan anggaran dasar', 'Persiapan undangan dan bahan rapat', 'Draf risalah atau keputusan', 'Koordinasi tindak lanjut korporasi'],
                ],
            ],
            [
                'service' => [
                    'name' => 'Penyusunan Laporan Keuangan',
                    'slug' => 'laporan-keuangan',
                    'short_name' => 'Laporan Keuangan',
                    'category' => 'Tata Kelola dan Keuangan',
                    'summary' => 'Penyusunan laporan keuangan usaha berdasarkan data transaksi dan periode yang disepakati.',
                    'description' => 'Layanan dapat mencakup penataan data, rekonsiliasi, dan penyusunan laporan dasar. Ruang lingkup final bergantung pada volume serta kondisi pembukuan.',
                    'requirements' => ['Data pemasukan dan pengeluaran', 'Mutasi rekening periode terkait', 'Daftar aset, utang, dan piutang', 'Dokumen pajak atau pembukuan yang tersedia'],
                    'icon' => 'chart',
                    'is_featured' => true,
                    'sort_order' => 17,
                ],
                'package' => [
                    'name' => 'Penyusunan Laporan Keuangan',
                    'tagline' => 'Penawaran berdasarkan periode dan volume transaksi.',
                    'features' => ['Pemeriksaan data awal', 'Rekonsiliasi sesuai ruang lingkup', 'Laporan laba rugi', 'Neraca dan ringkasan arus kas'],
                ],
            ],
            [
                'service' => [
                    'name' => 'Brand Identity',
                    'slug' => 'brand-identity',
                    'short_name' => 'Brand Identity',
                    'category' => 'Branding Bisnis',
                    'summary' => 'Identitas visual dasar untuk membantu bisnis tampil konsisten setelah legalitasnya siap.',
                    'description' => 'Paket disusun setelah kebutuhan merek, media utama, dan keluaran desain dikonfirmasi bersama klien.',
                    'requirements' => ['Nama dan profil singkat usaha', 'Target pasar', 'Referensi visual yang disukai atau dihindari', 'Daftar media yang akan digunakan'],
                    'icon' => 'palette',
                    'is_featured' => true,
                    'sort_order' => 18,
                ],
                'package' => [
                    'name' => 'Paket Brand Identity',
                    'tagline' => 'Penawaran menyesuaikan keluaran identitas visual.',
                    'features' => ['Arahan konsep visual', 'Logo utama', 'Palet warna dan tipografi', 'Aplikasi identitas dasar sesuai kesepakatan'],
                ],
            ],
            [
                'service' => [
                    'name' => 'Perizinan Lainnya',
                    'slug' => 'perizinan-lainnya',
                    'short_name' => 'Izin Lainnya',
                    'category' => 'Perizinan dan Perubahan',
                    'summary' => 'Pemeriksaan kebutuhan izin sektoral atau izin pendukung yang belum tercantum pada katalog.',
                    'description' => 'Tim mengidentifikasi instansi, dasar kebutuhan, tahapan, dan dokumen awal sebelum memberikan ruang lingkup serta penawaran.',
                    'requirements' => ['NIB dan KBLI usaha', 'Lokasi kegiatan', 'Uraian kegiatan dan produk/jasa', 'Izin atau dokumen pendukung yang sudah dimiliki'],
                    'icon' => 'file-check',
                    'is_featured' => false,
                    'sort_order' => 19,
                ],
                'package' => [
                    'name' => 'Konsultasi Perizinan Lainnya',
                    'tagline' => 'Penawaran setelah identifikasi izin dan ruang lingkup.',
                    'features' => ['Identifikasi jenis izin', 'Pemeriksaan persyaratan awal', 'Pemetaan tahapan dan instansi', 'Penawaran pendampingan sesuai kebutuhan'],
                ],
            ],
        ];

        foreach ($services as $entry) {
            $service = $entry['service'];
            $requirements = $service['requirements'];
            unset($service['requirements']);
            $now = now();
            $serviceId = DB::table('services')->where('slug', $service['slug'])->value('id');

            if (! $serviceId) {
                $serviceId = DB::table('services')->insertGetId([
                    ...$service,
                    'requirements' => json_encode($requirements, JSON_UNESCAPED_UNICODE),
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $package = $entry['package'];
            if (! DB::table('service_packages')->where('service_id', $serviceId)->where('name', $package['name'])->exists()) {
                DB::table('service_packages')->insert([
                    'service_id' => $serviceId,
                    'name' => $package['name'],
                    'tagline' => $package['tagline'],
                    'price' => 0,
                    'minimum_end_user_price' => 0,
                    'partner_price' => 0,
                    'original_price' => null,
                    'price_suffix' => 'penawaran',
                    'features' => json_encode($package['features'], JSON_UNESCAPED_UNICODE),
                    'is_estimated' => true,
                    'is_popular' => false,
                    'is_active' => true,
                    'sort_order' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
