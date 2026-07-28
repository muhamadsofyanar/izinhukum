<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use Illuminate\View\View;

class PriceController extends Controller
{
    public function index(): View
    {
        $packages = ServicePackage::query()
            ->with('service')
            ->where('is_active', true)
            ->whereHas('service', fn ($query) => $query->where('is_active', true))
            ->orderBy('service_id')
            ->orderBy('sort_order')
            ->get();

        return view('partner.prices', compact('packages'));
    }
}
