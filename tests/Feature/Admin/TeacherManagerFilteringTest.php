<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\TeacherManager;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 教師管理的搜尋與排序。建立／編輯／刪除與權限邊界在 TeacherManagerTest。
 */
class TeacherManagerFilteringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_search_matches_an_unlinked_teachers_own_name(): void
    {
        $admin = $this->admin();
        Teacher::factory()->create(['teacher_name' => '王小明']);
        Teacher::factory()->create(['teacher_name' => '陳大文']);

        Livewire::actingAs($admin)
            ->test(TeacherManager::class)
            ->set('search', '王小明')
            ->assertSee('王小明')
            ->assertDontSee('陳大文');
    }

    /**
     * 有連結帳號的老師，畫面上顯示的是帳號的姓名（displayName()），
     * 搜尋當然也要找得到——只查 teachers.teacher_name 的話，使用者
     * 搜尋畫面上明明看得到的那個名字卻搜不到。
     */
    public function test_search_matches_the_linked_accounts_name(): void
    {
        $admin = $this->admin();
        $account = User::factory()->create(['name' => '林老師', 'username' => 'lin']);
        Teacher::factory()->create(['teacher_name' => '舊的名字', 'user_id' => $account->id]);
        Teacher::factory()->create(['teacher_name' => '陳大文']);

        Livewire::actingAs($admin)
            ->test(TeacherManager::class)
            ->set('search', '林老師')
            ->assertSee('lin')
            ->assertDontSee('陳大文');
    }

    public function test_search_matches_the_linked_accounts_username(): void
    {
        $admin = $this->admin();
        $account = User::factory()->create(['name' => '林老師', 'username' => 'lin-teacher']);
        Teacher::factory()->create(['teacher_name' => '林老師', 'user_id' => $account->id]);
        Teacher::factory()->create(['teacher_name' => '陳大文']);

        Livewire::actingAs($admin)
            ->test(TeacherManager::class)
            ->set('search', 'lin-teacher')
            ->assertSee('林老師')
            ->assertDontSee('陳大文');
    }

    /**
     * 沒有連結帳號的老師不能因為 join 而從清單裡消失——所以是 leftJoin，
     * 不是 join。
     */
    public function test_teachers_without_an_account_still_appear(): void
    {
        $admin = $this->admin();
        Teacher::factory()->create(['teacher_name' => '沒有帳號的老師']);

        Livewire::actingAs($admin)
            ->test(TeacherManager::class)
            ->assertSee('沒有帳號的老師');
    }

    /**
     * 姓名排序必須照 displayName() 的規則（有連結帳號時用帳號的姓名），
     * 否則畫面上明明是照姓名排，有連結帳號的那幾列看起來就會「沒排到」。
     */
    public function test_sorting_by_name_uses_the_linked_accounts_name(): void
    {
        $admin = $this->admin();

        $withAccount = Teacher::factory()->create([
            'teacher_name' => 'Beta',
            'user_id' => User::factory()->create(['name' => 'Aaron', 'username' => 'aaron'])->id,
        ]);
        $withoutAccount = Teacher::factory()->create(['teacher_name' => 'Alpha']);

        $ordered = Livewire::actingAs($admin)
            ->test(TeacherManager::class)
            ->viewData('teachers')
            ->pluck('id')
            ->all();

        // 顯示名稱是 Aaron / Alpha，所以連結帳號的那位要排在前面。
        // 若排序只看 teachers.teacher_name（Alpha / Beta），順序會相反。
        $this->assertSame([$withAccount->id, $withoutAccount->id], $ordered);
    }

    public function test_sorting_by_name_can_be_reversed(): void
    {
        $admin = $this->admin();

        $alpha = Teacher::factory()->create(['teacher_name' => 'Alpha']);
        $zeta = Teacher::factory()->create(['teacher_name' => 'Zeta']);

        $ordered = Livewire::actingAs($admin)
            ->test(TeacherManager::class)
            ->call('sortBy', 'name')
            ->viewData('teachers')
            ->pluck('id')
            ->all();

        $this->assertSame([$zeta->id, $alpha->id], $ordered);
    }

    public function test_an_unknown_sort_column_falls_back_to_the_default(): void
    {
        $admin = $this->admin();
        Teacher::factory()->create(['teacher_name' => '王小明']);

        $component = Livewire::actingAs($admin)
            ->test(TeacherManager::class)
            ->set('sortColumn', 'teacher_name); drop table teachers; --')
            ->assertOk()
            ->assertSee('王小明');

        $this->assertSame('name', $component->instance()->activeSortColumn());
    }
}
