<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\UserManager;
use App\Models\AttendanceSession;
use App\Models\SchoolClass;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guest_is_redirected_away_from_the_admin_users_page(): void
    {
        $this->get('/admin/users')->assertRedirect('/');
    }

    public function test_non_admin_role_is_forbidden_from_the_admin_users_page(): void
    {
        $studentRep = User::factory()->create();
        $studentRep->assignRole('student_rep');

        $this->actingAs($studentRep)
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_admin_can_view_the_user_list(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee($admin->username);
    }

    public function test_admin_can_create_a_user_with_a_role(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->set('name', '新副班長')
            ->set('username', 'newrep')
            ->set('password', 'a-strong-password')
            ->set('role', 'student_rep')
            ->call('createUser')
            ->assertHasNoErrors();

        $created = User::where('username', 'newrep')->first();

        $this->assertNotNull($created);
        $this->assertTrue($created->hasRole('student_rep'));
        $this->assertTrue(Hash::check('a-strong-password', $created->password));
    }

    public function test_creating_a_user_with_a_duplicate_username_fails_validation(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        User::factory()->create(['username' => 'taken']);

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->set('name', '重複帳號')
            ->set('username', 'taken')
            ->set('password', 'a-strong-password')
            ->set('role', 'student_rep')
            ->call('createUser')
            ->assertHasErrors('username');
    }

    public function test_admin_can_toggle_another_users_active_status(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $target = User::factory()->create(['is_active' => true]);

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('toggleActive', $target->id);

        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_revoking_admin_role_mid_session_blocks_further_livewire_actions(): void
    {
        // routes/web.php 的 can:users.manage middleware 只在整頁載入那一刻
        // 檢查一次，不會延續到 Livewire 元件之後每一次 wire:click 的互動
        // 請求——這是 RequiresPermission trait 存在的原因，這裡驗證它真的
        // 擋得住。
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $component = Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->set('name', '想搶著建立的帳號')
            ->set('username', 'sneaky')
            ->set('password', 'a-strong-password')
            ->set('role', 'student_rep');

        $admin->removeRole('admin');

        $component->call('createUser')->assertForbidden();

        $this->assertDatabaseMissing('users', ['username' => 'sneaky']);
    }

    public function test_deactivating_a_user_deletes_their_existing_session_rows(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $target = User::factory()->create(['is_active' => true]);

        // 模擬這個使用者原本有一個登入中的 session。
        DB::table('sessions')->insert([
            'id' => 'fake-session-id',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode('x'),
            'last_activity' => now()->timestamp,
        ]);

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('toggleActive', $target->id);

        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
    }

    public function test_reactivating_a_user_does_not_touch_sessions(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $target = User::factory()->create(['is_active' => false]);

        DB::table('sessions')->insert([
            'id' => 'fake-session-id-2',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode('x'),
            'last_activity' => now()->timestamp,
        ]);

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('toggleActive', $target->id);

        $this->assertTrue($target->fresh()->is_active);
        $this->assertDatabaseHas('sessions', ['user_id' => $target->id]);
    }

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('toggleActive', $admin->id);

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_creating_a_user_forces_a_password_change_on_first_login(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->set('name', '新副班長')
            ->set('username', 'newrep')
            ->set('password', 'a-strong-password')
            ->set('role', 'student_rep')
            ->call('createUser');

        $this->assertTrue(User::where('username', 'newrep')->firstOrFail()->must_change_password);
    }

    public function test_admin_can_edit_another_users_name_and_role(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $target = User::factory()->create(['name' => '舊名字']);
        $target->assignRole('student_rep');

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('startEdit', $target->id)
            ->set('name', '新名字')
            ->set('role', 'homeroom_teacher')
            ->call('updateUser')
            ->assertHasNoErrors();

        $fresh = $target->fresh();
        $this->assertSame('新名字', $fresh->name);
        $this->assertTrue($fresh->hasRole('homeroom_teacher'));
        $this->assertFalse($fresh->hasRole('student_rep'));
    }

    public function test_the_last_admins_role_cannot_be_changed_away_from_admin(): void
    {
        // deleteUser() 擋了「刪除系統裡最後一個 admin」，updateUser()
        // 也要擋對稱的漏洞：把最後一個 admin 的身分改成別的角色，一樣
        // 會讓系統沒有人是字面上的 admin。用另一個持有 users.manage
        // 權限、但不是這個 admin 本人的帳號操作，確保擋下來的是「最後
        // 一個 admin」這條規則，不是「不能編輯自己」那條。
        $lastAdmin = User::factory()->create();
        $lastAdmin->assignRole('admin');

        $role = Role::create(['name' => 'user_operator', 'guard_name' => 'web']);
        $role->syncPermissions(['users.manage']);
        $operator = User::factory()->create();
        $operator->assignRole('user_operator');

        Livewire::actingAs($operator)
            ->test(UserManager::class)
            ->call('startEdit', $lastAdmin->id)
            ->set('role', 'user_operator')
            ->call('updateUser');

        $this->assertTrue($lastAdmin->fresh()->hasRole('admin'));
    }

    public function test_an_admins_role_can_be_changed_when_another_admin_still_exists(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $secondAdmin = User::factory()->create();
        $secondAdmin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('startEdit', $secondAdmin->id)
            ->set('role', 'student_rep')
            ->call('updateUser')
            ->assertHasNoErrors();

        $this->assertTrue($secondAdmin->fresh()->hasRole('student_rep'));
    }

    public function test_admin_cannot_edit_their_own_account(): void
    {
        // 姓名還好，但身分欄位混在同一個表單裡，萬一手滑把自己的角色
        // 改掉會直接把自己鎖出後台——乾脆整個編輯功能都不對自己開放。
        $admin = User::factory()->create(['name' => '管理者本人']);
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('startEdit', $admin->id)
            ->assertSet('editingUserId', null);
    }

    public function test_opening_the_create_form_while_editing_closes_the_edit_form(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $target = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('startEdit', $target->id)
            ->assertSet('editingUserId', $target->id)
            ->call('toggleCreateForm')
            ->assertSet('showCreateForm', true)
            ->assertSet('editingUserId', null)
            ->assertSet('name', '');
    }

    public function test_admin_can_reset_another_users_password(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $target = User::factory()->create(['password' => bcrypt('old-password')]);

        DB::table('sessions')->insert([
            'id' => 'fake-session-id-reset',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode('x'),
            'last_activity' => now()->timestamp,
        ]);

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('startResetPassword', $target->id)
            ->set('newPassword', 'a-brand-new-password')
            ->call('resetPassword')
            ->assertHasNoErrors();

        $fresh = $target->fresh();
        $this->assertTrue(Hash::check('a-brand-new-password', $fresh->password));
        $this->assertTrue($fresh->must_change_password);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
    }

    public function test_resetting_a_password_requires_at_least_eight_characters(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $target = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('startResetPassword', $target->id)
            ->set('newPassword', 'short')
            ->call('resetPassword')
            ->assertHasErrors('newPassword');
    }

    public function test_admin_cannot_reset_their_own_password_through_this_flow(): void
    {
        // 自己的密碼有專門的「變更密碼」自助頁面，不透過帳號管理這條路。
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('startResetPassword', $admin->id)
            ->assertSet('resettingPasswordUserId', null);
    }

    public function test_admin_can_delete_a_user_with_no_history(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $target = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('deleteUser', $target->id);

        $this->assertModelMissing($target);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('deleteUser', $admin->id);

        $this->assertModelExists($admin);
    }

    public function test_the_last_admin_account_cannot_be_deleted(): void
    {
        $lastAdmin = User::factory()->create();
        $lastAdmin->assignRole('admin');

        // 用另一個持有 users.manage 權限、但不是這個 admin 本人的帳號
        // 嘗試刪除——確保擋下來的是「這是系統裡最後一個 admin」這條
        // 規則本身，不是「不能刪自己」那條（那條已經有獨立測試涵蓋）。
        $role = Role::create(['name' => 'user_operator', 'guard_name' => 'web']);
        $role->syncPermissions(['users.manage']);
        $operator = User::factory()->create();
        $operator->assignRole('user_operator');

        Livewire::actingAs($operator)
            ->test(UserManager::class)
            ->call('deleteUser', $lastAdmin->id);

        $this->assertModelExists($lastAdmin);
    }

    public function test_an_admin_can_be_deleted_when_another_admin_still_exists(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $secondAdmin = User::factory()->create();
        $secondAdmin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('deleteUser', $secondAdmin->id);

        $this->assertModelMissing($secondAdmin);
    }

    public function test_a_user_with_attendance_history_cannot_be_deleted(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $target = User::factory()->create();
        $class = SchoolClass::factory()->create();
        AttendanceSession::factory()->for($class, 'schoolClass')->create(['recorded_by' => $target->id]);

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('deleteUser', $target->id);

        $this->assertModelExists($target);
    }
}
