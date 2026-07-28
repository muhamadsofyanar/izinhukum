<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('admin.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $configuredEmail = (string) config('admin.email');
        $configuredPassword = (string) config('admin.password');

        if ($configuredEmail === '' || $configuredPassword === '') {
            return back()->withErrors(['email' => 'Akun admin belum dikonfigurasi pada server.']);
        }

        $valid = hash_equals($configuredEmail, $validated['email'])
            && hash_equals($configuredPassword, $validated['password']);

        if (! $valid) {
            return back()->withErrors(['email' => 'Email atau kata sandi tidak sesuai.'])->onlyInput('email');
        }

        $request->session()->regenerate();
        $request->session()->put('admin_authenticated', true);

        return redirect()->route('admin.dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
