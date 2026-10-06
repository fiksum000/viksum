<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function loginForm()
    {
        if (session('user_id')) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request, RateLimiter $limiter)
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:1024']);
        $key = Str::lower($data['email']).'|'.$request->ip();

        if ($limiter->tooManyAttempts('billing-login:'.$key, 5)) {
            Audit::log('auth.login_throttled', User::class, null, ['email' => $data['email']]);
            return back()->withInput($request->only('email'))->with('error', 'Terlalu banyak percobaan. Coba lagi dalam satu menit.');
        }

        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            $limiter->hit('billing-login:'.$key, 60);
            Audit::log('auth.login_failed', User::class, $user?->id, ['email' => $data['email']]);
            return back()->withInput($request->only('email'))->with('error', 'Email atau password salah.');
        }

        $limiter->clear('billing-login:'.$key);
        $request->session()->regenerate();
        $request->session()->put('user_id', $user->id);
        Audit::log('auth.login_succeeded', User::class, $user->id);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Audit::log('auth.logout', User::class, $request->session()->get('user_id'));
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
