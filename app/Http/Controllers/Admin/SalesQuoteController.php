<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\Inquiry;
use App\Models\SalesQuote;
use App\Models\SalesQuoteTemplate;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\BrandingService;
use App\Services\FeatureFlagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SalesQuoteController extends Controller
{
    public function index(Request $request): View
    {
        $status = trim((string) $request->query('status'));
        $search = trim((string) $request->query('q'));
        $quotes = SalesQuote::query()
            ->with(['inquiry', 'invoice', 'creator'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                $nested->where('quote_number', 'like', '%'.$search.'%')
                    ->orWhere('recipient_name', 'like', '%'.$search.'%')
                    ->orWhere('recipient_company', 'like', '%'.$search.'%')
                    ->orWhere('recipient_phone', 'like', '%'.$search.'%');
            }))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.quotes.index', compact('quotes', 'status', 'search'));
    }

    public function create(Request $request): View
    {
        $lead = $request->filled('lead')
            ? CrmLead::query()->with(['contact', 'inquiry.package.service', 'serviceOrder'])->findOrFail($request->integer('lead'))
            : null;
        $inquiry = $request->filled('inquiry')
            ? Inquiry::query()->with(['package.service', 'serviceOrder', 'referredByPartner'])->findOrFail($request->integer('inquiry'))
            : $lead?->inquiry;
        $serviceId = $inquiry?->package?->service_id;
        $templatesEnabled = app(FeatureFlagService::class)->enabled('quote_templates');
        $template = ! $templatesEnabled
            ? null
            : ($request->filled('template')
                ? SalesQuoteTemplate::query()->where('is_active', true)->findOrFail($request->integer('template'))
                : SalesQuoteTemplate::query()->where('is_active', true)
                ->where(function ($query) use ($serviceId): void {
                    $query->when($serviceId, fn ($builder) => $builder->where('service_id', $serviceId))
                        ->orWhereNull('service_id');
                })
                ->orderByRaw('CASE WHEN service_id IS NULL THEN 1 ELSE 0 END')
                ->first());

        return view('admin.quotes.form', $this->formData(null, $inquiry, $lead, $template));
    }

    public function store(Request $request): RedirectResponse
    {
        $prepared = $this->prepare($request);
        $actor = $request->attributes->get('currentUser');
        abort_unless($actor instanceof User, 403);

        $quote = DB::transaction(function () use ($actor, $prepared): SalesQuote {
            $quote = SalesQuote::query()->create([
                'quote_number' => 'PENDING-'.Str::uuid(),
                'public_token' => Str::random(64),
                'created_by' => $actor->id,
                ...$prepared['attributes'],
                'status' => 'draft',
                'subtotal' => $prepared['subtotal'],
                'discount' => $prepared['discount'],
                'total' => $prepared['total'],
            ]);
            $quote->update(['quote_number' => sprintf('QTN/IH/%s/%05d', now()->format('Ym'), $quote->id)]);
            $quote->items()->createMany($prepared['items']);
            if ($quote->sales_quote_template_id) {
                SalesQuoteTemplate::query()->whereKey($quote->sales_quote_template_id)->increment('use_count');
            }

            return $quote;
        });

        return redirect()->route('admin.quotes.show', $quote)->with('success', 'Draf penawaran berhasil dibuat.');
    }

    public function show(SalesQuote $quote, BrandingService $brandingService): View
    {
        $quote->load(['items.package.service', 'creator', 'inquiry.crmLead', 'crmLead.contact', 'serviceOrder', 'invoice', 'referredByPartner']);

        return view('admin.quotes.show', [
            'quote' => $quote,
            'shareUrl' => route('quotes.public', $quote->public_token),
            'branding' => $brandingService->document(),
        ]);
    }

    public function edit(SalesQuote $quote): View
    {
        abort_unless($quote->status === 'draft', 422, 'Hanya penawaran draf yang dapat diubah.');
        $quote->load(['items', 'inquiry', 'crmLead.contact', 'template']);

        return view('admin.quotes.form', $this->formData($quote, $quote->inquiry, $quote->crmLead, $quote->template));
    }

    public function update(Request $request, SalesQuote $quote): RedirectResponse
    {
        abort_unless($quote->status === 'draft', 422, 'Hanya penawaran draf yang dapat diubah.');
        $prepared = $this->prepare($request);
        DB::transaction(function () use ($quote, $prepared): void {
            $locked = SalesQuote::query()->lockForUpdate()->findOrFail($quote->id);
            abort_unless($locked->status === 'draft', 422, 'Penawaran sudah dikunci.');
            $locked->update([
                ...$prepared['attributes'],
                'subtotal' => $prepared['subtotal'],
                'discount' => $prepared['discount'],
                'total' => $prepared['total'],
            ]);
            $locked->items()->delete();
            $locked->items()->createMany($prepared['items']);
        });

        return redirect()->route('admin.quotes.show', $quote)->with('success', 'Penawaran berhasil diperbarui.');
    }

    public function send(Request $request, SalesQuote $quote): RedirectResponse
    {
        abort_unless($quote->status === 'draft', 422, 'Hanya draf yang dapat diterbitkan.');
        $quote->update(['status' => 'sent', 'sent_at' => now()]);
        $quote->loadMissing(['crmLead', 'inquiry.crmLead']);
        $lead = $quote->crmLead ?: $quote->inquiry?->crmLead;
        if ($lead) {
            $lead->update([
                'stage' => 'proposal',
                'probability' => 55,
                'estimated_value' => $quote->total,
                'last_quote_at' => now(),
                'last_stage_changed_at' => $lead->stage !== 'proposal' ? now() : $lead->last_stage_changed_at,
            ]);
            CrmActivity::query()->create([
                'contact_id' => $lead->contact_id,
                'lead_id' => $lead->id,
                'service_order_id' => $lead->service_order_id,
                'user_id' => $request->attributes->get('currentUser')?->id,
                'type' => 'quote_sent',
                'title' => 'Penawaran diterbitkan',
                'description' => $quote->quote_number.' · '.$quote->formattedTotal(),
                'completed_at' => now(),
            ]);
        }

        return back()->with('success', 'Penawaran diterbitkan. Bagikan tautan publik melalui WhatsApp.');
    }

    public function cancel(SalesQuote $quote): RedirectResponse
    {
        abort_unless(in_array($quote->status, ['draft', 'sent'], true), 422, 'Penawaran ini tidak dapat dibatalkan.');
        $quote->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return back()->with('success', 'Penawaran dibatalkan tanpa menghapus histori.');
    }

    private function prepare(Request $request): array
    {
        $validated = $request->validate([
            'inquiry_id' => ['nullable', 'exists:inquiries,id'],
            'crm_lead_id' => ['nullable', 'exists:crm_leads,id'],
            'sales_quote_template_id' => [
                'nullable',
                Rule::exists('sales_quote_templates', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'recipient_name' => ['required', 'string', 'max:160'],
            'recipient_company' => ['nullable', 'string', 'max:180'],
            'recipient_email' => ['nullable', 'email', 'max:160'],
            'recipient_phone' => ['nullable', 'string', 'max:32'],
            'recipient_address' => ['nullable', 'string', 'max:1000'],
            'issue_date' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after_or_equal:issue_date'],
            'invoice_due_days' => ['required', 'integer', 'min:1', 'max:90'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'scope' => ['nullable', 'string', 'max:10000'],
            'terms' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'items' => ['required', 'array', 'min:1', 'max:15'],
            'items.*.service_package_id' => ['nullable', 'exists:service_packages,id'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'],
        ]);
        $inquiry = ! empty($validated['inquiry_id'])
            ? Inquiry::query()->with(['serviceOrder', 'referredByPartner'])->findOrFail((int) $validated['inquiry_id'])
            : null;
        $lead = ! empty($validated['crm_lead_id'])
            ? CrmLead::query()->with('serviceOrder')->findOrFail((int) $validated['crm_lead_id'])
            : null;
        $packageIds = collect($validated['items'])->pluck('service_package_id')->filter()->unique();
        $packages = ServicePackage::query()->whereIn('id', $packageIds)->get()->keyBy('id');
        $items = [];
        $subtotal = 0;

        foreach ($validated['items'] as $index => $item) {
            $package = ! empty($item['service_package_id']) ? $packages->get((int) $item['service_package_id']) : null;
            $unitPrice = (int) $item['unit_price'];
            if ($package && $unitPrice < (int) ($package->minimum_end_user_price ?? 0)) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_price" => 'Harga tidak boleh di bawah minimum end user Rp'.number_format($package->minimum_end_user_price, 0, ',', '.').'.',
                ]);
            }
            $lineTotal = $unitPrice * (int) $item['quantity'];
            $subtotal += $lineTotal;
            $items[] = [
                'service_package_id' => $package?->id,
                'description' => trim($item['description']),
                'quantity' => (int) $item['quantity'],
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        $discount = (int) ($validated['discount'] ?? 0);
        if ($discount > $subtotal) {
            throw ValidationException::withMessages(['discount' => 'Potongan tidak boleh melebihi subtotal.']);
        }

        return [
            'attributes' => [
                'inquiry_id' => $inquiry?->id,
                'crm_lead_id' => $lead?->id,
                'sales_quote_template_id' => app(FeatureFlagService::class)->enabled('quote_templates')
                    ? ($validated['sales_quote_template_id'] ?? null)
                    : null,
                'service_order_id' => $inquiry?->serviceOrder?->id ?: $lead?->serviceOrder?->id,
                'referred_by_partner_id' => $inquiry?->referred_by_partner_id,
                'coupon_id' => $inquiry?->coupon_id,
                'referral_code' => $inquiry?->referral_code,
                'coupon_code' => $inquiry?->coupon_code,
                'recipient_name' => $validated['recipient_name'],
                'recipient_company' => $validated['recipient_company'] ?? null,
                'recipient_email' => $validated['recipient_email'] ?? null,
                'recipient_phone' => $validated['recipient_phone'] ?? null,
                'recipient_address' => $validated['recipient_address'] ?? null,
                'issue_date' => $validated['issue_date'],
                'valid_until' => $validated['valid_until'],
                'invoice_due_days' => (int) $validated['invoice_due_days'],
                'scope' => $validated['scope'] ?? null,
                'terms' => $validated['terms'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ],
            'items' => $items,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => max(0, $subtotal - $discount),
        ];
    }

    private function formData(
        ?SalesQuote $quote,
        ?Inquiry $inquiry,
        ?CrmLead $lead = null,
        ?SalesQuoteTemplate $defaultTemplate = null,
    ): array
    {
        $packages = ServicePackage::query()
            ->with('service')
            ->where('is_active', true)
            ->whereHas('service', fn ($query) => $query->where('is_active', true))
            ->orderBy('service_id')
            ->orderBy('sort_order')
            ->get();
        $packageOptions = $packages->map(fn (ServicePackage $package): array => [
            'id' => $package->id,
            'name' => ($package->service?->name ?: 'Layanan').' · '.$package->name,
            'description' => $package->name,
            'price' => (int) $package->price,
            'minimum' => (int) ($package->minimum_end_user_price ?? 0),
        ])->values()->all();
        $formItems = $quote
            ? $quote->items->map(fn ($item): array => $item->only(['service_package_id', 'description', 'quantity', 'unit_price']))->values()->all()
            : [[
                'service_package_id' => $inquiry?->service_package_id ?: '',
                'description' => $inquiry?->package?->name ?: ($lead?->service_interest ?: ''),
                'quantity' => 1,
                'unit_price' => $inquiry?->package?->price ?: (int) ($lead?->estimated_value ?? 0),
            ]];
        $quoteTemplatesEnabled = app(FeatureFlagService::class)->enabled('quote_templates');
        $quoteTemplates = $quoteTemplatesEnabled
            ? SalesQuoteTemplate::query()
                ->with('service')
                ->where('is_active', true)
                ->orderByRaw('CASE WHEN service_id IS NULL THEN 1 ELSE 0 END')
                ->orderBy('name')
                ->get()
            : collect();
        $templateOptions = $quoteTemplates->map(fn (SalesQuoteTemplate $template): array => [
            'id' => $template->id,
            'scope' => $template->scope,
            'terms' => $template->terms,
            'notes' => $template->notes,
            'validity_days' => $template->validity_days,
            'invoice_due_days' => $template->invoice_due_days,
        ])->values()->all();

        return compact(
            'quote', 'inquiry', 'lead', 'packages', 'packageOptions', 'formItems',
            'quoteTemplates', 'templateOptions', 'defaultTemplate',
            'quoteTemplatesEnabled',
        );
    }
}
