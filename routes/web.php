<?php

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\BrandingController as AdminBrandingController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\FinanceController as AdminFinanceController;
use App\Http\Controllers\Admin\PackageController as AdminPackageController;
use App\Http\Controllers\Admin\ServiceLandingController as AdminServiceLandingController;
use App\Http\Controllers\Admin\ServiceOrderController as AdminServiceOrderController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SimpleCheckoutController as InquiryController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\Portal\InvoiceController as PortalInvoiceController;
use App\Http\Controllers\Portal\PaymentController as PortalPaymentController;
use App\Http\Controllers\Portal\ProfileController as PortalProfileController;
use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\PublicReceiptController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/healthz', HealthController::class)->name('healthz');
Route::group(config('simple.public_host') ? ['domain'=>config('simple.public_host')] : [], function (): void {
Route::get('/', HomeController::class)->name('home');
Route::get('/layanan', [ServiceController::class, 'index'])->name('services.index');
Route::get('/layanan/{service:slug}', [ServiceController::class, 'show'])->name('services.show');
Route::get('/checkout', [InquiryController::class, 'create'])->name('proposal.create');
Route::post('/checkout', [InquiryController::class, 'store'])->middleware('throttle:5,1')->name('proposal.store');
Route::get('/checkout/berhasil/{inquiry:reference}', [InquiryController::class, 'success'])->name('proposal.success');
Route::get('/proposal', fn () => redirect()->route('proposal.create'))->name('legacy.proposal');
Route::get('/tagihan/{token}', [PublicInvoiceController::class, 'show'])->middleware('throttle:60,1')->name('invoices.public');
Route::get('/kwitansi/{token}', [PublicReceiptController::class, 'show'])->middleware('throttle:60,1')->name('receipts.public');
Route::get('/kontak', ContactController::class)->name('contact');
Route::get('/kebijakan-privasi', [LegalPageController::class, 'privacy'])->name('legal.privacy');
Route::get('/syarat-ketentuan', [LegalPageController::class, 'terms'])->name('legal.terms');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

});

Route::group(config('simple.admin_host') ? ['domain'=>config('simple.admin_host')] : [], function (): void {
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/masuk', [AdminAuthController::class, 'create'])->name('login');
    Route::post('/masuk', [AdminAuthController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
    Route::middleware('admin')->group(function (): void {
        Route::get('/', AdminDashboardController::class)->name('dashboard');
        Route::get('/order', [AdminServiceOrderController::class, 'index'])->name('orders.index');
        Route::get('/order/buat', [AdminServiceOrderController::class, 'create'])->name('orders.create');
        Route::post('/order', [AdminServiceOrderController::class, 'store'])->name('orders.store');
        Route::get('/order/{order}', [AdminServiceOrderController::class, 'show'])->name('orders.show');
        Route::put('/order/{order}', [AdminServiceOrderController::class, 'update'])->name('orders.update');
        Route::post('/order/{order}/invoice', [AdminServiceOrderController::class, 'attachInvoice'])->name('orders.invoices.attach');
        Route::post('/order/{order}/dokumen', [AdminServiceOrderController::class, 'uploadDocument'])->name('orders.documents.store');
        Route::get('/order/{order}/dokumen/{document}', [AdminServiceOrderController::class, 'downloadDocument'])->name('orders.documents.download');
        Route::delete('/order/{order}/dokumen/{document}', [AdminServiceOrderController::class, 'deleteDocument'])->name('orders.documents.destroy');
        Route::get('/paket', [AdminPackageController::class, 'index'])->name('packages.index');
        Route::put('/paket/{package}', [AdminPackageController::class, 'update'])->name('packages.update');
        Route::get('/landing-layanan', [AdminServiceLandingController::class, 'index'])->name('service-landings.index');
        Route::put('/landing-layanan/{service}', [AdminServiceLandingController::class, 'update'])->name('service-landings.update');
        Route::get('/invoice', [PortalInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoice/buat', [PortalInvoiceController::class, 'create'])->name('invoices.create');
        Route::post('/invoice', [PortalInvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/invoice/{invoice}/ubah', [PortalInvoiceController::class, 'edit'])->name('invoices.edit');
        Route::put('/invoice/{invoice}', [PortalInvoiceController::class, 'update'])->name('invoices.update');
        Route::delete('/invoice/{invoice}', [PortalInvoiceController::class, 'destroy'])->name('invoices.destroy');
        Route::get('/invoice/{invoice}', [PortalInvoiceController::class, 'show'])->name('invoices.show');
        Route::post('/invoice/{invoice}/pembayaran', [PortalPaymentController::class, 'store'])->name('invoices.payments.store');
        Route::put('/invoice/{invoice}/status', [PortalInvoiceController::class, 'updateStatus'])->name('invoices.status');
        Route::post('/invoice/{invoice}/batalkan', [PortalInvoiceController::class, 'cancel'])->name('invoices.cancel');
        Route::get('/pembayaran/{payment}/ubah', [PortalPaymentController::class, 'edit'])->name('payments.edit');
        Route::put('/pembayaran/{payment}', [PortalPaymentController::class, 'update'])->name('payments.update');
        Route::post('/pembayaran/{payment}/batalkan', [PortalPaymentController::class, 'cancel'])->name('payments.cancel');
        Route::get('/keuangan', [AdminFinanceController::class, 'index'])->name('finance.index');
        Route::post('/keuangan/kategori', [AdminFinanceController::class, 'storeCategory'])->name('finance.categories.store');
        Route::post('/keuangan/pemasukan', [AdminFinanceController::class, 'storeIncome'])->name('finance.incomes.store');
        Route::post('/keuangan/pengeluaran', [AdminFinanceController::class, 'storeExpense'])->name('finance.expenses.store');
        Route::get('/keuangan/ekspor.csv', [AdminFinanceController::class, 'export'])->name('finance.export');
        Route::get('/keuangan/cetak', [AdminFinanceController::class, 'print'])->name('finance.print');
        Route::get('/profil', [PortalProfileController::class, 'edit'])->name('profile.edit');
        Route::put('/profil', [PortalProfileController::class, 'update'])->name('profile.update');
        Route::get('/branding', [AdminBrandingController::class, 'edit'])->name('branding.edit');
        Route::put('/branding', [AdminBrandingController::class, 'update'])->name('branding.update');
        Route::post('/keluar', [AdminAuthController::class, 'destroy'])->name('logout');
    });
});

});
