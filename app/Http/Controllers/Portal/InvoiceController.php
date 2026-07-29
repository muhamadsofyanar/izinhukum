<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\MailConfigurator;
use App\Services\BrandingService;
use App\Models\FinancialCategory;
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
            ->withSum('payments as amount_paid', 'amount')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('portal.invoices.index', compact('invoices', 'status', 'user'));
    }

    public function create(Request $request): View
    {
        $user = $request->attributes->get('currentUser');
        $packages = ServicePackage::query()
            ->with('service')
            ->where('is_active', true)
            ->whereHas('service', fn ($query) => $query->where('is_active', true))
            ->orderBy('service_id')
            ->orderBy('sort_order')
            ->get();

        $partners = $user->isAdmin()
            ? User::where('role', 'partner')->where('is_active', true)->orderBy('name')->get()
            : collect();

        $packageOptions = $packages->map(fn (ServicePackage $package): array => [
            'id' => $package->id,
            'name' => $package->name,
            'website' => $package->price,
            'minimum' => $package->minimum_end_user_price ?? 0,
            'partner' => $package->partner_price ?? 0,
        ])->values()->all();

        return view('portal.invoices.create', compact('user', 'packages', 'partners', 'packageOptions'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->attributes->get('currentUser');
        $recipientType = $user->isAdmin() ? $request->input('recipient_type', 'end_user') : 'end_user';

        $rules = [
            'recipient_type' => ['nullable', Rule::in(['partner', 'end_user'])],
            'partner_id' => ['nullable', 'exists:users,id'],
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
        ];
        $validated = $request->validate($rules);

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
                throw ValidationException::withMessages(['partner_id' => 'Pilih mitra yang aktif.']);
            }
        } elseif (empty($validated['recipient_name'])) {
            throw ValidationException::withMessages(['recipient_name' => 'Nama penerima wajib diisi.']);
        }

        $packageIds = collect($validated['items'])->pluck('service_package_id')->unique();
        $packages = ServicePackage::with('service')->whereIn('id', $packageIds)->get()->keyBy('id');
        $preparedItems = [];
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
                        "items.{$index}.unit_price" => 'Harga jual tidak boleh di bawah '.('Rp'.number_format($minimum, 0, ',', '.')).'.',
                    ]);
                }
            }

            $lineTotal = $unitPrice * $quantity;
            $subtotal += $lineTotal;
            $preparedItems[] = [
                'service_package_id' => $package->id,
                'description' => trim((string) ($item['description'] ?? '')) ?: $package->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'partner_cost' => $package->partner_price,
                'minimum_end_user_price' => $package->minimum_end_user_price,
                'line_total' => $lineTotal,
            ];
        }

        $invoice = DB::transaction(function () use (
            $user,
            $recipientType,
            $partner,
            $validated,
            $preparedItems,
            $subtotal,
        ): Invoice {
            $invoice = Invoice::create([
                'invoice_number' => 'PENDING-'.Str::uuid(),
                'public_token' => Str::random(56),
                'created_by' => $user->id,
                'partner_id' => $partner?->id,
                'recipient_type' => $recipientType,
                'recipient_name' => $partner?->name ?? $validated['recipient_name'],
                'recipient_company' => $partner?->company_name ?? ($validated['recipient_company'] ?? null),
                'recipient_email' => $partner?->email ?? ($validated['recipient_email'] ?? null),
                'recipient_phone' => $partner?->phone ?? ($validated['recipient_phone'] ?? null),
                'recipient_address' => $partner?->address ?? ($validated['recipient_address'] ?? null),
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'] ?? null,
                'status' => 'draft',
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'notes' => $validated['notes'] ?? null,
            ]);

            $invoice->update([
                'invoice_number' => sprintf('INV/IH/%s/%05d', now()->format('Ym'), $invoice->id),
            ]);
            $invoice->items()->createMany($preparedItems);

            return $invoice;
        });

        return redirect()
            ->route($this->routePrefix($user).'.invoices.show', $invoice)
            ->with('success', 'Invoice berhasil dibuat.');
    }

    public function show(
        Request $request,
        Invoice $invoice,
        BrandingService $brandingService,
    ): View
    {
        $user = $request->attributes->get('currentUser');
        $this->authorizeInvoice($user, $invoice);
        $invoice->load(['items.package.service', 'creator', 'partner', 'payments.creator', 'payments.category']);

        return view('portal.invoices.show', [
            'invoice' => $invoice,
            'user' => $user,
            'branding' => $brandingService->document(),
            'incomeCategories' => $user->isAdmin()
                ? FinancialCategory::query()->where('type', 'income')->where('is_active', true)->orderBy('name')->get()
                : collect(),
        ]);
    }

    public function updateStatus(Request $request, Invoice $invoice): RedirectResponse
    {
        $user = $request->attributes->get('currentUser');
        $this->authorizeInvoice($user, $invoice);

        if ($user->isPartner() && $invoice->created_by !== $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['draft', 'sent', 'cancelled'])],
        ]);

        if ($invoice->payments()->exists()) {
            throw ValidationException::withMessages([
                'status' => 'Status invoice yang sudah memiliki pembayaran ditentukan otomatis oleh total pembayaran.',
            ]);
        }

        $updates = ['status' => $validated['status']];
        if ($validated['status'] === 'sent' && ! $invoice->sent_at) {
            $updates['sent_at'] = now();
        }
        $invoice->update($updates);

        return back()->with('success', 'Status invoice diperbarui.');
    }

    public function send(
        Request $request,
        Invoice $invoice,
        MailConfigurator $mailConfigurator,
    ): RedirectResponse {
        $user = $request->attributes->get('currentUser');
        $this->authorizeInvoice($user, $invoice);

        if ($user->isPartner() && $invoice->created_by !== $user->id) {
            abort(403);
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

            return back()->withErrors(['email' => 'Email gagal dikirim. Periksa konfigurasi SMTP pada panel admin.']);
        }

        return back()->with('success', 'Invoice berhasil dikirim ke '.$invoice->recipient_email.'.');
    }

    private function visibleInvoices(User $user): Builder
    {
        $query = Invoice::query();

        if ($user->isPartner()) {
            $query->where(function ($builder) use ($user): void {
                $builder->where('created_by', $user->id)->orWhere('partner_id', $user->id);
            });
        }

        return $query;
    }

    private function authorizeInvoice(User $user, Invoice $invoice): void
    {
        if ($user->isAdmin()) {
            return;
        }

        abort_unless($invoice->created_by === $user->id || $invoice->partner_id === $user->id, 403);
    }

    private function routePrefix(User $user): string
    {
        return $user->isAdmin() ? 'admin' : 'partner';
    }
}
