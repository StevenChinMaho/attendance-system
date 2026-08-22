<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 只在登入當下檢查 is_active 不夠——帳號被管理者停用之後，已經登入的
 * session 依然有效，若不逐一請求重新驗證，停用形同虛設。這個 middleware
 * 掛在 auth 之後，讓「已登入」跟「帳號目前仍是啟用狀態」變成每一次
 * 請求都成立的條件，而不是只在登入那一刻成立。
 *
 * Livewire 會沿用元件第一次渲染時所在路由的 middleware 堆疊套用到後續
 * 的互動請求（wire:click 等），所以掛在 routes/web.php 的 auth 群組上
 * 也能保護到 Livewire 的操作，不只是整頁載入。
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'username' => '此帳號已被停用，請聯絡管理者。',
            ]);
        }

        return $next($request);
    }
}
