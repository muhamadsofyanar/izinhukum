<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Services\FeatureFlagService;
use App\Services\ServiceLandingContentService;
use Illuminate\View\View;

class ServiceController extends Controller
{
    public function index(): View
    {
        $services = Service::query()
            ->with(['packages' => fn ($query) => $query->where('is_active', true)])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('category');

        return view('services.index', compact('services'));
    }

    public function show(
        Service $service,
        ServiceLandingContentService $landing,
        FeatureFlagService $features,
    ): View
    {
        abort_unless($service->is_active, 404);

        $service->load(['packages' => fn ($query) => $query->where('is_active', true)]);
        if (! $features->enabled('service_landing_pages')) {
            return view('services.show-legacy', compact('service'));
        }
        $content = $landing->content($service);
        $structuredData = $landing->structuredData($service, $content);
        $relatedServices = Service::query()
            ->where('is_active', true)
            ->where('category', $service->category)
            ->where('id', '!=', $service->id)
            ->orderBy('sort_order')
            ->take(3)
            ->get();

        return view('services.show', compact('service', 'content', 'structuredData', 'relatedServices'));
    }
}
