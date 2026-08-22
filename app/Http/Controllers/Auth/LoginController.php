<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * Show the login form.
     *
     * This is the only page an unauthenticated visitor is ever allowed to see.
     * no-store keeps browsers from serving a stale copy of this page from
     * cache/back-forward-cache after the user has since logged in — without
     * this, "back button" or a bookmark can show an already-authenticated
     * user their own old login form.
     */
    public function create(): Response
    {
        return response(view('auth.login'))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    /**
     * Handle a login attempt.
     *
     * Deliberately not behind the `guest` middleware (see routes/web.php):
     * if a stale cached login form still gets submitted while a *different*
     * account is currently logged in, `guest` would silently redirect the
     * request away before it ever reached here, discarding the new
     * credentials without any feedback and leaving the old session in
     * place. Handling the account switch explicitly here means a login
     * attempt always does what it looks like it does.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::check()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

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
