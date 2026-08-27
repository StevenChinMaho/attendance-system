<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // spatie/laravel-permission 不會自動註冊這些別名，Laravel 11+ 的
        // bootstrap 結構要手動掛上，路由才能用 role:xxx / permission:xxx。
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // 正式環境的請求鏈是 Cloudflare edge → cloudflared → nginx → 這裡，
        // 中間隔了三層代理。不設定這個的話 Laravel 會把「最後一跳」當成
        // 客戶端，造成三個實際的問題（不是理論上的）：
        //
        // 1. LoginController::throttleKey() 是 `username|$request->ip()`，
        //    ip() 會變成 nginx 容器那個固定的內網 IP，全校共用同一組
        //    key——任何人在某個帳號上打錯 5 次密碼，就會把那個帳號從
        //    「所有地點」一起鎖住。這是系統唯一的暴力破解防線。
        // 2. $request->isSecure() 會是 false（看不到 X-Forwarded-Proto），
        //    session cookie 的 secure 旗標在 config/session.php 是
        //    env('SESSION_SECURE_COOKIE')，沒指定時交由這個判斷決定。
        // 3. route()/url() 產生出來的絕對網址會是 http://，在 HTTPS 頁面
        //    上被瀏覽器當成混合內容擋掉。
        //
        // at: '*' 在這個拓撲下是安全的：正式環境的 compose 完全沒有對
        // host 發佈任何 port（見 compose.production.yaml），能把請求送到
        // 這個容器的只有同一個 docker network 裡的 nginx 與 cloudflared，
        // 而容器 IP 每次重建都會變，寫死反而會在某次重啟後靜默失效。
        // 真正把外部流量擋在門外的是「沒有對外開放的 port」，不是這裡。
        //
        // **headers 刻意不包含 HEADER_X_FORWARDED_FOR。** 客戶端 IP 改由
        // nginx 從 CF-Connecting-IP 還原成 REMOTE_ADDR（見
        // docker/production/nginx.conf 的 real_ip 設定），Laravel 直接用
        // REMOTE_ADDR 即可。
        //
        // 為什麼不能信任 X-Forwarded-For：Cloudflare 對這個標頭是「附加」
        // 而不是覆寫，訪客自己送一個 `X-Forwarded-For: 1.2.3.4` 進來，
        // 到達應用程式時會變成 `1.2.3.4, <真實IP>`；而 Symfony 在所有
        // 代理都受信任時取的是最左邊那個，於是 request->ip() 回傳偽造值
        // ——實測確認過。稽核紀錄的登入來源 IP 如果可以被訪客自己指定，
        // 那份紀錄就沒有證據價值了（見 App\Support\AuditLog）。
        // CF-Connecting-IP 則是 Cloudflare 一律覆寫，偽造不了。
        //
        // 其餘三個標頭仍然要信任：HTTPS 判斷（PROTO）與主機名（HOST）
        // 沒有別的來源，而且它們被偽造的後果小得多——PROTO 被改只會讓
        // 已經是 HTTPS 的連線被誤判成 HTTP（cookie 反而更嚴格），HOST
        // 另外有 trustHosts() 白名單把關。
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // 只接受 APP_URL 指定的那一個主機名，擋掉偽造 Host 標頭的請求。
        //
        // 這裡一定要明確傳 at:，不能只寫 trustHosts(subdomains: false)——
        // TrustHosts::hosts() 在 $alwaysTrust 是 null 時會直接回傳
        // 「APP_URL 及其所有子網域」的 regex，subdomains 參數根本不會被
        // 讀到（它只在有傳 at: 時才生效）。這個系統只會有一個固定網域，
        // 沒有子網域需求，開著只是白白擴大接受範圍。
        //
        // 用 closure 而不是直接算好陣列：正式環境會跑 config:cache，
        // config() 要在請求當下才解析。regex 也要自己錨定＋跳脫，
        // Symfony 的 setTrustedHosts() 是把字串當 pattern 用的，直接丟
        // 主機名進去的話裡面的 . 會變成「任意字元」。
        //
        // TrustHosts::shouldSpecifyTrustedHosts() 本身就會在 local 環境
        // 與單元測試中略過，所以這行不影響開發與測試。
        $middleware->trustHosts(
            at: fn () => array_filter([
                ($host = parse_url((string) config('app.url'), PHP_URL_HOST))
                    ? '^'.preg_quote($host, '#').'$'
                    : null,
            ]),
            subdomains: false,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
