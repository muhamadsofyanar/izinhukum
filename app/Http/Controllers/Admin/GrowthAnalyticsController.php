<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\CrmLead;
use App\Models\Invoice;
use App\Models\PaymentProof;
use App\Models\Payment;
use App\Models\SalesQuote;
use App\Models\Service;
use App\Services\FeatureFlagService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GrowthAnalyticsController extends Controller
{
    public function __invoke(Request $request, FeatureFlagService $features): View
    {
        $days = in_array($request->integer('days'), [7, 30, 90], true) ? $request->integer('days') : 30;
        $from = now()->subDays($days - 1)->startOfDay();
        $inquiries = Inquiry::query()
            ->with(['package.service', 'referredByPartner', 'marketingCampaign'])
            ->where('created_at', '>=', $from)
            ->get();
        $quotes = SalesQuote::query()->where('created_at', '>=', $from)->get();
        $invoices = Invoice::query()->where('created_at', '>=', $from)->get();
        $payments = Payment::query()->with('invoice')->active()->whereDate('payment_date', '>=', $from->toDateString())->get();
        $leads = CrmLead::query()->where('created_at', '>=', $from)->get();

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

        $campaignRows = $inquiries->whereNotNull('utm_campaign')->groupBy(fn (Inquiry $inquiry): string => (string) ($inquiry->marketing_campaign_id ?: $inquiry->utm_campaign))->map(function ($items) use ($quotes, $invoices, $payments): array {
            $campaign = $items->first()?->marketingCampaign;
            $inquiryIds = $items->pluck('id');
            $campaignInvoices = $invoices->whereIn('inquiry_id', $inquiryIds);
            $invoiceIds = $campaignInvoices->pluck('id');
            $collected = (int) $payments->whereIn('invoice_id', $invoiceIds)->sum('amount');
            $spend = (int) ($campaign?->spend ?? 0);
            $leadCount = $items->count();

            return [
                'name' => $campaign?->name ?: $items->first()->utm_campaign,
                'leads' => $leadCount,
                'qualified' => $items->whereIn('status', ['dihubungi', 'proses', 'selesai'])->count(),
                'approved' => $quotes->whereIn('inquiry_id', $inquiryIds)->where('status', 'approved')->count(),
                'collected' => $collected,
                'spend' => $spend,
                'cost_per_lead' => $leadCount > 0 ? round($spend / $leadCount) : 0,
                'roi' => $spend > 0 ? round((($collected - $spend) / $spend) * 100, 1) : null,
            ];
        })->sortByDesc('leads')->values();

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
            'response_minutes' => $leads->whereNotNull('response_minutes')->count() > 0
                ? (int) round($leads->whereNotNull('response_minutes')->avg('response_minutes'))
                : null,
        ];

        $funnel = [
            ['label' => 'Lead masuk', 'count' => $leads->count()],
            ['label' => 'Sudah dihubungi', 'count' => $leads->whereNotNull('first_contacted_at')->count()],
            ['label' => 'Menerima penawaran', 'count' => $leads->whereNotNull('last_quote_at')->count()],
            ['label' => 'Deal', 'count' => $leads->whereNotNull('won_at')->count()],
            ['label' => 'Selesai', 'count' => $leads->where('stage', 'completed')->count()],
        ];
        $funnelBase = max(1, $leads->count());
        $funnel = collect($funnel)->map(fn (array $step): array => [
            ...$step,
            'rate' => round(($step['count'] / $funnelBase) * 100, 1),
        ]);

        $lossRows = $leads->where('stage', 'lost')->groupBy(fn (CrmLead $lead): string => $lead->loss_reason_code ?: 'other')
            ->map(fn ($items, $reason): array => [
                'name' => CrmLead::LOSS_REASONS[$reason] ?? 'Alasan lainnya',
                'count' => $items->count(),
                'value' => (int) $items->sum('estimated_value'),
            ])->sortByDesc('count')->values();

        $recommendations = collect([
            $features->enabled('sales_pipeline') && $features->enabled('lead_prioritization') ? [
                'title' => 'Lead panas belum memiliki jadwal follow-up',
                'count' => CrmLead::query()->where('temperature', 'hot')->whereNotIn('stage', ['completed', 'lost'])->whereNull('next_follow_up_at')->count(),
                'url' => route('admin.pipeline.index', ['temperature' => 'hot']),
                'action' => 'Jadwalkan follow-up',
            ] : null,
            $features->enabled('sales_pipeline') ? [
                'title' => 'Lead baru belum dihubungi lebih dari 2 jam',
                'count' => CrmLead::query()->where('stage', 'new')->whereNull('first_contacted_at')->where('created_at', '<=', now()->subHours(2))->count(),
                'url' => route('admin.pipeline.index', ['stage' => 'new']),
                'action' => 'Buka lead baru',
            ] : null,
            $features->enabled('digital_quotes') ? [
                'title' => 'Penawaran akan kedaluwarsa dalam 3 hari',
                'count' => SalesQuote::query()->where('status', 'sent')->whereBetween('valid_until', [today(), today()->addDays(3)])->count(),
                'url' => route('admin.quotes.index', ['status' => 'sent']),
                'action' => 'Follow-up penawaran',
            ] : null,
            $features->enabled('payment_proof_upload') ? [
                'title' => 'Bukti pembayaran menunggu pemeriksaan',
                'count' => PaymentProof::query()->where('status', 'pending')->count(),
                'url' => route('admin.invoices.index'),
                'action' => 'Periksa pembayaran',
            ] : null,
            $features->enabled('sales_pipeline') && $features->enabled('lead_recovery') ? [
                'title' => 'Lead siap diaktifkan kembali',
                'count' => CrmLead::query()->where('stage', 'lost')->whereNotNull('reactivate_at')->where('reactivate_at', '<=', now())->count(),
                'url' => route('admin.pipeline.index', ['recovery' => 1]),
                'action' => 'Buka daftar pemulihan',
            ] : null,
        ])->filter(fn (?array $item): bool => $item !== null && $item['count'] > 0)->values();

        return view('admin.growth.index', [
            'days' => $days,
            'from' => $from,
            'metrics' => $metrics,
            'sourceRows' => $sourceRows,
            'campaignRows' => $campaignRows,
            'serviceRows' => $serviceRows,
            'partnerRows' => $partnerRows,
            'funnel' => $funnel,
            'lossRows' => $lossRows,
            'recommendations' => $recommendations,
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get(['name', 'slug']),
            'campaignRoiEnabled' => $features->enabled('campaign_roi'),
        ]);
    }
}
