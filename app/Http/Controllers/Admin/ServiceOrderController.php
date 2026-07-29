<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderDocument;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\ServiceOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServiceOrderController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $status = (string) $request->query('status');
        $paymentStatus = (string) $request->query('payment_status');
        $priority = (string) $request->query('priority');

        $orders = ServiceOrder::query()
            ->with(['package.service', 'assignee', 'referredByPartner'])
            ->withCount(['invoices', 'documents'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('order_number', 'like', '%'.$search.'%')
                        ->orWhere('customer_name', 'like', '%'.$search.'%')
                        ->orWhere('customer_company', 'like', '%'.$search.'%')
                        ->orWhere('customer_phone', 'like', '%'.$search.'%')
                        ->orWhere('customer_email', 'like', '%'.$search.'%');
                });
            })
            ->when(array_key_exists($status, ServiceOrder::STATUSES), fn ($query) => $query->where('status', $status))
            ->when(in_array($paymentStatus, ['unpaid', 'partial', 'paid'], true), fn ($query) => $query->where('payment_status', $paymentStatus))
            ->when(array_key_exists($priority, ServiceOrder::PRIORITIES), fn ($query) => $query->where('priority', $priority))
            ->orderByRaw("CASE WHEN status IN ('completed', 'cancelled') THEN 1 ELSE 0 END")
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $summary = [
            'open' => ServiceOrder::query()->open()->count(),
            'overdue' => ServiceOrder::query()->open()->whereNotNull('due_at')->where('due_at', '<', now())->count(),
            'awaiting_payment' => ServiceOrder::query()->where('payment_status', 'unpaid')->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'completed_this_month' => ServiceOrder::query()->where('status', 'completed')->whereBetween('completed_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
        ];

        return view('admin.orders.index', compact(
            'orders',
            'summary',
            'search',
            'status',
            'paymentStatus',
            'priority',
        ));
    }

    public function create(): View
    {
        return view('admin.orders.create', [
            'packages' => ServicePackage::query()->with('service')->where('is_active', true)->orderBy('service_id')->orderBy('sort_order')->get(),
            'partners' => User::query()->where('role', 'partner')->where('is_active', true)->orderBy('name')->get(),
            'admins' => User::query()->where('role', 'admin')->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, ServiceOrderService $orders): RedirectResponse
    {
        $data = $request->validate([
            'service_package_id' => ['nullable', 'exists:service_packages,id'],
            'referred_by_partner_id' => ['nullable', 'exists:users,id'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'title' => ['required', 'string', 'max:180'],
            'customer_name' => ['required', 'string', 'max:160'],
            'customer_email' => ['nullable', 'email', 'max:160'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'customer_company' => ['nullable', 'string', 'max:180'],
            'customer_city' => ['nullable', 'string', 'max:120'],
            'customer_address' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(array_keys(ServiceOrder::STATUSES))],
            'priority' => ['required', Rule::in(array_keys(ServiceOrder::PRIORITIES))],
            'due_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:10000'],
        ]);

        if (! empty($data['referred_by_partner_id'])) {
            $partner = User::query()->whereKey($data['referred_by_partner_id'])->where('role', 'partner')->where('is_active', true)->firstOrFail();
            $data['referral_code'] = $partner->partner_code;
        }

        $order = $orders->createManual($data, $request->attributes->get('currentUser'));

        return redirect()->route('admin.orders.show', $order)->with('success', 'Order layanan berhasil dibuat.');
    }

    public function fromInquiry(Request $request, Inquiry $inquiry, ServiceOrderService $orders): RedirectResponse
    {
        $order = $orders->createFromInquiry($inquiry, $request->attributes->get('currentUser'));

        return redirect()->route('admin.orders.show', $order)->with('success', 'Permintaan berhasil disinkronkan menjadi order.');
    }

    public function show(ServiceOrder $order): View
    {
        $order->load([
            'inquiry.package.service',
            'package.service',
            'referredByPartner',
            'assignee',
            'creator',
            'invoices.payments',
            'events.actor',
            'documents.uploader',
        ]);

        return view('admin.orders.show', [
            'order' => $order,
            'admins' => User::query()->where('role', 'admin')->where('is_active', true)->orderBy('name')->get(),
            'availableInvoices' => Invoice::query()->whereNull('service_order_id')->latest()->limit(100)->get(),
        ]);
    }

    public function update(Request $request, ServiceOrder $order, ServiceOrderService $orders): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(ServiceOrder::STATUSES))],
            'payment_status' => ['nullable', Rule::in(['unpaid', 'partial', 'paid'])],
            'priority' => ['required', Rule::in(array_keys(ServiceOrder::PRIORITIES))],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'progress' => ['required', 'integer', 'min:0', 'max:100'],
            'due_at' => ['nullable', 'date'],
            'customer_name' => ['required', 'string', 'max:160'],
            'customer_email' => ['nullable', 'email', 'max:160'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'customer_company' => ['nullable', 'string', 'max:180'],
            'customer_city' => ['nullable', 'string', 'max:120'],
            'customer_address' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:10000'],
            'checklist_labels' => ['nullable', 'array', 'max:30'],
            'checklist_labels.*' => ['nullable', 'string', 'max:180'],
            'checklist_done' => ['nullable', 'array'],
        ]);

        $labels = collect($data['checklist_labels'] ?? []);
        $done = (array) ($data['checklist_done'] ?? []);
        $data['checklist'] = $labels
            ->map(fn ($label, $index): array => ['label' => trim((string) $label), 'done' => (string) ($done[$index] ?? '0') === '1'])
            ->filter(fn (array $item): bool => $item['label'] !== '')
            ->values()
            ->all();
        unset($data['checklist_labels'], $data['checklist_done'], $data['payment_status']);

        $orders->update($order, $data, $request->attributes->get('currentUser'));

        return back()->with('success', 'Order berhasil diperbarui.');
    }

    public function resetPortalToken(Request $request, ServiceOrder $order, ServiceOrderService $orders): RedirectResponse
    {
        $order->update(['public_token' => Str::random(64)]);
        $orders->event($order, 'portal_token_reset', 'Tautan portal pelanggan diperbarui', null, $request->attributes->get('currentUser'));

        return back()->with('success', 'Tautan portal lama tidak berlaku. Tautan baru sudah dibuat.');
    }

    public function sync(Request $request, ServiceOrderService $orders): RedirectResponse
    {
        $result = $orders->backfill();

        return back()->with('success', sprintf(
            'Sinkronisasi selesai. %d permintaan diperiksa, %d order dibuat, %d invoice lama diproses.',
            $result['checked'],
            $result['created'],
            $result['linked_invoices'],
        ));
    }

    public function attachInvoice(Request $request, ServiceOrder $order, ServiceOrderService $orders): RedirectResponse
    {
        $data = $request->validate(['invoice_id' => ['required', 'exists:invoices,id']]);
        $invoice = Invoice::query()->findOrFail($data['invoice_id']);

        if ($invoice->service_order_id && $invoice->service_order_id !== $order->id) {
            return back()->withErrors(['invoice_id' => 'Invoice sudah terhubung ke order lain.']);
        }

        $invoice->update(['service_order_id' => $order->id]);
        $orders->syncPaymentStatus($order);
        $orders->event(
            $order,
            'invoice_attached',
            'Invoice '.$invoice->invoice_number.' dihubungkan',
            null,
            $request->attributes->get('currentUser'),
            ['invoice_id' => $invoice->id],
        );

        return back()->with('success', 'Invoice berhasil dihubungkan ke order.');
    }

    public function uploadDocument(Request $request, ServiceOrder $order, ServiceOrderService $orders): RedirectResponse
    {
        $data = $request->validate([
            'document' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx'],
            'name' => ['nullable', 'string', 'max:180'],
            'category' => ['required', Rule::in(['supporting', 'draft', 'deliverable', 'contract', 'other'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $file = $request->file('document');
        $path = $file->store('orders/'.$order->id.'/admin', 'local');
        $document = $order->documents()->create([
            'uploaded_by' => $request->attributes->get('currentUser')->id,
            'uploaded_by_type' => 'admin',
            'category' => $data['category'],
            'name' => $data['name'] ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_name' => $file->getClientOriginalName(),
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'status' => 'approved',
            'notes' => $data['notes'] ?? null,
        ]);

        $orders->event(
            $order,
            'document_uploaded',
            'Dokumen ditambahkan: '.$document->name,
            null,
            $request->attributes->get('currentUser'),
        );

        return back()->with('success', 'Dokumen berhasil disimpan pada storage privat.');
    }

    public function downloadDocument(ServiceOrder $order, ServiceOrderDocument $document): StreamedResponse
    {
        abort_unless($document->service_order_id === $order->id, 404);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }

    public function deleteDocument(Request $request, ServiceOrder $order, ServiceOrderDocument $document, ServiceOrderService $orders): RedirectResponse
    {
        abort_unless($document->service_order_id === $order->id, 404);
        Storage::disk($document->disk)->delete($document->path);
        $name = $document->name;
        $document->delete();
        $orders->event($order, 'document_deleted', 'Dokumen dihapus: '.$name, null, $request->attributes->get('currentUser'));

        return back()->with('success', 'Dokumen berhasil dihapus.');
    }
}
