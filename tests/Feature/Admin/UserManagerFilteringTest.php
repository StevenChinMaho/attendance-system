<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\UserManager;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 帳號管理的搜尋／篩選／排序。跟 UserManagerTest（建立、編輯、刪除、
 * 權限邊界）分開放，那個檔案已經夠長了。
 */
class UserManagerFilteringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['name' => '系統管理者', 'username' => 'sysadmin']);
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_search_matches_the_display_name(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => '王小明', 'username' => 'wang'])->assignRole('homeroom_teacher');
        User::factory()->create(['name' => '陳大文', 'username' => 'chen'])->assignRole('homeroom_teacher');

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->set('search', '王小明')
            ->assertSee('wang')
            ->assertDontSee('chen');
    }

    public function test_search_matches_the_username(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => '王小明', 'username' => 'teacher-a'])->assignRole('homeroom_teacher');
        User::factory()->create(['name' => '陳大文', 'username' => 'teacher-b'])->assignRole('homeroom_teacher');

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->set('search', 'teacher-b')
            ->assertSee('陳大文')
            ->assertDontSee('王小明');
    }

    public function test_the_role_filter_narrows_the_list(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => '導師甲', 'username' => 'ht1'])->assignRole('homeroom_teacher');
        User::factory()->create(['name' => '學生乙', 'username' => 'st1'])->assignRole('student_rep');

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->set('roleFilter', 'student_rep')
            ->assertSee('學生乙')
            ->assertDontSee('導師甲');
    }

    /**
     * $roleFilter 是 public 屬性，客戶端每次更新請求都能改寫它。spatie 的
     * role() scope 收到不存在的角色名會丟出 RoleDoesNotExist——沒有先比對
     * 存在的角色清單就直接套用的話，隨手塞一個字串進來就能讓整個後台
     * 頁面 500。
     */
    public function test_an_unknown_role_filter_is_ignored_instead_of_erroring(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => '導師甲', 'username' => 'ht1'])->assignRole('homeroom_teacher');

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->set('roleFilter', 'this-role-does-not-exist')
            ->assertOk()
            ->assertSee('導師甲');
    }

    public function test_the_status_filter_separates_active_from_disabled(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => '啟用者', 'username' => 'on1'])->assignRole('homeroom_teacher');
        User::factory()->inactive()->create(['name' => '停用者', 'username' => 'off1'])->assignRole('homeroom_teacher');

        $component = Livewire::actingAs($admin)->test(UserManager::class);

        $component->set('statusFilter', 'inactive')
            ->assertSee('停用者')
            ->assertDontSee('啟用者');

        $component->set('statusFilter', 'active')
            ->assertSee('啟用者')
            ->assertDontSee('停用者');
    }

    public function test_clear_filters_restores_the_full_list(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => '王小明', 'username' => 'wang'])->assignRole('homeroom_teacher');

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->set('search', '不存在的人')
            ->set('statusFilter', 'inactive')
            ->assertDontSee('王小明')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('statusFilter', '')
            ->assertSee('王小明');
    }

    public function test_sorting_defaults_to_name_and_toggles_direction(): void
    {
        $admin = $this->admin();

        $component = Livewire::actingAs($admin)->test(UserManager::class);

        // 還沒點過任何標題時，畫面上的三角形要指向預設欄位，不是「無」。
        $this->assertSame('name', $component->instance()->activeSortColumn());
        $this->assertSame('asc', $component->instance()->activeSortDirection());

        $component->call('sortBy', 'name');
        $this->assertSame('desc', $component->instance()->activeSortDirection());

        $component->call('sortBy', 'name');
        $this->assertSame('asc', $component->instance()->activeSortDirection());
    }

    public function test_switching_column_starts_from_ascending(): void
    {
        $admin = $this->admin();

        $component = Livewire::actingAs($admin)->test(UserManager::class)
            ->call('sortBy', 'name')          // name desc
            ->call('sortBy', 'username');     // 換欄位

        $this->assertSame('username', $component->instance()->activeSortColumn());
        $this->assertSame('asc', $component->instance()->activeSortDirection());
    }

    /**
     * $sortColumn 直接被串進 orderBy 就是 SQL injection。白名單以外的值
     * 一律退回預設欄位，而且 sortBy() 收到不認識的 key 要安靜地不動作。
     */
    public function test_an_unknown_sort_column_falls_back_to_the_default(): void
    {
        $admin = $this->admin();

        $component = Livewire::actingAs($admin)->test(UserManager::class)
            ->set('sortColumn', 'name); drop table users; --')
            ->assertOk();

        $this->assertSame('name', $component->instance()->activeSortColumn());

        $component->call('sortBy', 'password')->assertOk();
        $this->assertSame('name', $component->instance()->activeSortColumn());
    }

    public function test_an_unknown_sort_direction_is_treated_as_ascending(): void
    {
        $admin = $this->admin();

        $component = Livewire::actingAs($admin)->test(UserManager::class)
            ->set('sortDirection', 'asc, (select 1)')
            ->assertOk();

        $this->assertSame('asc', $component->instance()->activeSortDirection());
    }

    public function test_sorting_by_role_does_not_error(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => '導師甲', 'username' => 'ht1'])->assignRole('homeroom_teacher');
        User::factory()->create(['name' => '學生乙', 'username' => 'st1'])->assignRole('student_rep');

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('sortBy', 'role')
            ->assertOk()
            ->assertSee('導師甲')
            ->assertSee('學生乙');
    }
}
