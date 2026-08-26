<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_admin_account_that_must_change_its_password(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->artisan('admin:create', ['--username' => 'principal', '--name' => '王主任'])
            ->expectsQuestion('密碼（至少 8 個字元，輸入時不會顯示）', 'a-strong-password')
            ->expectsQuestion('再輸入一次密碼', 'a-strong-password')
            ->assertSuccessful();

        $user = User::where('username', 'principal')->sole();

        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('a-strong-password', $user->password));

        // 密碼是部署者代設的，帳號本人首次登入必須換成只有自己知道的，
        // 跟 UserManager::createUser() 的行為一致。
        $this->assertTrue($user->must_change_password);
    }

    public function test_it_refuses_to_run_before_the_roles_have_been_seeded(): void
    {
        // 沒有跑 RolePermissionSeeder。這裡要的是一句看得懂的提示，
        // 而不是 spatie 丟出來的 RoleDoesNotExist——照著部署文件操作的
        // 人需要知道自己漏掉的是「上一步」。檢查刻意排在所有提問之前，
        // 不要讓人把帳號密碼都打完了才被告知白做工。
        $this->artisan('admin:create', ['--username' => 'principal', '--name' => '王主任'])
            ->expectsOutputToContain('db:seed --class=RolePermissionSeeder')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_rejects_mismatched_password_confirmation(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->artisan('admin:create', ['--username' => 'principal', '--name' => '王主任'])
            ->expectsQuestion('密碼（至少 8 個字元，輸入時不會顯示）', 'a-strong-password')
            ->expectsQuestion('再輸入一次密碼', 'a-different-password')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_rejects_a_password_shorter_than_the_admin_panel_would_accept(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->artisan('admin:create', ['--username' => 'principal', '--name' => '王主任'])
            ->expectsQuestion('密碼（至少 8 個字元，輸入時不會顯示）', 'short')
            ->expectsQuestion('再輸入一次密碼', 'short')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_rejects_a_username_that_already_exists(): void
    {
        $this->seed(RolePermissionSeeder::class);
        User::factory()->create(['username' => 'principal']);

        $this->artisan('admin:create', ['--username' => 'principal', '--name' => '王主任'])
            ->expectsQuestion('密碼（至少 8 個字元，輸入時不會顯示）', 'a-strong-password')
            ->expectsQuestion('再輸入一次密碼', 'a-strong-password')
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }
}
