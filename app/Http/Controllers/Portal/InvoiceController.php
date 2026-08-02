<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Mail\InvoiceMail;
use App\Models\AuditLog;
use App\Models\FinancialCategory;
use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\BrandingService;
use App\Services\MailConfigurator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->attributes->get('currentUser');
        $status = $request->query('status');
        $invoices = $this->visibleInvoices($user)
            ->with(['creator', 'partner'])
            ->withSum([
                'payments as amount_paid' => fn ($query) => $query->active(),
            ], 'amount')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('portal.invoices.index', compact('invoices', 'status', 'user'));
    }

    public function create(Request $request): View
    {
        $user = $request->attributes->get('currentUser');
        $inquiry = null;

        if ($user->isAdmin() && $request->filled('inquiry')) {
            $inquiry = Inquiry::query()
                ->with(['package.service', 'referredByPartner'])
                ->findOrFail($request->integer('inquiry'));
        }

        return view('portal.invoices.create', $this->formData($user, null, $inquiry));
    }

    public function edit(Request $request, Invoice $invoice): View
    {
        $user = $request->attributes->get('currentUser');
        $this->authorizeInvoice($user, $invoice);
        $this->authorizeInvoiceMutation($user, $invoice);
        $this->ensureDraftEditable($invoice);

        return view('portal.invoices.create', $this->formData($user, $invoice));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->attributes->get('currentUser');
        $prepared = $this->prepareInvoice($request, $user);

        $invoice = DB::transaction(function () use ($user, $prepared): Invoice {
            $invoice = Invoice::query()->create([
                'invoice_number' => 'PENDING-'.Str::uuid(),
                'public_token' => Str::random(56),
                'created_by' => $user->id,
                ...$prepared['attributes'],
                'status' => 'draft',
                'subtotal' => $prepared['subtotal'],
                'discount' => $prepared['discount'],
                'total' => $prepared['total'],
            ]);

            $invoice->update([
                'invoice_number' => sprintf('INV/IH/%s/%05d', now()->format('Ym'), $invoice->id),
            ]);
            $invoice->items()->createMany($prepared['items']);
            if ($invoice->inquiry_id) {
                Inquiry::query()
                    ->whereKey($invoice->inquiry_id)
                    ->whereNotIn('status', ['selesai', 'batal'])
                    ->update(['status' => 'proses']);
            }

            return $invoice;
        });

        return redirect()
            ->route($this->routePrefix($user).'.invoices.show', $invoice)
            ->with('success', 'Invoice berhasil dibuat.');
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        $user = $request->attributes->get('currentUser');
        $this->authorizeInvoice($user, $invoice);
        $this->authorizeInvoiceMutation($user, $invoice);
        $this->ensureDraftEditable($invoice);
        $prepared = $this->prepareInvoice($request, $user);

        DB::transaction(function () use ($request, $user, $invoice, $prepared): void {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->ensureDraftEditable($lockedInvoice);
            $before = $lockedInvoice->only([
                'recipient_type',
                'partner_id',
                'recipient_name',
                'recipient_company',
                'recipient_email',
                'recipient_phone',
                'recipient_address',
                'issue_date',
                'due_date',
                'subtotal',
                'discount',
                'total',
                'coupon_id',
                'coupon_code',
                'coupon_discount_type',
                'coupon_discount_value',
                'notes',
            ]);

            $lockedInvoice->update([
                ...$prepared['attributes'],
                'subtotal' => $prepared['subtotal'],
                'discount' => $prepared['discount'],
                'total' => $prepared['total'],
            ]);
            $lockedInvoice->items()->delete();
            $lockedInvoice->items()->createMany($prepared['items']);

            AuditLog::query()->create([
                'user_id' => $user->id,
                'action' => 'invoice.updated',
                'subject_type' => Invoice::class,
                'subject_id' => $lockedInvoice->id,
                'metadata' => [
                    'invoice_number' => $lockedInvoice->invoice_number,
                    'before' => $before,
                    'after' => $lockedInvoice->fresh()->only(array_keys($before)),
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return redirect()
            ->route($this->routePrefix($user).'.invoices.show', $invoice)
            ->with('success', 'Invoice draf berhasil diperbarui.');
    }

    public function destroy(Request $request, Invoice $invoice): RedirectResponse
    {
        $user = $request->attributes->get('currentUser');
        $this->authorizeInvoice($user, $invoice);
        $this->authorizeInvoiceMutation($user, $invoice);
        $this->ensureDraftEditable($invoice);

        DB::transaction(function () use ($request, $user, $invoice): void {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->ensureDraftEditable($lockedInvoice);

            AuditLog::query()->create([
                'user_id' => $user->id,
                'action' => 'invoice.deleted',
                'subject_type' => Invoice::class,
                'subject_id' => $lockedInvoice->id,
                'metadata' => [
                    'invoice_number' => $lockedInvoice->invoice_number,
                    'recipient_name' => $lockedInvoice->recipient_name,
                    'total' => $lockedInvoice->total,
                ],
                'ip_address' => $request->ip(),
            ]);

            $lockedInvoice->delete();
        });

        return redirect()
            ->route($this->routePrefix($user).'.invoices.index')
            ->with('success', 'Invoice draf berhasil dihapus.');
    }

    public function cancel(Request $request, Invoice $invoice): RedirectResponse
    {
        $user = $request->attributes->get('currentUser');
        $this->authorizeInvoice($user, $invoice);
        $this->authorizeInvoiceMutation($user, $invoice);
        $data = $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $user, $invoice, $data): void {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($lockedInvoice->status !== 'sent') {
                throw ValidationException::withMessages([
                    'invoice' => 'Hanya invoice terkirim yang dapat dibatalkan.',
                ]);
            }

            if ($lockedInvoice->payments()->active()->exists()) {
                throw ValidationException::withMessages([
                    'invoice' => 'Invoice dengan pembayaran aktif tidak dapat dibatalkan.',
                ]);
            }

            $lockedInvoice->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancellation_reason' => $data['cancellation_reason'],
            ]);

            AuditLog::query()->create([
                'user_id' => $user->id,
                'action' => 'invoice.cancelled',
                'subject_type' => Invoice::class,
                'subject_id' => $lockedInvoice->id,
                'metadata' => [
                    'reason' => $data['cancellation_reason'],
                    'invoice_number' => $lockedInvoice->invoice_number,
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return back()->with('success', 'Invoice dibatalkan dan alasan tersimpan pada audit log.');
    }

    public function show(
        Request $request,
        Invoice $invoice,
        BrandingService $brandingService,
    ): View {
        $user = $request->attributes->get('currentUser');
        $this->authorizeInvoice($user, $invoice);
        $invoice->load([
            'items.package.service',
            'creator',
            'partner',
            'inquiry',
            'referredByPartner',
            'payments.creator',
            'payments.category',
            'payments.cancelledBy',
            'payments.lastEditedBy',
            'paymentProofs.reviewer',
            'paymentProofs.payment',
            'cancelledBy',
        ]);

        return view('portal.invoices.show', [
            'invoice' => $invoice,
            'user' => $user,
            'branding' => $brandingService->document(),
            'incomeCategories' => $user->isAdmin()
                ? FinancialCategory::query()
                    ->where('type', 'income')
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get()
                : collect(),
        ]);
    }

    public function updateStatus(Request $request, Invoice $invoice): RedirectResponse
    {
        $user = $request->attributes->get('currentUser');
        $this->authorizeInvoice($user, $invoice);
        $this->authorizeInvoiceMutation($user, $invoice);
        $this->ensureDraftEditable($invoice);

        $request->validate([
            'status' => ['required', Rule::in(['sent'])],
        ]);

        $invoice->update([
            'status' => 'sent',
            'sent_at' => $invoice->sent_at ?: now(),
        ]);

        return back()->with('success', 'Invoice ditandai sebagai terkirim dan datanya kini dikunci.');
    }

    public function send(
        Request $request,
        Invoice $invoice,
        MailConfigurator $mailConfigurator,
    ): RedirectResponse {
        $user = $request->attributes->get('currentUser');
        $this->authorizeInvoice($user, $invoice);
        $this->authorizeInvoiceMutation($user, $invoice);

        if ($invoice->status === 'cancelled') {
            return back()->withErrors(['email' => 'Invoice yang dibatalkan tidak dapat dikirim.']);
        }
        if (! $invoice->recipient_email) {
            return back()->withErrors(['email' => 'Invoice belum memiliki email penerima.']);
        }

        try {
            $invoice->load(['items.package.service', 'creator']);
            $mailConfigurator->apply();
            Mail::to($invoice->recipient_email)->send(new InvoiceMail($invoice));
            $invoice->update([
                'status' => $invoice->status === 'draft' ? 'sent' : $invoice->status,
                'sent_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'email' => 'Email gagal dikirim. Periksa konfigurasi SMTP pada panel admin.',
            ]);
        }

        return back()->with('success', 'Invoice berhasil dikirim ke '.$invoice->recipient_email.'.');
    }

    private function prepareInvoice(Request $request, User $user): array
    {
        $recipientType = $user->isAdmin()
            ? $request->input('recipient_type', 'end_user')
            : 'end_user';
        $validated = $request->validate([
            'recipient_type' => ['nullable', Rule::in(['partner', 'end_user'])],
            'partner_id' => ['nullable', 'exists:users,id'],
            'inquiry_id' => ['nullable', 'exists:inquiries,id'],
            'referred_by_partner_id' => ['nullable', 'exists:users,id'],
            'recipient_name' => ['nullable', 'string', 'max:160'],
            'recipient_company' => ['nullable', 'string', 'max:160'],
            'recipient_email' => ['nullable', 'email', 'max:160'],
            'recipient_phone' => ['nullable', 'string', 'max:32'],
            'recipient_address' => ['nullable', 'string', 'max:1000'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.service_package_id' => ['required', 'exists:service_packages,id'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'items.*.unit_price' => ['nullable', 'integer', 'min:0'],
        ]);

        $inquiry = null;
        if ($user->isAdmin() && ! empty($validated['inquiry_id'])) {
            $inquiry = Inquiry::query()
                ->with('referredByPartner')
                ->findOrFail((int) $validated['inquiry_id']);
        }

        $partner = null;
        if ($recipientType === 'partner') {
            if (! $user->isAdmin()) {
                abort(403);
            }

            $partner = User::query()
                ->whereKey($validated['partner_id'] ?? null)
                ->where('role', 'partner')
                ->where('is_active', true)
                ->first();

            if (! $partner) {
                throw ValidationException::withMessages([
                    'partner_id' => 'Pilih mitra yang aktif.',
                ]);
            }
        } elseif (empty($validated['recipient_name'])) {
            throw ValidationException::withMessages([
                'recipient_name' => 'Nama penerima wajib diisi.',
            ]);
        }

        $referredPartner = null;
        $referredPartnerId = $user->isPartner()
            ? $user->id
            : ($validated['referred_by_partner_id']
                ?? $inquiry?->referred_by_partner_id);

        if ($referredPartnerId) {
            $referredPartner = User::query()
                ->whereKey($referredPartnerId)
                ->where('role', 'partner')
                ->where('is_active', true)
                ->first();

            if (! $referredPartner) {
                throw ValidationException::withMessages([
                    'referred_by_partner_id' => 'Mitra referral harus merupakan akun yang aktif.',
                ]);
            }
        }

        $packageIds = collect($validated['items'])->pluck('service_package_id')->unique();
        $packages = ServicePackage::query()
            ->with('service')
            ->whereIn('id', $packageIds)
            ->get()
            ->keyBy('id');
        $items = [];
        $subtotal = 0;

        foreach ($validated['items'] as $index => $item) {
            $package = $packages->get((int) $item['service_package_id']);
            if (! $package || ! $package->is_active) {
                throw ValidationException::withMessages([
                    "items.{$index}.service_package_id" => 'Paket tidak tersedia.',
                ]);
            }

            $quantity = (int) $item['quantity'];
            if ($recipientType === 'partner') {
                $unitPrice = (int) $package->partner_price;
            } else {
                $unitPrice = isset($item['unit_price']) && $item['unit_price'] !== ''
                    ? (int) $item['unit_price']
                    : (int) $package->price;
                $minimum = (int) $package->minimum_end_user_price;

                if ($unitPrice < $minimum) {
                    throw ValidationException::withMessages([
                        "items.{$index}.unit_price" => 'Harga jual tidak boleh di bawah Rp'
                            .number_format($minimum, 0, ',', '.').'.',
                    ]);
                }
            }

            $lineTotal = $unitPrice * $quantity;
            $subtotal += $lineTotal;
            $items[] = [
                'service_package_id' => $package->id,
                'description' => trim((string) ($item['description'] ?? '')) ?: $package->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'partner_cost' => $package->partner_price,
                'minimum_end_user_price' => $package->minimum_end_user_price,
                'line_total' => $lineTotal,
            ];
        }

        $couponDiscount = 0;
        $couponApplies = $recipientType === 'end_user'
            && $inquiry?->coupon_id
            && collect($items)->contains(
                fn (array $item): bool => (int) $item['service_package_id'] === (int) $inquiry->service_package_id,
            );
        if ($couponApplies) {
            $couponDiscount = min($subtotal, (int) $inquiry->coupon_discount_amount);
        }

        return [
            'attributes' => [
                'inquiry_id' => $inquiry?->id,
                'partner_id' => $partner?->id,
                'referred_by_partner_id' => $referredPartner?->id,
                'referral_code' => $referredPartner?->partner_code,
                'coupon_id' => $couponApplies ? $inquiry->coupon_id : null,
                'coupon_code' => $couponApplies ? $inquiry->coupon_code : null,
                'coupon_discount_type' => $couponApplies ? $inquiry->coupon_discount_type : null,
                'coupon_discount_value' => $couponApplies ? $inquiry->coupon_discount_value : 0,
                'recipient_type' => $recipientType,
                'recipient_name' => $partner?->name ?? $validated['recipient_name'],
                'recipient_company' => $partner?->company_name ?? ($validated['recipient_company'] ?? null),
                'recipient_email' => $partner?->email ?? ($validated['recipient_email'] ?? null),
                'recipient_phone' => $partner?->phone ?? ($validated['recipient_phone'] ?? null),
                'recipient_address' => $partner?->address ?? ($validated['recipient_address'] ?? null),
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ],
            'items' => $items,
            'subtotal' => $subtotal,
            'discount' => $couponDiscount,
            'total' => max(0, $subtotal - $couponDiscount),
        ];
    }

    private function formData(
        User $user,
        ?Invoice $invoice = null,
        ?Inquiry $inquiry = null,
    ): array
    {
        $invoice?->loadMissing(['items', 'inquiry', 'referredByPartner']);
        $sourceInquiry = $invoice?->inquiry ?: $inquiry;
        $packages = ServicePackage::query()
            ->with('service')
            ->where('is_active', true)
            ->whereHas('service', fn ($query) => $query->where('is_active', true))
            ->orderBy('service_id')
            ->orderBy('sort_order')
            ->get();
        $partners = $user->isAdmin()
            ? User::query()
                ->where('role', 'partner')
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
            : collect();
        $referredPartners = $partners;
        $packageOptions = $packages->map(fn (ServicePackage $package): array => [
            'id' => $package->id,
            'name' => $package->name,
            'website' => $package->price,
            'minimum' => $package->minimum_end_user_price ?? 0,
            'partner' => $package->partner_price ?? 0,
        ])->values()->all();
        $formItems = $invoice
            ? $invoice->items()->get()->map(fn ($item): array => [
                'service_package_id' => $item->service_package_id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
            ])->values()->all()
            : [[
                'service_package_id' => $sourceInquiry?->service_package_id ?: '',
                'description' => $sourceInquiry?->package?->name ?: '',
                'quantity' => 1,
                'unit_price' => $sourceInquiry?->package?->price ?: '',
            ]];
        $recipientDefaults = [
            'name' => $invoice?->recipient_name ?: $sourceInquiry?->name,
            'company' => $invoice?->recipient_company ?: $sourceInquiry?->company_name,
            'email' => $invoice?->recipient_email ?: $sourceInquiry?->email,
            'phone' => $invoice?->recipient_phone ?: $sourceInquiry?->phone,
            'address' => $invoice?->recipient_address,
        ];
        $selectedInquiryId = $invoice?->inquiry_id ?: $sourceInquiry?->id;
        $selectedReferralPartnerId = $invoice?->referred_by_partner_id
            ?: $sourceInquiry?->referred_by_partner_id
            ?: ($user->isPartner() ? $user->id : null);

        return compact(
            'user',
            'invoice',
            'packages',
            'partners',
            'referredPartners',
            'packageOptions',
            'formItems',
            'sourceInquiry',
            'recipientDefaults',
            'selectedInquiryId',
            'selectedReferralPartnerId',
        );
    }

    private function ensureDraftEditable(Invoice $invoice): void
    {
        if ($invoice->status !== 'draft' || $invoice->payments()->exists()) {
            throw ValidationException::withMessages([
                'invoice' => 'Hanya invoice draf tanpa pembayaran yang dapat diedit atau dihapus.',
            ]);
        }
    }

    private function visibleInvoices(User $user): Builder
    {
        $query = Invoice::query();

        if ($user->isPartner()) {
            $query->where(function ($builder) use ($user): void {
                $builder->where('created_by', $user->id)
                    ->orWhere('partner_id', $user->id);
            });
        }

        return $query;
    }

    private function authorizeInvoice(User $user, Invoice $invoice): void
    {
        if ($user->isAdmin()) {
            return;
        }

        abort_unless(
            $invoice->created_by === $user->id || $invoice->partner_id === $user->id,
            403,
        );
    }

    private function authorizeInvoiceMutation(User $user, Invoice $invoice): void
    {
        if ($user->isPartner()) {
            abort_unless($invoice->created_by === $user->id, 403);
        }
    }

    private function routePrefix(User $user): string
    {
        return $user->isAdmin() ? 'admin' : 'partner';
    }
}
