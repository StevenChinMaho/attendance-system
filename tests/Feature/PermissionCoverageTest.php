<?php

namespace Tests\Feature;

use App\Livewire\Admin\RoleManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 權限是**資料**（spatie 的 permissions 資料表），不是程式碼。
 *
 * 只在路由上寫 `can:something.manage` 卻沒有把它加進 RolePermissionSeeder，
 * 結果是那個權限在資料庫裡根本不存在：頁面對所有人 403（包含管理者），
 * 身分管理也列不出這個選項可以勾——而且完全沒有錯誤訊息，看起來只像
 * 「功能沒做出來」。實際踩過一次（audit.view）。
 *
 * 這個檔案守住三件事的一致性：路由用到的權限、seeder 建立的權限、
 * 以及身分管理畫面上的中文標籤。
 */
class PermissionCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 從路由定義裡撈出所有 `can:xxx` 形式的權限名稱。
     *
     * 只取「不帶模型參數」的那種：`can:recordAttendance,schoolClass` 是
     * Policy 方法名而不是 spatie 權限，不該被算進來。
     *
     * @return list<string>
     */
    private function permissionsUsedInRoutes(): array
    {
        $found = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
                    continue;
                }

                $argument = substr($middleware, 4);

                // 帶第二個參數的是 Policy 檢查，不是 spatie 權限
                if (str_contains($argument, ',')) {
                    continue;
                }

                $found[] = $argument;
            }
        }

        return array_values(array_unique($found));
    }

    public function test_every_permission_used_in_a_route_is_created_by_the_seeder(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $seeded = Permission::pluck('name')->all();

        foreach ($this->permissionsUsedInRoutes() as $permission) {
            $this->assertContains(
                $permission,
                $seeded,
                "路由用到權限 `{$permission}`，但 RolePermissionSeeder 沒有建立它——".
                '這會讓對應頁面對所有人 403（含管理者），而且沒有任何錯誤訊息。',
            );
        }
    }

    /**
     * 少了標籤的話，身分管理的權限表頭會直接顯示英文原始字串，
     * 對非技術背景的管理者來說等於看不懂那一欄在勾什麼。
     */
    public function test_every_seeded_permission_has_a_chinese_label(): void
    {
        $this->seed(RolePermissionSeeder::class);

        foreach (Permission::pluck('name') as $permission) {
            $this->assertArrayHasKey(
                $permission,
                RoleManager::PERMISSION_LABELS,
                "權限 `{$permission}` 沒有對應的中文標籤，身分管理會顯示英文原始字串。",
            );
        }
    }

    public function test_no_label_refers_to_a_permission_that_no_longer_exists(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $seeded = Permission::pluck('name')->all();

        foreach (array_keys(RoleManager::PERMISSION_LABELS) as $labelled) {
            $this->assertContains(
                $labelled,
                $seeded,
                "PERMISSION_LABELS 裡有 `{$labelled}`，但 seeder 已經不再建立它。",
            );
        }
    }

    /**
     * admin 是全權身分，任何新增的權限預設都該落在它身上——否則新功能
     * 上線後連管理者自己都進不去。
     */
    public function test_the_admin_role_holds_every_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = Role::where('name', 'admin')->sole();

        foreach (Permission::pluck('name') as $permission) {
            $this->assertTrue(
                $admin->hasPermissionTo($permission),
                "admin 身分沒有 `{$permission}` 權限。",
            );
        }
    }

    /**
     * entrypoint 每次容器啟動都會跑這個 seeder（見 DEPLOYMENT.md 6.3），
     * 所以重複執行必須完全沒有副作用：不能重複建立、不能改變既有結果。
     */
    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $permissionsAfterFirstRun = Permission::orderBy('name')->pluck('name')->all();
        $rolesAfterFirstRun = Role::orderBy('name')->pluck('name')->all();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame($permissionsAfterFirstRun, Permission::orderBy('name')->pluck('name')->all());
        $this->assertSame($rolesAfterFirstRun, Role::orderBy('name')->pluck('name')->all());
    }

    /**
     * 自訂身分（/admin/roles 建的）不能被重跑的 seeder 動到——正式環境
     * 每次重啟都會跑一次，把學校自己設定的身分洗掉會是災難。
     */
    public function test_rerunning_the_seeder_leaves_custom_roles_untouched(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $custom = Role::create(['name' => '學務處人員', 'guard_name' => 'web']);
        $custom->givePermissionTo('audit.view');

        $this->seed(RolePermissionSeeder::class);

        $custom->refresh();

        $this->assertTrue(Role::where('name', '學務處人員')->exists());
        $this->assertSame(['audit.view'], $custom->permissions->pluck('name')->all());
    }

    /**
     * seeder 只管角色與權限，絕不能建立帳號——它會在正式環境每次啟動時
     * 執行，任何「有已知密碼的帳號」都會變成永久後門。
     */
    public function test_the_seeder_creates_no_accounts(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }
}
