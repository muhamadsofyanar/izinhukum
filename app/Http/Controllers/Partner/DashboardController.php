<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\ServicePackage;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->attributes->get('currentUser');
        $invoices = Invoice::query()
            ->where(function ($query) use ($user): void {
                $query->where('created_by', $user->id)->orWhere('partner_id', $user->id);
            });

        return view('partner.dashboard', [
            'activePackages' => ServicePackage::where('is_active', true)->count(),
            'createdInvoices' => (clone $invoices)->where('created_by', $user->id)->count(),
            'incomingInvoices' => (clone $invoices)->where('partner_id', $user->id)->count(),
            'paidInvoices' => (clone $invoices)->where('status', 'paid')->count(),
            'latestInvoices' => $invoices->with('creator')->latest()->limit(8)->get(),
        ]);
    }
}
