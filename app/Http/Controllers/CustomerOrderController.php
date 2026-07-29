<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderDocument;
use App\Services\FeatureFlagService;
use App\Services\ServiceOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerOrderController extends Controller
{
    public function show(string $token, FeatureFlagService $features): View
    {
        $order = $this->findOrder($token);
        $order->load([
            'package.service',
            'inquiry.package.service',
            'invoices' => fn ($query) => $query->where('status', '!=', 'cancelled')->with(['payments' => fn ($payment) => $payment->active()]),
            'events' => fn ($query) => $query->whereIn('event_type', [
                'order_created',
                'status_changed',
                'customer_note',
                'document_uploaded',
                'invoice_attached',
            ])->orderByDesc('occurred_at'),
            'documents' => fn ($query) => $query
                ->where(fn ($nested) => $nested->where('uploaded_by_type', 'customer')->orWhere('status', 'approved'))
                ->latest(),
        ]);

        return view('customer-orders.show', [
            'order' => $order,
            'documentUploadEnabled' => $features->enabled('customer_document_upload'),
        ]);
    }

    public function note(Request $request, string $token, ServiceOrderService $orders): RedirectResponse
    {
        $order = $this->findOrder($token);
        $data = $request->validate(['message' => ['required', 'string', 'min:2', 'max:2000']]);

        $orders->event(
            $order,
            'customer_note',
            'Pelanggan mengirim catatan',
            $data['message'],
            null,
            [],
            'customer',
        );

        return back()->with('success', 'Catatan Anda berhasil dikirim.');
    }

    public function upload(
        Request $request,
        string $token,
        ServiceOrderService $orders,
        FeatureFlagService $features,
    ): RedirectResponse {
        abort_unless($features->enabled('customer_document_upload'), 404);
        $order = $this->findOrder($token);
        $data = $request->validate([
            'document' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
            'name' => ['nullable', 'string', 'max:180'],
            'category' => ['required', Rule::in(['identity', 'company', 'supporting', 'other'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $file = $request->file('document');
        $path = $file->store('orders/'.$order->id.'/customer', 'local');
        $document = $order->documents()->create([
            'uploaded_by_type' => 'customer',
            'category' => $data['category'],
            'name' => $data['name'] ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_name' => $file->getClientOriginalName(),
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'status' => 'received',
            'notes' => $data['notes'] ?? null,
        ]);

        $orders->event(
            $order,
            'document_uploaded',
            'Pelanggan mengunggah dokumen: '.$document->name,
            null,
            null,
            ['document_id' => $document->id],
            'customer',
        );

        return back()->with('success', 'Dokumen berhasil diunggah dan tersimpan secara privat.');
    }

    public function download(string $token, ServiceOrderDocument $document): StreamedResponse
    {
        $order = $this->findOrder($token);
        abort_unless($document->service_order_id === $order->id, 404);
        abort_unless($document->uploaded_by_type === 'customer' || $document->status === 'approved', 403);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }

    private function findOrder(string $token): ServiceOrder
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1, 404);

        return ServiceOrder::query()->where('public_token', $token)->firstOrFail();
    }
}
