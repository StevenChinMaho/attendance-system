<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 正式環境的請求鏈是 Cloudflare edge → cloudflared → nginx → app，
 * 中間隔了三層代理。bootstrap/app.php 的 trustProxies() 設定如果掉了，
 * 畫面上完全看不出任何異狀，但有兩件事會靜默壞掉，而且都是安全相關的：
 *
 *   1. $request->ip() 會變成 nginx 容器的固定內網 IP，
 *      LoginController 的登入頻率限制（key 是 username|ip）就會變成
 *      全校共用一組，任何人打錯 5 次密碼就把該帳號從所有地點鎖住。
 *   2. $request->isSecure() 會是 false，session cookie 的 secure 旗標
 *      與 route()/url() 產生的絕對網址都會退回 http。
 *
 * 所以這裡把它釘死成測試，而不是只寫在部署文件裡。
 */
class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 一條只在這個測試裡存在的路由，用來把全域 middleware 跑完之後
        // 的 Request 狀態撈出來——全域 middleware（含 TrustProxies）在
        // feature test 裡是會實際執行的。
        Route::get('/__proxy-probe', fn (Request $request) => [
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
        ]);
    }

    public function test_the_client_ip_comes_from_the_forwarded_header(): void
    {
        $this->get('/__proxy-probe', [
            'X-Forwarded-For' => '203.0.113.5',
        ])->assertJsonPath('ip', '203.0.113.5');
    }

    public function test_the_request_is_recognised_as_https_behind_the_tunnel(): void
    {
        // cloudflared 會帶上這個標頭。認不出來的話 Laravel 會以為
        // 自己在明文 HTTP 上服務。
        $this->get('/__proxy-probe', [
            'X-Forwarded-Proto' => 'https',
        ])->assertJsonPath('secure', true);
    }

    public function test_the_forwarded_host_is_honoured(): void
    {
        $this->get('/__proxy-probe', [
            'X-Forwarded-Host' => 'attendance.example.test',
        ])->assertJsonPath('host', 'attendance.example.test');
    }

    public function test_a_request_without_forwarded_headers_still_works(): void
    {
        // 直接連到容器（例如 docker exec 進去用 curl 打健康檢查）不該
        // 因為少了代理標頭就出錯。
        $this->get('/__proxy-probe')
            ->assertOk()
            ->assertJsonPath('secure', false);
    }
}
