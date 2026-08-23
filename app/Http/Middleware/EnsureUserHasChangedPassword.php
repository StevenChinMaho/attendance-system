<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 管理者建立帳號／重置密碼時代打的密碼，帳號本人必須先換成只有自己
 * 知道的新密碼才能繼續使用系統——見 UserManager::createUser()/
 * resetPassword() 設定 must_change_password 的地方，以及
 * App\Livewire\Account\ChangePassword 實際處理變更的頁面。
 *
 * 排除 account.password 跟 logout 這兩個路由，不然使用者會被卡在
 * 「一直被導去改密碼頁面，但連改密碼頁面本身也被導走」的無限迴圈裡，
 * 也要讓他們至少能登出。
 *
 * 只在整頁請求時檢查（跟 EnsureAccountIsActive 同樣的限制——這是一般
 * 自訂 middleware，不在 Livewire 的 PersistentMiddleware allowlist
 * 裡，不會延續到 wire:click 互動請求）：這裡的目的是「把使用者導去該
 * 去的頁面」，不是攔截危險操作，這個限制影響有限。
 */
class EnsureUserHasChangedPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->routeIs('account.password', 'logout')) {
            return redirect()->route('account.password')->withErrors([
                'must_change_password' => '請先設定新密碼才能繼續使用系統。',
            ]);
        }

        return $next($request);
    }
}
