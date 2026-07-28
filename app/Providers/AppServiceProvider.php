<?php

namespace App\Providers;

use App\Models\Service;
use App\Models\SystemSetting;
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
                // The layout still renders during first deployment before migration.
            }

            $view->with('navServices', $services);
        });

        View::composer(['layouts.app', 'layouts.admin'], function ($view): void {
            try {
                $view->with([
                    'platformBrandName' => Schema::hasTable('system_settings') ? SystemSetting::valueFor('brand_name', 'IzinHukum') : 'IzinHukum',
                    'platformBrandTagline' => Schema::hasTable('system_settings') ? SystemSetting::valueFor('brand_tagline', 'Legalitas sampai tuntas') : 'Legalitas sampai tuntas',
                    'platformBrandLogo' => Schema::hasTable('system_settings') ? SystemSetting::valueFor('brand_logo') : null,
                ]);
            } catch (\Throwable) {
                $view->with(['platformBrandName' => 'IzinHukum', 'platformBrandTagline' => 'Legalitas sampai tuntas', 'platformBrandLogo' => null]);
            }
        });
    }
}
