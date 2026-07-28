<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('portal.profile', ['user' => $request->attributes->get('currentUser')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->attributes->get('currentUser');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'tax_id' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:1000'],
            'bank_name' => ['nullable', 'string', 'max:160'],
            'bank_account_number' => ['nullable', 'string', 'max:64'],
            'bank_account_name' => ['nullable', 'string', 'max:160'],
            'current_password' => ['nullable', 'string'],
            'password' => ['nullable', 'string', 'min:10', 'confirmed'],
        ]);

        if (! empty($validated['password'])) {
            if (empty($validated['current_password']) || ! Hash::check($validated['current_password'], $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'Kata sandi saat ini tidak sesuai.',
                ]);
            }

            $validated['password'] = $validated['password'];
        } else {
            unset($validated['password']);
        }

        unset($validated['current_password'], $validated['password_confirmation']);
        $validated['email'] = mb_strtolower($validated['email']);
        $user->update($validated);

        return back()->with('success', 'Profil berhasil diperbarui.');
    }
}
