# IzinHukum Operational Platform Upgrade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Menghasilkan source Laravel siap deploy yang memiliki katalog KBLI terjamin, LMS internal dan aman, dokumen bermerek, kwitansi pembayaran, sertifikat khusus, serta laporan keuangan operasional.

**Architecture:** Data transaksi menggunakan invoice sebagai tagihan, payment sebagai realisasi pemasukan, dan expense sebagai pengeluaran. Dokumen invoice, kwitansi, dan sertifikat memakai satu sumber identitas merek. Materi LMS disajikan melalui rute berotorisasi, sedangkan video YouTube diubah menjadi URL embed yang aman.

**Tech Stack:** PHP 8.4, Laravel 12, Blade, MySQL/MariaDB, Vite, Bootstrap, SCSS, PHPUnit 11, Docker Compose.

## Global Constraints

- Baseline adalah `izinhukum-main.zip` tertanggal 29 Juli 2026.
- Data dan harga yang sudah diedit admin tidak boleh ditimpa oleh migrasi.
- Dataset KBLI 2025 harus berisi tepat 1.559 kode.
- Materi LMS privat hanya boleh dibuka oleh admin atau peserta terdaftar.
- Laporan keuangan hanya tersedia untuk admin.
- Pemasukan laporan berasal dari pembayaran aktual, bukan nilai invoice yang belum dibayar.
- Tidak menambahkan dependensi PDF eksternal. Dokumen memakai halaman cetak khusus yang dapat disimpan sebagai PDF oleh browser.
- Arsip sumber tidak menyertakan metadata Git. Langkah commit dilakukan setelah paket diterapkan pada repository pemilik.

---

### Task 1: Fondasi Deployment, KBLI, dan Keamanan

**Files:**
- Create: `app/Console/Commands/EnsureKbliCatalog.php`
- Modify: `database/migrations/2026_07_28_000008_complete_production_catalog_and_partner_registration.php`
- Modify: `database/migrations/2026_07_28_000009_publish_complete_service_catalog.php`
- Modify: `docker/entrypoint.sh`
- Modify: `docker-compose.yml`
- Modify: `bootstrap/app.php`
- Modify: `docker/nginx.conf`
- Modify: `docker/php.ini`
- Modify: `.github/workflows/ci.yml`
- Test: `tests/Feature/KbliCatalogTest.php`

**Interfaces:**
- Consumes: `Database\Seeders\KbliSeeder` dan `database/data/kbli-2025.json`.
- Produces: perintah `php artisan kbli:ensure` yang keluar tanpa mutasi jika 1.559 kode sudah tersedia.

- [x] **Step 1: Write the failing deployment test**

```php
public function test_kbli_ensure_populates_the_complete_catalog(): void
{
    $this->artisan('kbli:ensure')->assertSuccessful();
    $this->assertSame(1559, KbliCode::where('version', '2025')->count());
}
```

- [x] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=KbliCatalogTest`
Expected: FAIL because `kbli:ensure` does not exist.

- [x] **Step 3: Implement the idempotent catalog command**

```php
protected $signature = 'kbli:ensure';

public function handle(): int
{
    if (KbliCode::where('version', '2025')->count() === 1559) {
        $this->components->info('Katalog KBLI 2025 sudah lengkap.');
        return self::SUCCESS;
    }

    $this->call('db:seed', ['--class' => KbliSeeder::class, '--force' => true]);
    return KbliCode::where('version', '2025')->count() === 1559 ? self::SUCCESS : self::FAILURE;
}
```

- [x] **Step 4: Remove `ServiceSeeder` calls from migrations and harden deployment**

Run `kbli:ensure` after migrations, persist `database/uploads`, set trusted proxies from `TRUSTED_PROXIES`, align PHP/Nginx upload limits to 25 MB, and set CI to PHP 8.4.

- [x] **Step 5: Run focused tests and commit**

Run: `php artisan test --filter=KbliCatalogTest`
Expected: PASS.

Commit: `fix: secure deployment and ensure KBLI catalog`

### Task 2: Private LMS Materials and Embedded Video

**Files:**
- Create: `app/Http/Controllers/Partner/LearningMaterialController.php`
- Create: `app/Support/VideoEmbed.php`
- Modify: `app/Http/Controllers/Admin/AcademyController.php`
- Modify: `app/Http/Controllers/Partner/LearningController.php`
- Modify: `resources/views/partner/learning/show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/PartnerAcademyTest.php`

**Interfaces:**
- Consumes: authenticated `currentUser`, `CourseEnrollment`, `Lesson::file_path`, and YouTube watch/share URLs.
- Produces: `VideoEmbed::url(?string): ?string`, route `partner.learning.material`, and private streamed responses.

- [x] **Step 1: Write failing video and authorization tests**

```php
public function test_enrolled_partner_can_stream_material_but_other_partner_cannot(): void
{
    Storage::fake('local');
    Storage::disk('local')->put('academy/materials/modul.pdf', '%PDF-test');
    $lesson->update(['file_path' => 'academy/materials/modul.pdf']);

    $this->asPartner($enrolled)->get(route('partner.learning.material', [$course, $lesson]))->assertOk();
    $this->asPartner($outsider)->get(route('partner.learning.material', [$course, $lesson]))->assertNotFound();
}

public function test_youtube_url_is_rendered_as_an_internal_embed(): void
{
    $lesson->update(['type' => 'video', 'resource_url' => 'https://youtu.be/abc123XYZ89']);
    $this->asPartner($partner)->get(route('partner.learning.show', $course))
        ->assertSee('https://www.youtube-nocookie.com/embed/abc123XYZ89', false)
        ->assertDontSee('Buka materi');
}
```

- [x] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=PartnerAcademyTest`
Expected: FAIL because the material route and embed conversion do not exist.

- [x] **Step 3: Store new PDFs privately and stream after enrollment checks**

Use `Storage::disk('local')->putFileAs(...)`. The material controller must verify the lesson belongs to the course and the user has an enrollment before returning `Storage::disk('local')->response(...)`.

- [x] **Step 4: Embed supported YouTube URLs**

`VideoEmbed::url()` accepts `youtube.com/watch?v=`, `youtu.be/`, and `youtube.com/embed/`, extracts an 11-character ID, and returns `https://www.youtube-nocookie.com/embed/{id}`.

- [x] **Step 5: Run focused tests and commit**

Run: `php artisan test --filter=PartnerAcademyTest`
Expected: PASS.

Commit: `feat: deliver LMS content inside the partner portal`

### Task 3: Dedicated LMS Certificate

**Files:**
- Create: `app/Http/Controllers/Partner/CertificateController.php`
- Create: `resources/views/partner/learning/certificate.blade.php`
- Modify: `app/Models/CourseEnrollment.php`
- Modify: `resources/views/partner/learning/show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/PartnerAcademyTest.php`

**Interfaces:**
- Consumes: completed enrollment, certificate number, brand settings, participant, and course.
- Produces: route `partner.learning.certificate` with a dedicated print document.

- [x] **Step 1: Write the failing certificate test**

```php
public function test_completed_partner_receives_a_dedicated_certificate_document(): void
{
    $enrollment->update([
        'status' => 'completed',
        'progress_percent' => 100,
        'completed_at' => now(),
        'certificate_number' => 'CERT-IH-202607-0001',
    ]);

    $this->asPartner($partner)
        ->get(route('partner.learning.certificate', $enrollment))
        ->assertOk()
        ->assertSee('Sertifikat Kelulusan')
        ->assertSee('CERT-IH-202607-0001');
}
```

- [x] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=dedicated_certificate`
Expected: FAIL because the certificate route does not exist.

- [x] **Step 3: Implement certificate ownership and completion checks**

The controller must require the session user to own the enrollment, require `completed_at` and `certificate_number`, load the course and participant, then render the dedicated view.

- [x] **Step 4: Build an A4 landscape certificate view**

Include brand logo, participant name, course name, completion date, certificate number, authorized signatory, signature, stamp, and a `Cetak / Simpan PDF` button hidden during printing.

- [x] **Step 5: Run focused tests and commit**

Run: `php artisan test --filter=PartnerAcademyTest`
Expected: PASS.

Commit: `feat: add dedicated branded LMS certificates`

### Task 4: Shared Document Branding

**Files:**
- Create: `app/Services/BrandingService.php`
- Modify: `app/Http/Controllers/Admin/BrandingController.php`
- Modify: `resources/views/admin/branding.blade.php`
- Modify: `resources/views/portal/invoices/_document.blade.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/BrandingTest.php`

**Interfaces:**
- Consumes: `SystemSetting`.
- Produces: `BrandingService::document(): array` with brand name, tagline, logo, address, phone, email, bank, signatory, signature, and stamp.

- [x] **Step 1: Write the failing branding test**

```php
public function test_admin_can_save_document_branding_and_invoice_displays_it(): void
{
    $this->asAdmin()->put(route('admin.branding.update'), [
        'brand_name' => 'IzinHukum',
        'document_address' => 'Jalan Legalitas 1',
        'document_phone' => '08123456789',
        'document_email' => 'billing@example.test',
        'bank_name' => 'BCA',
        'bank_account_number' => '1234567890',
        'bank_account_holder' => 'PT Praktisi Izin Hukum',
        'signatory_name' => 'Direktur Utama',
        'signatory_title' => 'Direktur',
    ])->assertSessionHasNoErrors();

    $this->get(route('invoices.public', $invoice->public_token))
        ->assertSee('Jalan Legalitas 1')
        ->assertSee('1234567890');
}
```

- [x] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=BrandingTest`
Expected: FAIL because document branding fields are not accepted.

- [x] **Step 3: Persist all branding fields and media**

Store text fields in `system_settings`. Store logo, signature, and stamp on the persistent public disk, deleting only the replaced prior file after the new file succeeds.

- [x] **Step 4: Use one branding payload in every document**

Inject the branding payload into invoice, receipt, and certificate views. Fall back to `config('company')` when a setting is empty.

- [x] **Step 5: Run focused tests and commit**

Run: `php artisan test --filter=BrandingTest`
Expected: PASS.

Commit: `feat: centralize branding for business documents`

### Task 5: Payments, Automatic Invoice Status, and Receipts

**Files:**
- Create: `database/migrations/2026_07_29_000011_add_payments_and_financial_categories.php`
- Create: `app/Models/Payment.php`
- Create: `app/Models/FinancialCategory.php`
- Create: `app/Services/InvoicePaymentService.php`
- Create: `app/Http/Controllers/Portal/PaymentController.php`
- Create: `app/Http/Controllers/PublicReceiptController.php`
- Create: `resources/views/receipts/show.blade.php`
- Modify: `app/Models/Invoice.php`
- Modify: `app/Models/User.php`
- Modify: `resources/views/portal/invoices/show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/PaymentReceiptTest.php`

**Interfaces:**
- Consumes: invoice total, prior payments, authenticated creator, and branding payload.
- Produces: `InvoicePaymentService::record(Invoice, User, array): Payment`, automatic `partial` or `paid` invoice status, and public receipt route protected by a 64-character token.

- [x] **Step 1: Write failing payment and receipt tests**

```php
public function test_payment_creates_a_receipt_and_updates_invoice_status(): void
{
    $this->asAdmin()->post(route('admin.invoices.payments.store', $invoice), [
        'payment_date' => '2026-07-29',
        'amount' => 1000000,
        'payment_method' => 'transfer',
    ])->assertRedirect();

    $payment = Payment::firstOrFail();
    $this->assertSame('partial', $invoice->fresh()->status);
    $this->get(route('receipts.public', $payment->public_token))
        ->assertOk()
        ->assertSee($payment->receipt_number)
        ->assertSee('Rp1.000.000');
}
```

- [x] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=PaymentReceiptTest`
Expected: FAIL because payment tables and routes do not exist.

- [x] **Step 3: Implement transactional payment recording**

Lock the invoice row, reject cancelled invoices and payments above the remaining balance, create the payment, assign `KWT/IH/YYYYMM/00001`, then set invoice status to `partial` or `paid`.

- [x] **Step 4: Add invoice payment history and dedicated receipt**

Display paid amount, remaining amount, form, receipt links, and immutable payment history. The receipt includes invoice reference, payer, amount in numbers, payment method, reference, signatory, signature, and stamp.

- [x] **Step 5: Run focused tests and commit**

Run: `php artisan test --filter=PaymentReceiptTest`
Expected: PASS.

Commit: `feat: record payments and issue branded receipts`

### Task 6: Expenses and Operational Financial Reports

**Files:**
- Create: `database/migrations/2026_07_29_000012_add_expenses.php`
- Create: `app/Models/Expense.php`
- Create: `app/Http/Controllers/Admin/FinanceController.php`
- Create: `resources/views/admin/finance/index.blade.php`
- Create: `resources/views/admin/finance/print.blade.php`
- Create: `app/Services/FinancialReportService.php`
- Modify: `resources/views/layouts/admin.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/FinancialReportTest.php`

**Interfaces:**
- Consumes: payment and expense rows in an inclusive date range.
- Produces: `FinancialReportService::forPeriod(CarbonInterface, CarbonInterface): array`, admin report, CSV export, and print report.

- [x] **Step 1: Write failing report calculation and access tests**

```php
public function test_report_uses_actual_payments_and_expenses(): void
{
    Payment::factory()->create(['payment_date' => '2026-07-10', 'amount' => 5000000]);
    Expense::factory()->create(['transaction_date' => '2026-07-12', 'amount' => 1750000]);

    $this->asAdmin()->get(route('admin.finance.index', [
        'from' => '2026-07-01',
        'to' => '2026-07-31',
    ]))->assertOk()->assertSee('Rp5.000.000')->assertSee('Rp1.750.000')->assertSee('Rp3.250.000');
}

public function test_partner_cannot_access_financial_reports(): void
{
    $this->asPartner()->get('/admin/keuangan')->assertRedirect('/admin/masuk');
}
```

- [x] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=FinancialReportTest`
Expected: FAIL because expenses and reports do not exist.

- [x] **Step 3: Implement expense and category management**

Validate category type, date, payee, description, amount, payment method, reference, and notes. Permit admin to add income or expense categories without modifying historical rows.

- [x] **Step 4: Implement report calculations and exports**

Return opening balance, payment income, expenses, net cash flow, income and expense by category, monthly trend, recent transactions, accounts receivable, CSV response, and print view.

- [x] **Step 5: Run focused tests and commit**

Run: `php artisan test --filter=FinancialReportTest`
Expected: PASS.

Commit: `feat: add operational cash flow and profit loss reporting`

### Task 7: Regression, Build, Documentation, and Packaging

**Files:**
- Modify: `README.md`
- Create: `PETUNJUK-UPGRADE-KEUANGAN-LMS-KBLI.md`
- Modify: relevant tests under `tests/Feature/`

**Interfaces:**
- Consumes: all routes and services produced by Tasks 1 through 6.
- Produces: a validated ZIP package and upgrade instructions.

- [x] **Step 1: Run the complete backend test suite**

Run: `php artisan test`
Expected: all tests PASS.

- [x] **Step 2: Run code format and frontend build**

Run: `vendor/bin/pint --test && npm ci && npm run build`
Expected: formatting PASS and Vite build succeeds.

- [x] **Step 3: Run static fallback checks when PHP runtime is unavailable**

Run a PHP parser across all `*.php` files, validate JSON, shell syntax, route names, Blade directive balance, and dataset count.

- [x] **Step 4: Write deployment instructions**

Document database backup, upload backup, image rebuild, migrations, `kbli:ensure`, private LMS storage, branding completion, payment workflow, and report reconciliation.

- [x] **Step 5: Package and commit**

Create `IZINHUKUM-OPERATIONAL-UPGRADE-V6.zip` without `node_modules`, logs, environment secrets, cache files, or generated build artifacts.

Commit: `release: prepare IzinHukum operational upgrade v6`

