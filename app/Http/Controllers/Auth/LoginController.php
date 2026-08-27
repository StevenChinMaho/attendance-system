<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLog;
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

            // 被限制擋下來這件事本身就是訊號：正常使用者不會連錯五次，
            // 短時間內大量出現代表有人在猜某個帳號的密碼。
            AuditLog::auth('登入被頻率限制擋下', $this->accountFor($credentials['username']), [
                'username' => $this->loggableUsername($credentials['username']),
                'retry_after_seconds' => $seconds,
            ]);

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

            AuditLog::auth('登入失敗：帳號已停用', $user, [
                'username' => $user->username,
            ]);

            throw ValidationException::withMessages([
                'username' => '此帳號已被停用，請聯絡管理者。',
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);

            AuditLog::auth(
                $user ? '登入失敗：密碼錯誤' : '登入失敗：帳號不存在',
                $user,
                ['username' => $this->loggableUsername($credentials['username'])],
            );

            throw ValidationException::withMessages([
                'username' => '帳號或密碼錯誤。',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $request->session()->regenerate();

        Auth::user()->forceFill(['last_login_at' => now()])->save();

        // users.last_login_at 只留「最後一次」，看不出歷程。稽核紀錄
        // 才答得出「這個帳號這學期在什麼時間、從哪些位置登入過」——
        // 那正是判斷帳號是不是被別人拿去用的主要線索。
        AuditLog::auth('登入成功', Auth::user(), [
            'username' => Auth::user()->username,
            'remember' => $request->boolean('remember'),
        ]);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * 只有在這個帳號真的存在時才把輸入的字串記進稽核紀錄。
     *
     * 理由不是隱私而是安全：使用者把密碼打進帳號欄是很常見的手誤，
     * 而那個字串幾乎不可能剛好等於某個既有帳號名稱——所以「存在才記」
     * 這個規則，剛好可以擋掉「有人的密碼以明文躺在管理者看得到的稽核
     * 紀錄裡」這種意外。帳號不存在時仍然會留下 IP 與時間，「有人在亂試
     * 帳號」這件事還是看得出來。
     */
    private function loggableUsername(string $username): ?string
    {
        return User::where('username', $username)->exists() ? $username : null;
    }

    private function accountFor(string $username): ?User
    {
        return User::where('username', $username)->first();
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
        // 一定要在 logout() 之前記，之後 Auth::user() 就是 null 了。
        if (Auth::check()) {
            AuditLog::auth('登出', Auth::user(), ['username' => Auth::user()->username]);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
