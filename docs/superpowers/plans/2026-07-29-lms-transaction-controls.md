# LMS and Safe Transaction Controls Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menyajikan LMS dengan satu materi aktif serta menyediakan edit, hapus, dan pembatalan transaksi yang menjaga audit dan laporan keuangan.

**Architecture:** Controller LMS memilih satu materi dari koleksi kelas dan Blade merender navigasi dua panel. Invoice draf memakai form yang sama untuk create dan edit. Pembayaran menggunakan status aktif atau dibatalkan, sedangkan service transaksi menghitung ulang status invoice dan menulis audit log.

**Tech Stack:** PHP 8.4, Laravel 12, Blade, MySQL/MariaDB, Vite, Bootstrap, SCSS, PHPUnit 11.

## Global Constraints

- Invoice draf tanpa pembayaran dapat diedit dan dihapus permanen.
- Invoice terkirim hanya dapat dibatalkan dengan alasan.
- Kwitansi tidak boleh dihapus permanen.
- Pembayaran dibatalkan tidak boleh masuk laporan keuangan.
- Mitra hanya dapat memutasi invoice yang dibuatnya.
- Materi LMS privat tetap melalui rute berotorisasi.
- Source paket tidak memiliki metadata Git. Commit dilakukan setelah paket diterapkan pada repository pemilik.

---

### Task 1: Status Pembatalan dan Audit

**Files:**
- Create: `database/migrations/2026_07_29_000013_add_transaction_audit_fields.php`
- Modify: `app/Models/Invoice.php`
- Modify: `app/Models/Payment.php`
- Test: `tests/Feature/PaymentReceiptTest.php`

**Interfaces:**
- Produces: `Payment::scopeActive(Builder $query)`, metadata pembatalan invoice, dan metadata pembatalan atau koreksi pembayaran.

- [x] **Step 1: Write the failing model test**

```php
$payment->update(['status' => 'cancelled']);
$this->assertSame(0, $invoice->fresh()->amountPaid());
```

- [ ] **Step 2: Run the focused test**

Run: `php artisan test --filter=PaymentReceiptTest`

Expected: FAIL because `payments.status` and active filtering do not exist.

- [x] **Step 3: Add the migration**

```php
Schema::table('invoices', function (Blueprint $table): void {
    $table->timestamp('cancelled_at')->nullable();
    $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
    $table->text('cancellation_reason')->nullable();
});

Schema::table('payments', function (Blueprint $table): void {
    $table->string('status', 24)->default('active')->index();
    $table->timestamp('cancelled_at')->nullable();
    $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
    $table->text('cancellation_reason')->nullable();
    $table->timestamp('last_edited_at')->nullable();
    $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
});
```

- [x] **Step 4: Add casts, fillable fields, and active totals**

`Invoice::amountPaid()` must sum only payments whose status is `active`. `Payment::scopeActive()` must add `where('status', 'active')`.

- [ ] **Step 5: Run the focused test**

Run: `php artisan test --filter=PaymentReceiptTest`

Expected: PASS.

### Task 2: Focused LMS Reader

**Files:**
- Modify: `app/Http/Controllers/Partner/LearningController.php`
- Modify: `resources/views/partner/learning/show.blade.php`
- Modify: `resources/css/app.scss`
- Test: `tests/Feature/PartnerAcademyTest.php`

**Interfaces:**
- Produces: view data `lessons`, `activeLesson`, `previousLesson`, `nextLesson`, and `activeVideoEmbed`.

- [x] **Step 1: Write the failing active-material test**

```php
$this->withSession(['portal_user_id' => $partner->id])
    ->get(route('partner.learning.show', ['course' => $course, 'materi' => $second->id]))
    ->assertSee($second->title)
    ->assertDontSee($first->content);
```

- [x] **Step 2: Select a validated active lesson**

Flatten lessons in course order. Select the requested lesson, otherwise the first incomplete lesson, otherwise the first lesson. Return 404 when the requested ID is not part of the course.

- [x] **Step 3: Render the two-panel reader**

The sidebar lists sections and lessons. The main panel renders only `activeLesson`, keeps embedded YouTube and private PDF routes, and displays previous, next, and completion actions.

- [x] **Step 4: Redirect completion to the next lesson**

```php
return redirect()->route('partner.learning.show', [
    'course' => $course,
    'materi' => $nextLesson?->id ?? $lesson->id,
]);
```

- [ ] **Step 5: Run LMS tests**

Run: `php artisan test --filter=PartnerAcademyTest`

Expected: PASS.

### Task 3: Draft Invoice Editing and Safe Cancellation

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Portal/InvoiceController.php`
- Modify: `resources/views/portal/invoices/create.blade.php`
- Modify: `resources/views/portal/invoices/show.blade.php`
- Modify: `resources/views/portal/invoices/index.blade.php`
- Test: `tests/Feature/PartnerInvoiceTest.php`

**Interfaces:**
- Produces: routes `invoices.edit`, `invoices.update`, `invoices.destroy`, and `invoices.cancel`.

- [x] **Step 1: Write failing lifecycle tests**

Create tests proving a draft can be edited and deleted, a sent invoice cannot be edited or deleted, and cancellation requires a reason.

- [x] **Step 2: Reuse invoice preparation for create and update**

Add `prepareInvoice(Request $request, User $user): array`. Both store and update must use identical recipient, package, price floor, quantity, and date validation.

- [x] **Step 3: Enforce lifecycle rules**

`edit`, `update`, and `destroy` require `status === 'draft'` and no payments. `cancel` requires `status === 'sent'` and no active payments.

- [x] **Step 4: Record audit entries**

```php
AuditLog::create([
    'user_id' => $user->id,
    'action' => 'invoice.cancelled',
    'subject_type' => Invoice::class,
    'subject_id' => $invoice->id,
    'metadata' => ['reason' => $reason, 'invoice_number' => $invoice->invoice_number],
    'ip_address' => $request->ip(),
]);
```

- [ ] **Step 5: Run invoice tests**

Run: `php artisan test --filter=PartnerInvoiceTest`

Expected: PASS.

### Task 4: Kwitansi Correction and Cancellation

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Portal/PaymentController.php`
- Modify: `app/Services/InvoicePaymentService.php`
- Create: `resources/views/portal/payments/edit.blade.php`
- Modify: `resources/views/portal/invoices/show.blade.php`
- Modify: `resources/views/receipts/show.blade.php`
- Modify: `app/Http/Controllers/PublicReceiptController.php`
- Test: `tests/Feature/PaymentReceiptTest.php`

**Interfaces:**
- Produces: admin routes `payments.edit`, `payments.update`, and `payments.cancel`; service methods `update`, `cancel`, and `recalculateInvoice`.

- [x] **Step 1: Write failing correction and cancellation tests**

Tests must assert that edit requires a reason, overpayment is rejected, cancellation leaves the payment row intact, invoice status is recalculated, and an audit row exists.

- [x] **Step 2: Implement transactional correction**

Lock the payment and linked invoice. Validate the replacement amount against other active payments. Store before and after values in `AuditLog`.

- [x] **Step 3: Implement transactional cancellation**

Set payment status and cancellation metadata. Do not delete the row. Recalculate the linked invoice from active payments.

- [x] **Step 4: Add management UI and cancelled watermark**

Active kwitansi gets an admin edit link. A cancelled public kwitansi displays `DIBATALKAN`, reason, date, and no longer implies an active receipt.

- [ ] **Step 5: Run payment tests**

Run: `php artisan test --filter=PaymentReceiptTest`

Expected: PASS.

### Task 5: Financial Exclusion and Package Verification

**Files:**
- Modify: `app/Services/FinancialReportService.php`
- Modify: `tests/Feature/FinancialReportTest.php`
- Modify: `PETUNJUK-UPGRADE-KEUANGAN-LMS-KBLI.md`

**Interfaces:**
- Consumes: `Payment::active()`.
- Produces: cash reports that exclude cancelled receipts.

- [x] **Step 1: Add the failing report assertion**

```php
Payment::firstOrFail()->update(['status' => 'cancelled']);
$report = app(FinancialReportService::class)->forPeriod($from, $to);
$this->assertSame(0, $report['income']);
```

- [x] **Step 2: Filter every report payment query**

Apply `->active()` to period income and opening balance queries. Receivables must use active payment sums.

- [ ] **Step 3: Run source and frontend checks**

Run:

```bash
npm run build
npm audit --omit=dev
php artisan test
vendor/bin/pint --test
```

Expected: Vite build succeeds, npm reports no production vulnerability, PHPUnit passes, and Pint reports no style violations.

- [x] **Step 4: Build and verify the archive**

Exclude `.env`, `vendor`, `node_modules`, `.npm-cache`, build output, and Git metadata. Run CRC and SHA-256 checks on the final ZIP.

## Verification Status

Implementation steps are complete. The clean `npm ci` and Vite build pass with 113 transformed modules. The npm production audit reports zero vulnerabilities. Static parsing passes for 107 PHP files, 52 Blade files, and 108 routed controller handlers.

The four `php artisan test` steps and `vendor/bin/pint --test` remain unchecked because the packaging environment does not provide PHP or Composer. Run both commands on PHP 8.4 before production promotion.
