<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('partner.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('email', mb_strtolower($validated['email']))
            ->where('role', 'partner')
            ->first();

        if (! $user || ! $user->is_active || ! Hash::check($validated['password'], $user->password)) {
            return back()->withErrors(['email' => 'Email atau kata sandi tidak sesuai.'])->onlyInput('email');
        }

        if (! $user->email_verified_at) {
            return back()->withErrors(['email' => 'Akun belum diaktifkan. Gunakan tautan aktivasi dari IzinHukum.']);
        }

        $request->session()->regenerate();
        $request->session()->put([
            'portal_user_id' => $user->id,
            'portal_role' => 'partner',
        ]);
        $user->update(['last_login_at' => now()]);

        return redirect()->route('partner.dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('partner.login');
    }
}
