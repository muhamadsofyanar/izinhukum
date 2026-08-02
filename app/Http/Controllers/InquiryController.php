<?php

namespace App\Http\Controllers;

use App\Jobs\SendNewInquiryEmailNotification;
use App\Jobs\SendNewInquiryWhatsAppNotification;
use App\Models\Inquiry;
use App\Models\ServicePackage;
use App\Services\CouponService;
use App\Services\FeatureFlagService;
use App\Services\PartnerReferralService;
use App\Services\ReferralEventService;
use App\Services\ServiceOrderService;
use App\Services\SalesPipelineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InquiryController extends Controller
{
    public function create(Request $request): View
    {
        $packages = ServicePackage::query()
            ->with('service')
            ->where('is_active', true)
            ->whereHas('service', fn ($query) => $query->where('is_active', true))
            ->orderBy('service_id')
            ->orderBy('sort_order')
            ->get();

        $selectedPackage = $request->integer('paket');
        $prefillMessage = Str::limit(trim((string) $request->query('pesan')), 3000, '');
        $prefillCompanyName = Str::limit(trim((string) $request->query('nama_usaha')), 160, '');
        $journeySource = in_array($request->query('asal'), ['name_generator', 'deed_simulator'], true)
            ? (string) $request->query('asal')
            : 'website';
        $prefillCouponCode = Str::upper(Str::limit(trim((string) $request->query('kupon')), 32, ''));

        return view('proposal', compact(
            'packages',
            'selectedPackage',
            'prefillMessage',
            'prefillCompanyName',
            'journeySource',
            'prefillCouponCode',
        ));
    }

    public function checkCoupon(Request $request, CouponService $coupons): JsonResponse
    {
        $validated = $request->validate([
            'service_package_id' => ['required', 'exists:service_packages,id'],
            'coupon_code' => ['required', 'string', 'max:32'],
        ]);
        $package = ServicePackage::query()
            ->with('service')
            ->where('is_active', true)
            ->findOrFail((int) $validated['service_package_id']);
        $quote = $coupons->quote($validated['coupon_code'], $package);

        return response()->json([
            'valid' => true,
            'code' => $quote['code'],
            'discount_amount' => $quote['discount_amount'],
            'discount_formatted' => 'Rp'.number_format($quote['discount_amount'], 0, ',', '.'),
            'subtotal' => $quote['subtotal'],
            'total' => $quote['total'],
            'total_formatted' => 'Rp'.number_format($quote['total'], 0, ',', '.'),
            'message' => 'Kupon aktif. Promo akan dicatat ketika proposal dikirim.',
        ]);
    }

    public function store(
        Request $request,
        PartnerReferralService $referrals,
        ServiceOrderService $orders,
        ReferralEventService $events,
        FeatureFlagService $features,
        CouponService $coupons,
        SalesPipelineService $pipeline,
    ): RedirectResponse {
        $validated = $request->validate([
            'service_package_id' => ['nullable', 'exists:service_packages,id'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:160'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:3000'],
            'journey_source' => ['nullable', 'in:website,name_generator,deed_simulator'],
            'coupon_code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'privacy_consent' => ['accepted'],
        ]);
        $journeySource = $validated['journey_source'] ?? 'website';
        $couponCode = Str::upper(trim((string) ($validated['coupon_code'] ?? '')));
        unset($validated['journey_source'], $validated['coupon_code'], $validated['privacy_consent']);

        $attribution = $features->enabled('referral_tracking')
            ? $referrals->attribution($request)
            : null;
        $marketing = $features->enabled('campaign_tracking')
            ? array_intersect_key(
                (array) $request->session()->get('marketing_attribution', []),
                array_flip(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'landing_path']),
            )
            : [];
        $inquiry = DB::transaction(function () use (
            $validated,
            $couponCode,
            $coupons,
            $attribution,
            $marketing,
            $journeySource,
            $events,
            $orders,
        ): Inquiry {
            $package = ! empty($validated['service_package_id'])
                ? ServicePackage::query()->with('service')->find((int) $validated['service_package_id'])
                : null;
            $quote = $couponCode !== '' ? $coupons->quote($couponCode, $package, true) : null;

            $inquiry = Inquiry::query()->create([
                ...$validated,
                ...($attribution ?? []),
                ...$marketing,
                'coupon_id' => $quote['coupon']->id ?? null,
                'coupon_code' => $quote['code'] ?? null,
                'coupon_discount_type' => $quote['discount_type'] ?? null,
                'coupon_discount_value' => $quote['discount_value'] ?? 0,
                'coupon_discount_amount' => $quote['discount_amount'] ?? 0,
                'reference' => 'IH-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
                'source' => $attribution ? 'partner_referral' : $journeySource,
                'status' => 'baru',
            ]);

            if ($quote) {
                $coupons->redeem($quote, $inquiry);
            }
            $events->recordInquiry($inquiry);
            $orders->createFromInquiry($inquiry);

            return $inquiry;
        });
        if ($features->enabled('sales_pipeline')) {
            try {
                $pipeline->syncInquiry($inquiry);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        SendNewInquiryEmailNotification::dispatch($inquiry->id)->onQueue('default');
        SendNewInquiryWhatsAppNotification::dispatch($inquiry->id)->onQueue('whatsapp');

        return redirect()
            ->route('proposal.success', $inquiry)
            ->with('success', 'Permintaan Anda sudah kami terima.')
            ->with('open_whatsapp', true);
    }

    public function success(Inquiry $inquiry): View
    {
        $inquiry->load(['package.service', 'serviceOrder']);
        $service = $inquiry->package?->service?->name
            ?: $inquiry->package?->name
            ?: 'konsultasi legalitas';
        $message = implode("\n", array_filter([
            'Halo IzinHukum, saya sudah mengirim permintaan.',
            'Referensi: '.$inquiry->reference,
            $inquiry->serviceOrder ? 'Nomor order: '.$inquiry->serviceOrder->order_number : null,
            'Nama: '.$inquiry->name,
            'Kebutuhan: '.$service,
            $inquiry->coupon_code ? 'Kupon promo: '.$inquiry->coupon_code : null,
            $inquiry->coupon_discount_amount > 0
                ? 'Potongan tercatat: Rp'.number_format($inquiry->coupon_discount_amount, 0, ',', '.')
                : null,
            '',
            'Mohon ditindaklanjuti melalui WhatsApp ini.',
        ], fn (?string $line): bool => $line !== null));
        $whatsappNumber = preg_replace('/\D/', '', (string) config('company.whatsapp'));
        $whatsappUrl = 'https://wa.me/'.$whatsappNumber.'?text='.urlencode($message);
        $openWhatsApp = (bool) session('open_whatsapp', false);

        return view('proposal-success', compact('inquiry', 'whatsappUrl', 'openWhatsApp'));
    }
}
