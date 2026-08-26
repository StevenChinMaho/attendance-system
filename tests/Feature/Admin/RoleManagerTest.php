<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\RoleManager;
use App\Livewire\Admin\UserManager;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagerTest extends TestCase
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

    public function test_guest_is_redirected_away_from_the_roles_page(): void
    {
        $this->get('/admin/roles')->assertRedirect('/');
    }

    public function test_a_role_without_the_roles_permission_is_forbidden_from_the_roles_page(): void
    {
        // homeroom_teacher 有其他 admin.* 權限之外的能力，但不包含
        // roles.manage——確認頁面級權限真的是逐頁檢查，不是「只要有
        // 任何一個 admin 相關權限就放行」。
        $teacher = User::factory()->create();
        $teacher->assignRole('homeroom_teacher');

        $this->actingAs($teacher)
            ->get('/admin/roles')
            ->assertForbidden();
    }

    public function test_admin_can_view_the_role_list(): void
    {
        // 內建三個角色的英文代號一律透過 App\Support\RoleLabel 轉成中文
        // 顯示（見 CLAUDE.md「角色/身分」的用語說明），畫面上看不到原始
        // 的英文代號。
        // student_rep 的中文名稱「學生」刻意不用 assertSee 驗：權限勾選欄
        // 本來就有「學生管理」這個標籤，看到「學生」兩個字並不能證明
        // RoleLabel 有正常運作，會是個永遠會過的假斷言。這裡真正的把關是
        // 下面的 assertDontSee('student_rep')（畫面上不該出現英文代號），
        // 對照表本身的正確性由 RoleLabelTest 直接驗。
        $this->actingAs($this->admin())
            ->get('/admin/roles')
            ->assertOk()
            ->assertSee('管理者')
            ->assertSee('導師')
            ->assertDontSee('homeroom_teacher')
            ->assertDontSee('student_rep');
    }

    public function test_admin_can_create_a_custom_role_with_selected_permissions(): void
    {
        Livewire::actingAs($this->admin())
            ->test(RoleManager::class)
            ->set('name', 'exam_supervisor')
            ->set('selectedPermissions', ['teachers.manage'])
            ->call('createRole')
            ->assertHasNoErrors();

        $role = Role::where('name', 'exam_supervisor')->first();

        $this->assertNotNull($role);
        $this->assertTrue($role->hasPermissionTo('teachers.manage'));
        $this->assertFalse($role->hasPermissionTo('users.manage'));
    }

    public function test_creating_a_role_with_a_duplicate_name_fails_validation(): void
    {
        Livewire::actingAs($this->admin())
            ->test(RoleManager::class)
            ->set('name', 'admin')
            ->call('createRole')
            ->assertHasErrors('name');
    }

    public function test_admin_can_toggle_a_permission_on_a_custom_role(): void
    {
        $role = Role::create(['name' => 'exam_supervisor', 'guard_name' => 'web']);

        $component = Livewire::actingAs($this->admin())->test(RoleManager::class);

        $component->call('togglePermission', $role->id, 'teachers.manage');
        $this->assertTrue($role->fresh()->hasPermissionTo('teachers.manage'));

        $component->call('togglePermission', $role->id, 'teachers.manage');
        $this->assertFalse($role->fresh()->hasPermissionTo('teachers.manage'));
    }

    public function test_permissions_of_a_protected_built_in_role_cannot_be_toggled(): void
    {
        $studentRepRole = Role::where('name', 'student_rep')->first();

        Livewire::actingAs($this->admin())
            ->test(RoleManager::class)
            ->call('togglePermission', $studentRepRole->id, 'users.manage')
            ->assertForbidden();

        $this->assertFalse($studentRepRole->fresh()->hasPermissionTo('users.manage'));
    }

    public function test_a_built_in_role_cannot_be_deleted(): void
    {
        $adminRole = Role::where('name', 'admin')->first();

        Livewire::actingAs($this->admin())
            ->test(RoleManager::class)
            ->call('deleteRole', $adminRole->id)
            ->assertForbidden();

        $this->assertNotNull($adminRole->fresh());
    }

    public function test_a_custom_role_still_assigned_to_an_account_cannot_be_deleted(): void
    {
        $role = Role::create(['name' => 'exam_supervisor', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('exam_supervisor');

        // Livewire::test() 元件內部呼叫 session()->flash() 寫入的內容，
        // 跟外層測試呼叫的 session() 讀到的不是同一份（跟 CLAUDE.md
        // 提到的 AcademicPeriodSwitcher 那個 session 隔離現象同源），
        // 這裡只驗證真正重要的結果——角色沒有被刪掉。
        Livewire::actingAs($this->admin())
            ->test(RoleManager::class)
            ->call('deleteRole', $role->id);

        $this->assertNotNull($role->fresh());
    }

    public function test_an_unused_custom_role_can_be_deleted(): void
    {
        $role = Role::create(['name' => 'exam_supervisor', 'guard_name' => 'web']);

        Livewire::actingAs($this->admin())
            ->test(RoleManager::class)
            ->call('deleteRole', $role->id);

        $this->assertNull($role->fresh());
    }

    public function test_a_newly_created_role_grants_exactly_the_pages_it_was_given_end_to_end(): void
    {
        // 這是整個功能真正要達成的效果：管理者建立一個只開放「教師管理」
        // 頁面的自訂角色，指派給某個帳號後，那個帳號可以進 /admin/teachers，
        // 但進不了 /admin/users，即使兩個頁面之前都只靠同一個 role:admin
        // 判斷放行。
        Livewire::actingAs($this->admin())
            ->test(RoleManager::class)
            ->set('name', 'exam_supervisor')
            ->set('selectedPermissions', ['teachers.manage'])
            ->call('createRole');

        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->set('name', '考務助理')
            ->set('username', 'examhelper')
            ->set('password', 'a-strong-password')
            ->set('role', 'exam_supervisor')
            ->call('createUser');

        $limitedUser = User::where('username', 'examhelper')->first();

        // UserManager::createUser() 強制新帳號首次登入要先改密碼（見
        // EnsureUserHasChangedPassword），跟這裡要驗證的「頁面級權限」
        // 是兩件事——直接把這個測試帳號標記成已經改過密碼，才不會每個
        // 請求都被導去改密碼頁面而不是真正要測的頁面。
        $limitedUser->forceFill(['must_change_password' => false])->save();

        $this->actingAs($limitedUser)
            ->get('/admin/teachers')
            ->assertOk();

        $this->actingAs($limitedUser)
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_revoking_the_roles_permission_mid_session_blocks_further_livewire_actions(): void
    {
        $admin = $this->admin();

        $component = Livewire::actingAs($admin)
            ->test(RoleManager::class)
            ->set('name', 'sneaky_role');

        $admin->removeRole('admin');

        $component->call('createRole')->assertForbidden();

        $this->assertNull(Role::where('name', 'sneaky_role')->first());
    }
}
