<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('role', 24)->default('partner')->index();
            $table->string('partner_code', 32)->nullable()->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('phone', 32)->nullable();
            $table->string('company_name')->nullable();
            $table->string('tax_id', 40)->nullable();
            $table->string('city')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->string('activation_token', 64)->nullable()->index();
            $table->timestamp('activation_expires_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->json('requirements')->nullable()->after('description');
        });

        Schema::table('service_packages', function (Blueprint $table): void {
            $table->unsignedBigInteger('minimum_end_user_price')->nullable()->after('price');
            $table->unsignedBigInteger('partner_price')->nullable()->after('minimum_end_user_price');
        });

        Schema::create('partner_applications', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 32);
            $table->string('company_name')->nullable();
            $table->string('tax_id', 40)->nullable();
            $table->string('city');
            $table->text('address')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt');
            $table->longText('body');
            $table->string('featured_image')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->string('seo_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_number', 64)->unique();
            $table->string('public_token', 64)->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_type', 24)->default('end_user');
            $table->string('recipient_name');
            $table->string('recipient_company')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('recipient_phone', 32)->nullable();
            $table->text('recipient_address')->nullable();
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['created_by', 'created_at']);
            $table->index(['partner_id', 'created_at']);
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('partner_cost')->nullable();
            $table->unsignedBigInteger('minimum_end_user_price')->nullable();
            $table->unsignedBigInteger('line_total');
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();
        });

        Schema::create('email_senders', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('type', 24)->default('whitelabel');
            $table->string('status', 24)->default('approved');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $adminEmail = mb_strtolower(trim((string) env('ADMIN_EMAIL', '')));
        $adminPassword = (string) env('ADMIN_PASSWORD', '');

        if ($adminEmail !== '' && $adminPassword !== '') {
            DB::table('users')->insertOrIgnore([
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

        DB::table('email_senders')->insertOrIgnore([
            'name' => (string) env('MAIL_FROM_NAME', env('APP_NAME', 'IzinHukum')),
            'email' => mb_strtolower((string) env('MAIL_FROM_ADDRESS', env('COMPANY_EMAIL', 'izinhukum@gmail.com'))),
            'type' => 'whitelabel',
            'status' => 'approved',
            'is_default' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $packages = DB::table('service_packages')->get(['id', 'price']);
        foreach ($packages as $package) {
            DB::table('service_packages')->where('id', $package->id)->update([
                'minimum_end_user_price' => (int) round($package->price * 0.70),
                'partner_price' => (int) round($package->price * 0.60),
            ]);
        }

        $requirements = [
            'pendirian-pt' => [
                'Nama PT minimal 3 kata',
                'Alamat usaha (kabupaten/kota)',
                'Minimal 2 pendiri',
                'KTP dan NPWP para pendiri',
                'Informasi modal usaha',
            ],
            'pendirian-cv' => [
                'Nama CV minimal 2 kata',
                'Alamat usaha (kabupaten/kota)',
                'Minimal 2 sekutu: aktif dan pasif',
                'KTP dan NPWP para sekutu',
            ],
            'pendirian-yayasan' => [
                'Nama yayasan minimal 3 kata',
                'Alamat yayasan (kabupaten/kota)',
                'Pembina',
                'Pengurus: Ketua, Sekretaris, dan Bendahara',
                'Pengawas',
                'KTP dan NPWP pengurus',
            ],
            'pendirian-koperasi' => [
                'Nama koperasi minimal 3 kata',
                'Alamat koperasi (kabupaten/kota)',
                'Minimal 9 pendiri',
                'KTP dan NPWP para pendiri',
                'Struktur pengurus dan pengawas',
            ],
            'pendirian-perkumpulan' => [
                'Nama perkumpulan minimal 3 kata',
                'Alamat organisasi (kabupaten/kota)',
                'Minimal 2 pendiri',
                'Struktur pengurus: Ketua, Sekretaris, dan Bendahara',
                'KTP para pendiri',
            ],
            'pendirian-firma' => [
                'Nama firma minimal 2 kata',
                'Alamat usaha (kabupaten/kota)',
                'Minimal 2 sekutu',
                'KTP dan NPWP para sekutu',
            ],
            'pendirian-pt-pma' => [
                'Nama PT minimal 3 kata',
                'Alamat usaha (kabupaten/kota)',
                'Minimal 2 pemegang saham',
                'KTP atau paspor pemegang saham',
                'Informasi modal usaha',
            ],
        ];

        foreach ($requirements as $slug => $items) {
            DB::table('services')->where('slug', $slug)->update([
                'requirements' => json_encode($items, JSON_UNESCAPED_UNICODE),
            ]);
        }

        $this->ensureCooperativeService($now, $requirements['pendirian-koperasi']);

        $fixedPrices = [
            'Pendirian PT' => [6000000, 4200000, 3600000],
            'Pendirian CV' => [4000000, 2800000, 2400000],
            'Pendirian Yayasan' => [4000000, 2800000, 2400000],
            'Pendirian Koperasi' => [6000000, 4200000, 3600000],
            'Pendirian Perkumpulan' => [5000000, 3500000, 3000000],
            'Pendirian Firma' => [4000000, 2800000, 2400000],
            'Pendirian PT PMA' => [8000000, 5600000, 4800000],
        ];

        foreach ($fixedPrices as $name => [$website, $minimum, $partner]) {
            DB::table('service_packages')->where('name', $name)->update([
                'price' => $website,
                'minimum_end_user_price' => $minimum,
                'partner_price' => $partner,
                'updated_at' => $now,
            ]);
        }

        foreach ([
            'Pendirian PT + Izin' => [8000000, 5600000, 4800000],
            'Pendirian PT + Virtual Office' => [10000000, 7000000, 6000000],
        ] as $name => [$website, $minimum, $partner]) {
            DB::table('service_packages')->where('name', $name)->update([
                'price' => $website,
                'minimum_end_user_price' => $minimum,
                'partner_price' => $partner,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_senders');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('partner_applications');

        Schema::table('service_packages', function (Blueprint $table): void {
            $table->dropColumn(['minimum_end_user_price', 'partner_price']);
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('requirements');
        });

        Schema::dropIfExists('users');
    }

    private function ensureCooperativeService($now, array $requirements): void
    {
        $serviceId = DB::table('services')->where('slug', 'pendirian-koperasi')->value('id');

        if (! $serviceId) {
            $serviceId = DB::table('services')->insertGetId([
                'name' => 'Koperasi',
                'slug' => 'pendirian-koperasi',
                'short_name' => 'Koperasi',
                'category' => 'Organisasi dan Nonprofit',
                'summary' => 'Pendirian koperasi berbadan hukum dengan struktur pengurus dan pengawas yang sesuai ketentuan.',
                'description' => 'Pendampingan persiapan pendiri, rapat pembentukan, akta, dan pengesahan badan hukum koperasi.',
                'requirements' => json_encode($requirements, JSON_UNESCAPED_UNICODE),
                'icon' => 'users',
                'is_featured' => true,
                'is_active' => true,
                'sort_order' => 9,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! DB::table('service_packages')->where('name', 'Pendirian Koperasi')->exists()) {
            DB::table('service_packages')->insert([
                'service_id' => $serviceId,
                'name' => 'Pendirian Koperasi',
                'tagline' => 'Paket akta dan pengesahan badan hukum.',
                'price' => 6000000,
                'minimum_end_user_price' => 4200000,
                'partner_price' => 3600000,
                'original_price' => null,
                'price_suffix' => null,
                'features' => json_encode([
                    'Pengecekan dan pemesanan nama',
                    'Persiapan rapat pembentukan',
                    'Akta pendirian',
                    'Pengesahan badan hukum',
                    'Konsultasi struktur pengurus dan pengawas',
                ], JSON_UNESCAPED_UNICODE),
                'is_estimated' => false,
                'is_popular' => true,
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
