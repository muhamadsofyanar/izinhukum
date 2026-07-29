<?php

use App\Http\Controllers\Admin\ArticleController as AdminArticleController;
use App\Http\Controllers\Admin\BrandingController as AdminBrandingController;
use App\Http\Controllers\Admin\AcademyController as AdminAcademyController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\FinanceController as AdminFinanceController;
use App\Http\Controllers\Admin\InquiryController as AdminInquiryController;
use App\Http\Controllers\Admin\MailSettingController as AdminMailSettingController;
use App\Http\Controllers\Admin\PackageController as AdminPackageController;
use App\Http\Controllers\Admin\PartnerController as AdminPartnerController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InquiryController;
use App\Http\Controllers\InquiryTrackingController;
use App\Http\Controllers\KbliController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\Partner\ActivationController as PartnerActivationController;
use App\Http\Controllers\Partner\AuthController as PartnerAuthController;
use App\Http\Controllers\Partner\DashboardController as PartnerDashboardController;
use App\Http\Controllers\Partner\PriceController as PartnerPriceController;
use App\Http\Controllers\Partner\LearningController as PartnerLearningController;
use App\Http\Controllers\Partner\LearningMaterialController as PartnerLearningMaterialController;
use App\Http\Controllers\Partner\CertificateController as PartnerCertificateController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\PartnerApplicationController;
use App\Http\Controllers\Portal\InvoiceController as PortalInvoiceController;
use App\Http\Controllers\Portal\ProfileController as PortalProfileController;
use App\Http\Controllers\Portal\CommunityController;
use App\Http\Controllers\Portal\CommunityAttachmentController;
use App\Http\Controllers\Portal\InboxController;
use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\PublicReceiptController;
use App\Http\Controllers\Portal\PaymentController as PortalPaymentController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/layanan', [ServiceController::class, 'index'])->name('services.index');
Route::get('/layanan/{service:slug}', [ServiceController::class, 'show'])->name('services.show');
Route::get('/cek-risiko-kbli', [KbliController::class, 'index'])->name('kbli.index');
Route::get('/cek-risiko-kbli/{code}', [KbliController::class, 'show'])->name('kbli.show');
Route::get('/artikel', [ArticleController::class, 'index'])->name('articles.index');
Route::get('/artikel/{article:slug}', [ArticleController::class, 'show'])->name('articles.show');
Route::get('/proposal', [InquiryController::class, 'create'])->name('proposal.create');
Route::post('/proposal', [InquiryController::class, 'store'])->middleware('throttle:10,1')->name('proposal.store');
Route::get('/proposal/berhasil/{inquiry:reference}', [InquiryController::class, 'success'])->name('proposal.success');
Route::get('/lacak-permintaan', [InquiryTrackingController::class, 'index'])->name('tracking.index');
Route::get('/kemitraan', [PartnerApplicationController::class, 'create'])->name('partnership.create');
Route::post('/kemitraan', [PartnerApplicationController::class, 'store'])->middleware('throttle:5,1')->name('partnership.store');
Route::get('/tagihan/{token}', [PublicInvoiceController::class, 'show'])->name('invoices.public');
Route::get('/kwitansi/{token}', [PublicReceiptController::class, 'show'])->name('receipts.public');
Route::get('/kebijakan-privasi', [LegalPageController::class, 'privacy'])->name('legal.privacy');
Route::get('/syarat-ketentuan', [LegalPageController::class, 'terms'])->name('legal.terms');
Route::get('/kontak', ContactController::class)->name('contact');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/masuk', [AdminAuthController::class, 'create'])->name('login');
    Route::post('/masuk', [AdminAuthController::class, 'store'])->middleware('throttle:5,1')->name('login.store');

    Route::middleware('admin')->group(function (): void {
        Route::get('/', AdminDashboardController::class)->name('dashboard');
        Route::get('/paket', [AdminPackageController::class, 'index'])->name('packages.index');
        Route::put('/paket/{package}', [AdminPackageController::class, 'update'])->name('packages.update');
        Route::get('/permintaan', [AdminInquiryController::class, 'index'])->name('inquiries.index');
        Route::put('/permintaan/{inquiry}', [AdminInquiryController::class, 'update'])->name('inquiries.update');
        Route::get('/mitra', [AdminPartnerController::class, 'index'])->name('partners.index');
        Route::post('/mitra', [AdminPartnerController::class, 'store'])->name('partners.store');
        Route::post('/mitra/permohonan/{application}/setujui', [AdminPartnerController::class, 'approve'])->name('partners.approve');
        Route::post('/mitra/permohonan/{application}/tolak', [AdminPartnerController::class, 'reject'])->name('partners.reject');
        Route::put('/mitra/{partner}/status', [AdminPartnerController::class, 'toggle'])->name('partners.toggle');
        Route::put('/mitra/{partner}', [AdminPartnerController::class, 'update'])->name('partners.update');
        Route::put('/mitra/{partner}/password', [AdminPartnerController::class, 'updatePassword'])->name('partners.password');
        Route::get('/akademi', [AdminAcademyController::class, 'index'])->name('academy.index');
        Route::get('/akademi/laporan', [AdminAcademyController::class, 'report'])->name('academy.report');
        Route::get('/akademi/buat', [AdminAcademyController::class, 'create'])->name('academy.create');
        Route::post('/akademi', [AdminAcademyController::class, 'store'])->name('academy.store');
        Route::get('/akademi/{course}/ubah', [AdminAcademyController::class, 'edit'])->name('academy.edit');
        Route::put('/akademi/{course}', [AdminAcademyController::class, 'update'])->name('academy.update');
        Route::delete('/akademi/{course}', [AdminAcademyController::class, 'destroy'])->name('academy.destroy');
        Route::post('/akademi-kategori', [AdminAcademyController::class, 'storeCategory'])->name('academy.categories.store');
        Route::post('/akademi/{course}/bab', [AdminAcademyController::class, 'storeSection'])->name('academy.sections.store');
        Route::post('/akademi/bab/{section}/materi', [AdminAcademyController::class, 'storeLesson'])->name('academy.lessons.store');
        Route::delete('/akademi/materi/{lesson}', [AdminAcademyController::class, 'destroyLesson'])->name('academy.lessons.destroy');
        Route::post('/akademi/{course}/peserta', [AdminAcademyController::class, 'assign'])->name('academy.assign');
        Route::get('/operasional/{module}', [OperationsController::class, 'index'])->name('operations.index');
        Route::post('/operasional/{module}', [OperationsController::class, 'store'])->name('operations.store');
        Route::put('/tiket/{ticket}', [OperationsController::class, 'updateTicket'])->name('tickets.update');
        Route::put('/komisi/{commission}', [OperationsController::class, 'updateCommission'])->name('commissions.update');
        Route::get('/invoice', [PortalInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoice/buat', [PortalInvoiceController::class, 'create'])->name('invoices.create');
        Route::post('/invoice', [PortalInvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/invoice/{invoice}', [PortalInvoiceController::class, 'show'])->name('invoices.show');
        Route::post('/invoice/{invoice}/pembayaran', [PortalPaymentController::class, 'store'])->name('invoices.payments.store');
        Route::put('/invoice/{invoice}/status', [PortalInvoiceController::class, 'updateStatus'])->name('invoices.status');
        Route::post('/invoice/{invoice}/kirim', [PortalInvoiceController::class, 'send'])->name('invoices.send');
        Route::get('/keuangan', [AdminFinanceController::class, 'index'])->name('finance.index');
        Route::post('/keuangan/kategori', [AdminFinanceController::class, 'storeCategory'])->name('finance.categories.store');
        Route::post('/keuangan/pemasukan', [AdminFinanceController::class, 'storeIncome'])->name('finance.incomes.store');
        Route::post('/keuangan/pengeluaran', [AdminFinanceController::class, 'storeExpense'])->name('finance.expenses.store');
        Route::get('/keuangan/ekspor.csv', [AdminFinanceController::class, 'export'])->name('finance.export');
        Route::get('/keuangan/cetak', [AdminFinanceController::class, 'print'])->name('finance.print');
        Route::resource('/artikel', AdminArticleController::class)
            ->parameters(['artikel' => 'article'])
            ->except('show')
            ->names([
                'index' => 'articles.index',
                'create' => 'articles.create',
                'store' => 'articles.store',
                'edit' => 'articles.edit',
                'update' => 'articles.update',
                'destroy' => 'articles.destroy',
            ]);
        Route::get('/pengaturan-email', [AdminMailSettingController::class, 'edit'])->name('mail.edit');
        Route::put('/pengaturan-email', [AdminMailSettingController::class, 'update'])->name('mail.update');
        Route::post('/pengaturan-email/tes', [AdminMailSettingController::class, 'test'])->name('mail.test');
        Route::post('/pengaturan-email/sender', [AdminMailSettingController::class, 'storeSender'])->name('mail.senders.store');
        Route::put('/pengaturan-email/sender/{sender}', [AdminMailSettingController::class, 'updateSender'])->name('mail.senders.update');
        Route::get('/profil', [PortalProfileController::class, 'edit'])->name('profile.edit');
        Route::put('/profil', [PortalProfileController::class, 'update'])->name('profile.update');
        Route::get('/komunitas', [CommunityController::class, 'index'])->name('community.index');
        Route::post('/komunitas', [CommunityController::class, 'store'])->name('community.store');
        Route::get('/komunitas/{post}/lampiran', CommunityAttachmentController::class)->name('community.attachment');
        Route::post('/komunitas/{post}/komentar', [CommunityController::class, 'comment'])->name('community.comment');
        Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
        Route::post('/inbox', [InboxController::class, 'store'])->name('inbox.store');
        Route::get('/branding', [AdminBrandingController::class, 'edit'])->name('branding.edit');
        Route::put('/branding', [AdminBrandingController::class, 'update'])->name('branding.update');
        Route::post('/keluar', [AdminAuthController::class, 'destroy'])->name('logout');
    });
});

Route::prefix('mitra')->name('partner.')->group(function (): void {
    Route::get('/masuk', [PartnerAuthController::class, 'create'])->name('login');
    Route::post('/masuk', [PartnerAuthController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
    Route::get('/aktivasi/{token}', [PartnerActivationController::class, 'create'])->name('activate');
    Route::post('/aktivasi/{token}', [PartnerActivationController::class, 'store'])->name('activate.store');

    Route::middleware('partner')->group(function (): void {
        Route::get('/', PartnerDashboardController::class)->name('dashboard');
        Route::get('/harga', [PartnerPriceController::class, 'index'])->name('prices.index');
        Route::get('/akademi', [PartnerLearningController::class, 'index'])->name('learning.index');
        Route::get('/akademi/{course}', [PartnerLearningController::class, 'show'])->name('learning.show');
        Route::get('/akademi/{course}/materi/{lesson}/file', PartnerLearningMaterialController::class)->name('learning.material');
        Route::post('/akademi/{course}/materi/{lesson}/selesai', [PartnerLearningController::class, 'complete'])->name('learning.complete');
        Route::get('/sertifikat/{enrollment}', PartnerCertificateController::class)->name('learning.certificate');
        Route::get('/operasional/{module}', [OperationsController::class, 'index'])->name('operations.index');
        Route::post('/operasional/{module}', [OperationsController::class, 'store'])->name('operations.store');
        Route::get('/invoice', [PortalInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoice/buat', [PortalInvoiceController::class, 'create'])->name('invoices.create');
        Route::post('/invoice', [PortalInvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/invoice/{invoice}', [PortalInvoiceController::class, 'show'])->name('invoices.show');
        Route::put('/invoice/{invoice}/status', [PortalInvoiceController::class, 'updateStatus'])->name('invoices.status');
        Route::post('/invoice/{invoice}/kirim', [PortalInvoiceController::class, 'send'])->name('invoices.send');
        Route::get('/profil', [PortalProfileController::class, 'edit'])->name('profile.edit');
        Route::put('/profil', [PortalProfileController::class, 'update'])->name('profile.update');
        Route::get('/komunitas', [CommunityController::class, 'index'])->name('community.index');
        Route::post('/komunitas', [CommunityController::class, 'store'])->name('community.store');
        Route::get('/komunitas/{post}/lampiran', CommunityAttachmentController::class)->name('community.attachment');
        Route::post('/komunitas/{post}/komentar', [CommunityController::class, 'comment'])->name('community.comment');
        Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
        Route::post('/inbox', [InboxController::class, 'store'])->name('inbox.store');
        Route::post('/keluar', [PartnerAuthController::class, 'destroy'])->name('logout');
    });
});
