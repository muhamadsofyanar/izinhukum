<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SalesQuote;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GrowthAnalyticsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $days = in_array($request->integer('days'), [7, 30, 90], true) ? $request->integer('days') : 30;
        $from = now()->subDays($days - 1)->startOfDay();
        $inquiries = Inquiry::query()
            ->with(['package.service', 'referredByPartner'])
            ->where('created_at', '>=', $from)
            ->get();
        $quotes = SalesQuote::query()->where('created_at', '>=', $from)->get();
        $invoices = Invoice::query()->where('created_at', '>=', $from)->get();
        $payments = Payment::query()->active()->whereDate('payment_date', '>=', $from->toDateString())->get();

        $sourceRows = $inquiries->groupBy(function (Inquiry $inquiry): string {
            if ($inquiry->utm_source) {
                return $inquiry->utm_source;
            }
            if ($inquiry->referred_by_partner_id) {
                return 'referral';
            }

            return $inquiry->source ?: 'langsung';
        })->map(fn ($items, $source): array => [
            'name' => $source,
            'leads' => $items->count(),
            'qualified' => $items->whereIn('status', ['dihubungi', 'proses', 'selesai'])->count(),
            'value' => (int) $items->sum(fn (Inquiry $inquiry) => $inquiry->package?->price ?? 0),
        ])->sortByDesc('leads')->values();

        $campaignRows = $inquiries->whereNotNull('utm_campaign')->groupBy('utm_campaign')->map(fn ($items, $campaign): array => [
            'name' => $campaign,
            'leads' => $items->count(),
            'qualified' => $items->whereIn('status', ['dihubungi', 'proses', 'selesai'])->count(),
        ])->sortByDesc('leads')->values();

        $serviceRows = $inquiries->groupBy(fn (Inquiry $inquiry): string => $inquiry->package?->service?->name ?: 'Belum memilih layanan')
            ->map(fn ($items, $service): array => [
                'name' => $service,
                'leads' => $items->count(),
                'value' => (int) $items->sum(fn (Inquiry $inquiry) => $inquiry->package?->price ?? 0),
            ])->sortByDesc('leads')->take(8)->values();

        $partnerRows = $inquiries->whereNotNull('referred_by_partner_id')
            ->groupBy(fn (Inquiry $inquiry): string => $inquiry->referredByPartner?->name ?: 'Mitra tidak aktif')
            ->map(fn ($items, $partner): array => [
                'name' => $partner,
                'leads' => $items->count(),
                'completed' => $items->where('status', 'selesai')->count(),
                'value' => (int) $items->sum(fn (Inquiry $inquiry) => $inquiry->package?->price ?? 0),
            ])->sortByDesc('leads')->take(8)->values();

        $approvedQuotes = $quotes->where('status', 'approved')->count();
        $approvedLeadCount = $quotes->where('status', 'approved')->whereNotNull('inquiry_id')->pluck('inquiry_id')->unique()->count();
        $metrics = [
            'leads' => $inquiries->count(),
            'quotes' => $quotes->whereIn('status', ['sent', 'approved', 'rejected'])->count(),
            'approved_quotes' => $approvedQuotes,
            'invoiced' => (int) $invoices->whereNotIn('status', ['draft', 'cancelled'])->sum('total'),
            'collected' => (int) $payments->sum('amount'),
            'conversion' => $inquiries->count() > 0 ? round(($approvedLeadCount / $inquiries->count()) * 100, 1) : 0,
        ];

        return view('admin.growth.index', [
            'days' => $days,
            'from' => $from,
            'metrics' => $metrics,
            'sourceRows' => $sourceRows,
            'campaignRows' => $campaignRows,
            'serviceRows' => $serviceRows,
            'partnerRows' => $partnerRows,
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get(['name', 'slug']),
        ]);
    }
}
