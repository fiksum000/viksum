<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const ROLES = ['super_admin', 'admin', 'operator', 'finance', 'technician'];

    public function index(Request $request)
    {
        return view('users.index', ['users' => User::orderBy('name')->paginate(50), 'assignableRoles' => $this->assignableRoles($request)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:255|unique:users,email',
            'role' => ['required', Rule::in($this->assignableRoles($request))],
            'password' => 'required|string|min:12|confirmed',
        ]);
        $user = User::create($data);
        Audit::log('user.created', User::class, $user->id, ['role' => $user->role]);

        return back()->with('success', 'Pengguna dibuat. Berikan password awal melalui kanal aman.');
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in($this->assignableRoles($request, $user))],
            'password' => 'nullable|string|min:12|confirmed',
        ]);
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        if ($user->role === 'super_admin' && $data['role'] !== 'super_admin' && User::where('role', 'super_admin')->count() <= 1) {
            return back()->with('error', 'Tidak dapat menurunkan satu-satunya Super Admin.');
        }

        $user->update($data);
        Audit::log('user.updated', User::class, $user->id, ['role' => $user->role]);
        return back()->with('success', 'Pengguna diperbarui.');
    }

    public function destroy(Request $request, User $user)
    {
        if ((int) $request->session()->get('user_id') === $user->id) {
            return back()->with('error', 'Akun yang sedang digunakan tidak dapat dihapus.');
        }
        if ($user->role === 'super_admin' && User::where('role', 'super_admin')->count() <= 1) {
            return back()->with('error', 'Tidak dapat menghapus satu-satunya Super Admin.');
        }

        Audit::log('user.deleted', User::class, $user->id, ['role' => $user->role]);
        $user->delete();
        return back()->with('success', 'Pengguna dihapus.');
    }

    private function assignableRoles(Request $request, ?User $target = null): array
    {
        $actor = $request->attributes->get('billing_user');
        if ($actor?->role === 'super_admin') {
            return self::ROLES;
        }

        $roles = ['admin', 'operator', 'finance', 'technician'];
        if ($target?->role === 'super_admin') {
            return [];
        }
        return $roles;
    }
}
