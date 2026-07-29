<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Services\ServiceOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InquiryController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status');
        $search = trim((string) $request->query('q'));

        $inquiries = Inquiry::query()
            ->with(['package.service', 'referredByPartner', 'serviceOrder'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('reference', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('company_name', 'like', '%'.$search.'%');
                });
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.inquiries', compact('inquiries', 'status', 'search'));
    }

    public function update(
        Request $request,
        Inquiry $inquiry,
        ServiceOrderService $orders,
    ): RedirectResponse {
        $validated = $request->validate([
            'status' => ['required', 'in:baru,dihubungi,proses,selesai,batal'],
        ]);

        $inquiry->update($validated);
        $order = $orders->createFromInquiry($inquiry, $request->attributes->get('currentUser'));
        $orderStatus = match ($validated['status']) {
            'dihubungi' => 'waiting_approval',
            'proses' => 'processing',
            'selesai' => 'completed',
            'batal' => 'cancelled',
            default => 'lead',
        };

        if ($order->status !== $orderStatus) {
            $orders->update($order, [
                'status' => $orderStatus,
                'progress' => $orders->progressForStatus($orderStatus),
            ], $request->attributes->get('currentUser'));
        }

        return back()->with('success', 'Status permintaan dan order berhasil diperbarui.');
    }
}
