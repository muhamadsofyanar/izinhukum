<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class ActivationController extends Controller
{
    public function create(string $token): View
    {
        $user = $this->findUser($token);

        return view('partner.activate', compact('user', 'token'));
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $user = $this->findUser($token);
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        $user->update([
            'password' => $validated['password'],
            'activation_token' => null,
            'activation_expires_at' => null,
            'email_verified_at' => now(),
            'must_change_password' => false,
        ]);

        return redirect()->route('partner.login')->with('success', 'Akun mitra berhasil diaktifkan. Silakan masuk.');
    }

    private function findUser(string $token): User
    {
        $user = User::query()
            ->where('role', 'partner')
            ->where('activation_token', hash('sha256', $token))
            ->where('activation_expires_at', '>', now())
            ->first();

        abort_unless($user, 404, 'Tautan aktivasi tidak valid atau sudah kedaluwarsa.');

        return $user;
    }
}
