# Finance and Partner Referral Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menghubungkan invoice lama, pembayaran, laporan keuangan, paket mitra, referral, dan komisi otomatis.

**Architecture:** Tambahkan migrasi kompatibel dengan data V7, layanan khusus rekonsiliasi dan komisi, middleware referral, serta hubungan model yang terpisah antara penerima invoice dan mitra pemasaran. Harga serta persentase komisi disimpan dalam konfigurasi agar dapat diubah tanpa migrasi.

**Tech Stack:** PHP 8.4, Laravel 12, SQLite, Blade, PHPUnit 11.

## Global Constraints

- Data invoice dan kwitansi lama tidak boleh dihapus.
- Nilai internal paket lama tetap `starter`, `professional`, dan `priority`.
- Rekonsiliasi dan pembuatan komisi wajib idempoten.
- Atribusi berlaku 30 hari dan hanya menerima mitra aktif.
- Laporan keuangan hanya menghitung pembayaran berstatus `active`.

---

### Task 1: Skema integrasi

**Files:**
- Create: `database/migrations/2026_07_29_000014_integrate_partner_referrals.php`
- Create: `config/partner.php`
- Modify: model terkait pada `app/Models`

**Interfaces:**
- Produces: `partner_referrals`, `referred_by_partner_id`, `source_key`, dan `payment_id`.

- [ ] Tambahkan pengujian skema melalui feature test.
- [ ] Jalankan pengujian dan pastikan gagal karena kolom belum tersedia.
- [ ] Buat migrasi serta relasi model.
- [ ] Jalankan pengujian dan pastikan lulus.

### Task 2: Rekonsiliasi invoice lama

**Files:**
- Create: `app/Services/LegacyPaidInvoiceReconciler.php`
- Create: `app/Console/Commands/ReconcileLegacyPaidInvoices.php`
- Modify: `docker/entrypoint.sh`
- Test: `tests/Feature/LegacyPaidInvoiceReconciliationTest.php`

**Interfaces:**
- Produces: `LegacyPaidInvoiceReconciler::run(): int`
- Consumes: invoice `paid` tanpa pembayaran.

- [ ] Tulis tes `paid_at`, fallback `updated_at`, dan idempotensi.
- [ ] Pastikan tes gagal sebelum layanan dibuat.
- [ ] Buat layanan dan perintah `finance:reconcile-legacy-paid-invoices`.
- [ ] Jalankan perintah setelah migrasi pada startup.
- [ ] Pastikan pembayaran migrasi masuk laporan.

### Task 3: Paket mitra publik

**Files:**
- Modify: `app/Http/Controllers/PartnerApplicationController.php`
- Modify: `app/Http/Controllers/Admin/PartnerController.php`
- Modify: `resources/views/partnership.blade.php`
- Modify: `resources/views/admin/partners.blade.php`
- Test: `tests/Feature/PartnerPlanTest.php`

**Interfaces:**
- Consumes: `config('partner.plans')`.
- Produces: `desired_partner_level` dan level akun saat disetujui.

- [ ] Tulis tes tiga paket, nominal, dan penyimpanan pilihan.
- [ ] Tambahkan validasi level berbasis kunci konfigurasi.
- [ ] Tampilkan kartu dan pilihan paket.
- [ ] Pastikan persetujuan admin meneruskan level yang dipilih.

### Task 4: Atribusi referral

**Files:**
- Create: `app/Http/Middleware/CapturePartnerReferral.php`
- Create: `app/Services/PartnerReferralService.php`
- Create: `app/Models/PartnerReferral.php`
- Modify: `bootstrap/app.php`
- Modify: `app/Http/Controllers/InquiryController.php`
- Modify: `resources/views/admin/inquiries.blade.php`
- Test: `tests/Feature/PartnerReferralTest.php`

**Interfaces:**
- Produces: `PartnerReferralService::attribution(Request): array`.
- Consumes: parameter `ref` dan cookie 30 hari.

- [ ] Tulis tes kode valid, kode tidak valid, dan penyimpanan inquiry.
- [ ] Tambahkan middleware web.
- [ ] Simpan kunjungan serta atribusi.
- [ ] Tampilkan sumber mitra pada panel admin.

### Task 5: Proposal ke invoice

**Files:**
- Modify: `app/Http/Controllers/Portal/InvoiceController.php`
- Modify: `resources/views/portal/invoices/create.blade.php`
- Modify: `resources/views/portal/invoices/show.blade.php`
- Modify: `resources/views/admin/inquiries.blade.php`
- Test: `tests/Feature/PartnerReferralTest.php`

**Interfaces:**
- Consumes: query `inquiry` dan `referred_by_partner_id`.
- Produces: invoice yang menyimpan inquiry dan mitra referral.

- [ ] Tambahkan tes pembuatan invoice dari proposal.
- [ ] Isi data penerima dan paket dari proposal.
- [ ] Simpan atribusi pada invoice.
- [ ] Tampilkan atribusi hanya pada portal yang berwenang.

### Task 6: Komisi otomatis

**Files:**
- Create: `app/Services/CommissionService.php`
- Modify: `app/Services/InvoicePaymentService.php`
- Modify: `app/Http/Controllers/OperationsController.php`
- Modify: `resources/views/portal/operations.blade.php`
- Test: `tests/Feature/PartnerCommissionTest.php`

**Interfaces:**
- Produces: `CommissionService::syncForPayment(Payment): ?Commission`.
- Produces: `CommissionService::cancelForPayment(Payment): ?Commission`.

- [ ] Tulis tes pembuatan, koreksi, pembatalan, dan idempotensi komisi.
- [ ] Sinkronkan komisi dalam transaksi pembayaran.
- [ ] Tambahkan status `adjustment_required`.
- [ ] Tampilkan sumber, tarif, invoice, dan kwitansi.

### Task 7: Dashboard dan validasi akhir

**Files:**
- Modify: `app/Http/Controllers/Partner/DashboardController.php`
- Modify: `resources/views/partner/dashboard.blade.php`
- Modify: `README.md`

**Interfaces:**
- Produces: statistik klik, prospek, invoice, omzet, dan komisi.

- [ ] Tambahkan tes statistik dashboard.
- [ ] Tampilkan tautan referral siap salin.
- [ ] Jalankan seluruh test suite.
- [ ] Bangun aset produksi.
- [ ] Periksa ZIP agar tidak memuat `.env`, database produksi, vendor, atau `node_modules`.

