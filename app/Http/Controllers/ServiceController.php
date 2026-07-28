<?php

namespace App\Http\Controllers;

use App\Models\Service;
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

    public function show(Service $service): View
    {
        abort_unless($service->is_active, 404);

        $service->load(['packages' => fn ($query) => $query->where('is_active', true)]);

        return view('services.show', compact('service'));
    }
}
