<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    /**
     * Show the login form.
     *
     * This is the only page an unauthenticated visitor is ever allowed to see.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle a login attempt.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Accounts are provisioned by an administrator only; a disabled
        // account must be rejected before we even attempt authentication,
        // otherwise a stale-but-correct password would still let it in.
        $user = User::where('username', $credentials['username'])->first();

        if ($user && ! $user->is_active) {
            throw ValidationException::withMessages([
                'username' => '此帳號已被停用，請聯絡管理者。',
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'username' => '帳號或密碼錯誤。',
            ]);
        }

        $request->session()->regenerate();

        Auth::user()->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Log the user out.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
