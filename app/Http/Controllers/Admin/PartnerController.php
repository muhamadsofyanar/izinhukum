<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PartnerActivationMail;
use App\Models\PartnerApplication;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\MailConfigurator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PartnerController extends Controller
{
    public function index(): View
    {
        return view('admin.partners', [
            'partners' => User::where('role', 'partner')->latest()->paginate(20, ['*'], 'partners'),
            'applications' => PartnerApplication::latest()->paginate(15, ['*'], 'applications'),
        ]);
    }

    public function store(Request $request, MailConfigurator $mailConfigurator): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:32'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:120'],
        ]);

        [$partner, $activationUrl] = $this->createPartner($validated);
        $this->sendActivation($partner, $activationUrl, $mailConfigurator);

        return back()
            ->with('success', 'Akun mitra dibuat. Tautan aktivasi tersedia di bawah.')
            ->with('activation_url', $activationUrl);
    }

    public function approve(
        Request $request,
        PartnerApplication $application,
        MailConfigurator $mailConfigurator,
    ): RedirectResponse {
        if ($application->status !== 'pending') {
            return back()->withErrors(['partner' => 'Permohonan ini sudah ditinjau.']);
        }

        if (User::where('email', mb_strtolower($application->email))->exists()) {
            return back()->withErrors(['partner' => 'Email tersebut sudah digunakan akun lain.']);
        }

        [$partner, $activationUrl] = DB::transaction(function () use ($application, $request): array {
            [$partner, $activationUrl] = $this->createPartner([
                'name' => $application->name,
                'email' => $application->email,
                'phone' => $application->phone,
                'company_name' => $application->company_name,
                'tax_id' => $application->tax_id,
                'city' => $application->city,
                'address' => $application->address,
            ]);

            $application->update([
                'status' => 'approved',
                'reviewed_by' => $request->attributes->get('currentUser')->id,
                'reviewed_at' => now(),
            ]);

            return [$partner, $activationUrl];
        });

        $this->sendActivation($partner, $activationUrl, $mailConfigurator);

        return back()
            ->with('success', 'Permohonan disetujui dan akun mitra dibuat.')
            ->with('activation_url', $activationUrl);
    }

    public function reject(Request $request, PartnerApplication $application): RedirectResponse
    {
        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $application->update([
            'status' => 'rejected',
            'admin_notes' => $validated['admin_notes'] ?? null,
            'reviewed_by' => $request->attributes->get('currentUser')->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Permohonan mitra ditolak.');
    }

    public function toggle(User $partner): RedirectResponse
    {
        abort_unless($partner->role === 'partner', 404);
        $partner->update(['is_active' => ! $partner->is_active]);

        return back()->with('success', 'Status akun mitra diperbarui.');
    }

    public function update(Request $request, User $partner): RedirectResponse
    {
        abort_unless($partner->role === 'partner', 404);
        $data = $request->validate([
            'partner_level' => ['required', 'in:starter,professional,priority'],
            'account_status' => ['required', 'in:pending,active,suspended,inactive'],
        ]);
        $partner->update([
            ...$data,
            'is_active' => $data['account_status'] === 'active',
        ]);
        AuditLog::create([
            'user_id' => $request->attributes->get('currentUser')->id,
            'action' => 'partner.updated',
            'subject_type' => User::class,
            'subject_id' => $partner->id,
            'metadata' => $data,
            'ip_address' => $request->ip(),
        ]);
        return back()->with('success', 'Level dan status mitra diperbarui.');
    }

    private function createPartner(array $data): array
    {
        $token = Str::random(48);
        $partner = User::create([
            ...$data,
            'email' => mb_strtolower($data['email']),
            'role' => 'partner',
            'partner_code' => $this->nextPartnerCode(),
            'password' => Str::random(40),
            'activation_token' => hash('sha256', $token),
            'activation_expires_at' => now()->addDays(7),
            'is_active' => true,
        ]);

        return [$partner, route('partner.activate', $token)];
    }

    private function nextPartnerCode(): string
    {
        do {
            $code = 'LEG-'.now()->format('ym').'-'.Str::upper(Str::random(4));
        } while (User::where('partner_code', $code)->exists());

        return $code;
    }

    private function sendActivation(User $partner, string $activationUrl, MailConfigurator $mailConfigurator): void
    {
        try {
            $mailConfigurator->apply();
            Mail::to($partner->email)->send(new PartnerActivationMail($partner, $activationUrl));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
