<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ContentHubUserController extends Controller
{
    public function index(): View { return view('admin.whatsapp.users', ['users' => User::where('role', 'admin')->orderBy('name')->get()]); }

    public function store(Request $request, WhatsAppAuditService $audit): RedirectResponse
    {
        $data = $this->validated($request, null, true);
        $user = User::create([...$data, 'role' => 'admin', 'is_active' => $request->boolean('is_active', true)]);
        $audit->record($request, 'content_hub.user_created', $user, ['admin_role' => $user->adminRole()]);
        return back()->with('success', 'Pengguna dibuat.');
    }

    public function update(Request $request, User $user, WhatsAppAuditService $audit): RedirectResponse
    {
        abort_unless($user->isAdmin(), 404);
        $data = $this->validated($request, $user);
        $active = $request->boolean('is_active');
        if ($user->adminRole() === 'owner' && ($data['admin_role'] !== 'owner' || ! $active) && User::where('role', 'admin')->where('admin_role', 'owner')->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages(['admin_role' => 'Minimal satu owner aktif harus tetap tersedia.']);
        }
        if (blank($data['password'] ?? null)) unset($data['password']);
        $user->update([...$data, 'is_active' => $active]);
        $audit->record($request, 'content_hub.user_updated', $user, ['admin_role' => $user->adminRole(), 'is_active' => $user->is_active]);
        return back()->with('success', 'Pengguna diperbarui.');
    }

    private function validated(Request $request, ?User $user = null, bool $passwordRequired = false): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users')->ignore($user)],
            'admin_role' => ['required', Rule::in(['owner', 'admin', 'content', 'customer_service', 'viewer'])],
            'password' => [$passwordRequired ? 'required' : 'nullable', 'string', 'min:10', 'max:100'],
        ]);
    }
}
