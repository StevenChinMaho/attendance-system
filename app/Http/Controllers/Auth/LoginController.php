<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * 同一組「帳號＋來源 IP」在這段時間內密碼試錯太多次就先擋下來，
     * 不管帳號是否真的存在——沒有這一層，攻擊者可以無限次對著任何
     * 帳號猜密碼直到猜中為止。用「帳號＋IP」而不是只用帳號當 key，
     * 是避免某個使用者自己打錯密碼幾次，就連累到別人從別的地方登入
     * 同一帳號也被鎖住；殘留風險是同一帳號被分散在很多不同 IP 慢慢
     * 猜，這種規模的攻擊已經超出這裡要防的範圍。
     */
    private const MAX_LOGIN_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 60;

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

        $throttleKey = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'username' => "登入嘗試次數過多，請 {$seconds} 秒後再試一次。",
            ]);
        }

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
            RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);

            throw ValidationException::withMessages([
                'username' => '此帳號已被停用，請聯絡管理者。',
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);

            throw ValidationException::withMessages([
                'username' => '帳號或密碼錯誤。',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $request->session()->regenerate();

        Auth::user()->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dashboard'));
    }

    private function throttleKey(Request $request): string
    {
        return Str::lower((string) $request->input('username')).'|'.$request->ip();
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
