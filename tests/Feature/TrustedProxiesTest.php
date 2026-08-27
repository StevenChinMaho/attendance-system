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
 *      （真實 IP 由 nginx 的 real_ip 從 CF-Connecting-IP 還原到
 *      REMOTE_ADDR，見 docker/production/nginx.conf。）
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

    /**
     * **X-Forwarded-For 不能被信任。** Cloudflare 對這個標頭是「附加」
     * 而不是覆寫，所以訪客自己送一個進來，到達應用程式時會變成
     * `偽造值, 真實IP`，而 Symfony 在所有代理都受信任時取最左邊那個
     * ——實測過確實會回傳偽造值。稽核紀錄的登入來源 IP 如果可以被訪客
     * 自己指定，那份紀錄就沒有證據價值了。
     *
     * 真實 IP 改由 nginx 從 CF-Connecting-IP（Cloudflare 一律覆寫、
     * 偽造不了）還原成 REMOTE_ADDR，見 docker/production/nginx.conf。
     */
    public function test_the_forwarded_for_header_cannot_spoof_the_client_ip(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->get('/__proxy-probe', [
                'X-Forwarded-For' => '1.2.3.4, 203.0.113.5',
            ])
            ->assertJsonPath('ip', '203.0.113.5');
    }

    /**
     * nginx 的 real_ip 會把 CF-Connecting-IP 還原到 REMOTE_ADDR，
     * 應用程式直接用它即可。
     */
    public function test_the_client_ip_comes_from_the_remote_address(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->get('/__proxy-probe')
            ->assertJsonPath('ip', '203.0.113.5');
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
