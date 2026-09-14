<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InquiryController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status');
        $search = trim((string) $request->query('q'));

        $inquiries = Inquiry::query()
            ->with(['package.service', 'referredByPartner', 'serviceOrder'])
            ->when($status !== '', fn ($query) => $query->where('disposition', $status))
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
    ): RedirectResponse {
        $validated = $request->validate([
            'disposition' => ['required', Rule::in(array_keys(Inquiry::DISPOSITIONS))],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($validated['disposition'] === Inquiry::DISPOSITION_WON && ! $inquiry->serviceOrder()->exists()) {
            throw ValidationException::withMessages([
                'disposition' => 'Gunakan status Terverifikasi, lalu klik Jadikan proyek.',
            ]);
        }
        if ($inquiry->serviceOrder()->exists() && $validated['disposition'] !== Inquiry::DISPOSITION_WON) {
            throw ValidationException::withMessages([
                'disposition' => 'Lead ini sudah menjadi proyek. Ubah status pekerjaan dari halaman proyek.',
            ]);
        }
        $actor = $request->attributes->get('currentUser');
        $before = $inquiry->only(['disposition', 'review_note']);
        $legacyStatus = match ($validated['disposition']) {
            Inquiry::DISPOSITION_NEW => 'baru',
            Inquiry::DISPOSITION_CONTACTED,
            Inquiry::DISPOSITION_QUALIFIED,
            Inquiry::DISPOSITION_PROPOSAL_SENT => 'dihubungi',
            Inquiry::DISPOSITION_WON => 'proses',
            Inquiry::DISPOSITION_LOST,
            Inquiry::DISPOSITION_DUPLICATE,
            Inquiry::DISPOSITION_SPAM => 'batal',
        };

        $inquiry->update([
            ...$validated,
            'status' => $legacyStatus,
            'reviewed_by' => $actor?->id,
            'reviewed_at' => now(),
        ]);

        AuditLog::query()->create([
            'user_id' => $actor?->id,
            'action' => 'inquiry_reviewed',
            'subject_type' => Inquiry::class,
            'subject_id' => $inquiry->id,
            'metadata' => ['before' => $before, 'after' => $inquiry->only(['disposition', 'review_note'])],
            'ip_address' => $request->ip(),
        ]);

        return back()->with('success', 'Hasil pemeriksaan lead berhasil disimpan.');
    }
}
