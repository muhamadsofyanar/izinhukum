<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailSender;
use App\Models\SystemSetting;
use App\Services\MailConfigurator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MailSettingController extends Controller
{
    public function edit(): View
    {
        return view('admin.mail-settings', [
            'settings' => [
                'host' => SystemSetting::valueFor('mail.host', config('mail.mailers.smtp.host', 'smtp.mailketing.id')),
                'port' => SystemSetting::valueFor('mail.port', config('mail.mailers.smtp.port', 587)),
                'encryption' => SystemSetting::valueFor(
                    'mail.encryption',
                    config('mail.mailers.smtp.scheme') === 'smtps' ? 'ssl' : 'tls',
                ),
                'username' => SystemSetting::valueFor('mail.username', config('mail.mailers.smtp.username')),
                'has_password' => (bool) SystemSetting::valueFor('mail.password', config('mail.mailers.smtp.password')),
            ],
            'senders' => EmailSender::orderByDesc('is_default')->orderBy('email')->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
        ]);

        SystemSetting::storeValue('mail.host', $validated['host']);
        SystemSetting::storeValue('mail.port', (string) $validated['port']);
        SystemSetting::storeValue('mail.encryption', $validated['encryption']);
        SystemSetting::storeValue('mail.username', $validated['username']);
        if (! empty($validated['password'])) {
            SystemSetting::storeValue('mail.password', $validated['password'], true);
        }

        return back()->with('success', 'Konfigurasi SMTP disimpan.');
    }

    public function test(Request $request, MailConfigurator $mailConfigurator): RedirectResponse
    {
        $validated = $request->validate([
            'test_email' => ['required', 'email', 'max:160'],
        ]);

        try {
            $mailConfigurator->apply();
            Mail::raw(
                'Email pengujian SMTP IzinHukum berhasil dikirim pada '.now()->format('d-m-Y H:i').' WIB.',
                fn ($message) => $message->to($validated['test_email'])->subject('Tes SMTP IzinHukum'),
            );
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['smtp' => 'Pengiriman gagal. Periksa host, port, username, password, dan sender Mailketing.']);
        }

        return back()->with('success', 'Email pengujian berhasil dikirim.');
    }

    public function storeSender(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:160', 'unique:email_senders,email'],
            'type' => ['required', Rule::in(['simple', 'whitelabel'])],
            'status' => ['required', Rule::in(['pending', 'approved', 'blocked'])],
            'is_default' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $validated): void {
            if ($request->boolean('is_default')) {
                EmailSender::query()->update(['is_default' => false]);
            }
            EmailSender::create([
                ...$validated,
                'email' => mb_strtolower($validated['email']),
                'is_default' => $request->boolean('is_default'),
                'is_active' => true,
            ]);
        });

        return back()->with('success', 'Sender email ditambahkan.');
    }

    public function updateSender(Request $request, EmailSender $sender): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:160', Rule::unique('email_senders', 'email')->ignore($sender->id)],
            'type' => ['required', Rule::in(['simple', 'whitelabel'])],
            'status' => ['required', Rule::in(['pending', 'approved', 'blocked'])],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $sender, $validated): void {
            if ($request->boolean('is_default')) {
                EmailSender::where('id', '!=', $sender->id)->update(['is_default' => false]);
            }
            $sender->update([
                ...$validated,
                'email' => mb_strtolower($validated['email']),
                'is_default' => $request->boolean('is_default'),
                'is_active' => $request->boolean('is_active'),
            ]);
        });

        return back()->with('success', 'Sender email diperbarui.');
    }
}
