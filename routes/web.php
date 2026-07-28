<?php

use App\Http\Controllers\Admin\ArticleController as AdminArticleController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
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
use App\Http\Controllers\PartnerApplicationController;
use App\Http\Controllers\Portal\InvoiceController as PortalInvoiceController;
use App\Http\Controllers\Portal\ProfileController as PortalProfileController;
use App\Http\Controllers\PublicInvoiceController;
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
        Route::get('/invoice', [PortalInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoice/buat', [PortalInvoiceController::class, 'create'])->name('invoices.create');
        Route::post('/invoice', [PortalInvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/invoice/{invoice}', [PortalInvoiceController::class, 'show'])->name('invoices.show');
        Route::put('/invoice/{invoice}/status', [PortalInvoiceController::class, 'updateStatus'])->name('invoices.status');
        Route::post('/invoice/{invoice}/kirim', [PortalInvoiceController::class, 'send'])->name('invoices.send');
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
        Route::get('/invoice', [PortalInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoice/buat', [PortalInvoiceController::class, 'create'])->name('invoices.create');
        Route::post('/invoice', [PortalInvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/invoice/{invoice}', [PortalInvoiceController::class, 'show'])->name('invoices.show');
        Route::put('/invoice/{invoice}/status', [PortalInvoiceController::class, 'updateStatus'])->name('invoices.status');
        Route::post('/invoice/{invoice}/kirim', [PortalInvoiceController::class, 'send'])->name('invoices.send');
        Route::get('/profil', [PortalProfileController::class, 'edit'])->name('profile.edit');
        Route::put('/profil', [PortalProfileController::class, 'update'])->name('profile.update');
        Route::post('/keluar', [PartnerAuthController::class, 'destroy'])->name('logout');
    });
});
