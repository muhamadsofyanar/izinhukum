<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Services\FeatureFlagService;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(FeatureFlagService $features): View
    {
        $services = Service::query()
            ->with(['packages' => fn ($query) => $query->where('is_active', true)])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return view('home', [
            'services' => $services,
            'featuredServices' => $services->where('is_featured', true)->take(6),
            'packages' => $services->flatMap->packages->sortByDesc('is_popular')->values(),
            'proposalEnabled' => $features->enabled('public_proposal'),
        ]);
    }
}
