<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\UserManager;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
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
        // routes/web.php 的 role:admin middleware 只在整頁載入那一刻檢查
        // 一次，不會延續到 Livewire 元件之後每一次 wire:click 的互動請求
        // ——這是 RequiresAdminRole trait 存在的原因，這裡驗證它真的擋得住。
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
}
