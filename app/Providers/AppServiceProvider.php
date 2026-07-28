<?php

namespace App\Providers;

use App\Models\Service;
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
    }
}
