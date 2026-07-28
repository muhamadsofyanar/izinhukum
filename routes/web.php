<?php

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\InquiryController as AdminInquiryController;
use App\Http\Controllers\Admin\PackageController as AdminPackageController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InquiryController;
use App\Http\Controllers\KbliController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/layanan', [ServiceController::class, 'index'])->name('services.index');
Route::get('/layanan/{service:slug}', [ServiceController::class, 'show'])->name('services.show');
Route::get('/cek-risiko-kbli', [KbliController::class, 'index'])->name('kbli.index');
Route::get('/cek-risiko-kbli/{code}', [KbliController::class, 'show'])->name('kbli.show');
Route::get('/proposal', [InquiryController::class, 'create'])->name('proposal.create');
Route::post('/proposal', [InquiryController::class, 'store'])->middleware('throttle:10,1')->name('proposal.store');
Route::get('/proposal/berhasil/{inquiry:reference}', [InquiryController::class, 'success'])->name('proposal.success');
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
        Route::post('/keluar', [AdminAuthController::class, 'destroy'])->name('logout');
    });
});
