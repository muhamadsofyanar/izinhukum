<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        $services = Service::query()
            ->with(['packages' => fn ($query) => $query->where('is_active', true)])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return view('home', [
            'services' => $services,
            'featuredServices' => $services->where('is_featured', true)->take(6),
        ]);
    }
}
