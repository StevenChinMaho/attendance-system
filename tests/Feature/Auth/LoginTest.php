<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_only_ever_sees_the_login_page(): void
    {
        $this->get('/')->assertOk()->assertSee('登入');
    }

    public function test_guest_is_redirected_away_from_protected_routes(): void
    {
        $this->get('/dashboard')->assertRedirect('/');
    }

    public function test_manually_visiting_slash_login_shows_the_login_page_instead_of_405(): void
    {
        // 只有 POST /login 存在的時候，手動在網址列輸入 /login 會命中
        // 那個路徑但方法不符，得到 405——很多人會直接猜這個網址，見
        // routes/web.php 額外提供的 GET /login。
        $this->get('/login')->assertOk()->assertSee('登入');
    }

    public function test_an_authenticated_user_visiting_slash_login_is_redirected_away(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/login')
            ->assertRedirect('/dashboard');
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->create([
            'username' => 'teststudent',
            'password' => bcrypt('correct-password'),
        ]);

        $this->post('/login', [
            'username' => 'teststudent',
            'password' => 'correct-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'username' => 'teststudent',
            'password' => bcrypt('correct-password'),
        ]);

        $this->post('/login', [
            'username' => 'teststudent',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_repeated_failed_attempts_get_rate_limited_regardless_of_whether_the_account_exists(): void
    {
        // 沒有這一層，攻擊者可以無限次對著同一個帳號猜密碼（甚至對著
        // 不存在的帳號名稱枚舉）直到猜中或試出哪些帳號存在為止。
        User::factory()->create([
            'username' => 'teststudent',
            'password' => bcrypt('correct-password'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'username' => 'teststudent',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('username');
        }

        // 第 6 次即使密碼這次是對的，也應該先被擋在「嘗試次數過多」，
        // 不會因為密碼正確就放行。
        $response = $this->post('/login', [
            'username' => 'teststudent',
            'password' => 'correct-password',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertStringContainsString('嘗試次數過多', session('errors')->first('username'));
        $this->assertGuest();
    }

    public function test_rate_limit_is_scoped_per_account_not_shared_across_every_login_attempt(): void
    {
        // key 是「帳號＋IP」——某個帳號被鎖住，不該連累到別的帳號也
        // 登入不了（不然一次針對某帳號的攻擊就能順便癱瘓其他人登入）。
        $lockedOut = User::factory()->create(['username' => 'locked-out']);
        $unaffected = User::factory()->create([
            'username' => 'unaffected',
            'password' => bcrypt('correct-password'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => $lockedOut->username, 'password' => 'wrong-password']);
        }

        $this->post('/login', [
            'username' => 'unaffected',
            'password' => 'correct-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($unaffected);
    }

    public function test_a_successful_login_clears_the_rate_limit(): void
    {
        $user = User::factory()->create([
            'username' => 'teststudent',
            'password' => bcrypt('correct-password'),
        ]);

        // 錯個幾次但還沒到鎖住的門檻。
        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['username' => 'teststudent', 'password' => 'wrong-password']);
        }

        $this->post('/login', [
            'username' => 'teststudent',
            'password' => 'correct-password',
        ])->assertRedirect(route('dashboard'));

        Auth::logout();

        // 登入成功後計數器應該歸零，不會因為登入前那幾次失敗，之後正常
        // 輸對密碼卻莫名其妙被鎖住。
        $this->post('/login', [
            'username' => 'teststudent',
            'password' => 'correct-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_disabled_account_cannot_login_even_with_correct_password(): void
    {
        User::factory()->create([
            'username' => 'disabled',
            'password' => bcrypt('correct-password'),
            'is_active' => false,
        ]);

        $this->post('/login', [
            'username' => 'disabled',
            'password' => 'correct-password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_deactivating_a_user_immediately_kicks_out_their_existing_session(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        // 帳號還是啟用狀態時登入，模擬「使用者已經開著頁面」。
        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk();

        // 管理者事後停用這個帳號——session 本身完全沒有變動。
        $user->update(['is_active' => false]);

        // 同一個（已經登入的）session 再訪問任何受保護頁面，都應該被踢出，
        // 而不是因為 session 還有效就放行。
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_login_page_is_never_cached_by_the_browser(): void
    {
        // 沒有這個 header，瀏覽器可能用 bfcache/上一頁 顯示出這個頁面已經
        // 登入之前的舊版本，讓已登入的使用者看到自己的舊登入表單。Symfony
        // 會重新排序/正規化這個 header 的內容，所以只檢查關鍵指令有沒有
        // 出現，不比對完整字串。
        $cacheControl = $this->get('/')->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
    }

    public function test_submitting_login_while_a_different_account_is_already_authenticated_switches_accounts(): void
    {
        // 重現情境：user1 已登入，瀏覽器透過「上一頁」或書籤顯示出登入頁
        // 的過期快取，這時候在那個舊表單填了 user2 的帳密送出。因為
        // POST /login 沒有掛 guest middleware，這個請求應該被正常處理成
        // 「登出 user1、登入 user2」，而不是被 guest middleware 靜默攔截、
        // 導回 user1 原本的頁面。
        $user1 = User::factory()->create(['username' => 'user1', 'password' => bcrypt('password1')]);
        $user2 = User::factory()->create(['username' => 'user2', 'password' => bcrypt('password2')]);

        $this->actingAs($user1);
        $this->assertAuthenticatedAs($user1);

        $this->post('/login', [
            'username' => 'user2',
            'password' => 'password2',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user2);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertGuest();
    }
}
