<?php

namespace App\Providers;

use App\Models\Commission;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\SystemSetting;
use App\Observers\CommissionObserver;
use App\Observers\InvoiceObserver;
use App\Observers\PaymentObserver;
use App\Observers\ServiceOrderObserver;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        ServiceOrder::observe(ServiceOrderObserver::class);
        Invoice::observe(InvoiceObserver::class);
        Payment::observe(PaymentObserver::class);
        Commission::observe(CommissionObserver::class);

        View::composer('layouts.app', function ($view): void {
            $services = collect();
            try {
                if (Schema::hasTable('services')) {
                    $services = Service::query()
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->get()
                        ->groupBy('category');
                }
            } catch (\Throwable) {
                // Layout tetap dapat dirender saat deployment pertama sebelum migrasi selesai.
            }
            $view->with('navServices', $services);
        });

        View::composer(['layouts.app', 'layouts.admin'], function ($view): void {
            try {
                $view->with([
                    'platformBrandName' => Schema::hasTable('system_settings') ? SystemSetting::valueFor('brand_name', 'IzinHukum') : 'IzinHukum',
                    'platformBrandTagline' => Schema::hasTable('system_settings') ? SystemSetting::valueFor('brand_tagline', 'Jalur Pasti, Usaha Aman') : 'Jalur Pasti, Usaha Aman',
                    'platformBrandLogo' => Schema::hasTable('system_settings') ? SystemSetting::valueFor('brand_logo') : null,
                ]);
            } catch (\Throwable) {
                $view->with(['platformBrandName' => 'IzinHukum', 'platformBrandTagline' => 'Jalur Pasti, Usaha Aman', 'platformBrandLogo' => null]);
            }
        });
    }
}
