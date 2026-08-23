<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\TeacherManager;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeacherManagerTest extends TestCase
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

    public function test_guest_is_redirected_away_from_the_teachers_page(): void
    {
        $this->get('/admin/teachers')->assertRedirect('/');
    }

    public function test_non_admin_is_forbidden_from_the_teachers_page(): void
    {
        $rep = User::factory()->create();
        $rep->assignRole('student_rep');

        $this->actingAs($rep)->get('/admin/teachers')->assertForbidden();
    }

    public function test_admin_can_create_a_teacher_without_a_linked_account(): void
    {
        Livewire::actingAs($this->admin())
            ->test(TeacherManager::class)
            ->set('teacherName', '王老師')
            ->call('createTeacher')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('teachers', ['teacher_name' => '王老師', 'user_id' => null]);
    }

    public function test_admin_can_create_a_teacher_linked_to_an_account(): void
    {
        // 連結帳號時姓名不需要（也不應該）手動打，直接沿用帳號本身的
        // users.name——見 Teacher::resolveName() 的說明：帳號本身就有
        // 姓名了，分開輸入容易兩邊打成不一樣的字。
        $account = User::factory()->create(['name' => '李老師']);
        $account->assignRole('homeroom_teacher');

        Livewire::actingAs($this->admin())
            ->test(TeacherManager::class)
            ->set('userId', $account->id)
            ->call('createTeacher')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('teachers', ['teacher_name' => '李老師', 'user_id' => $account->id]);
    }

    public function test_a_manually_typed_name_is_ignored_when_an_account_is_linked(): void
    {
        // 就算 userId 跟 teacherName 被同時設定成不一致的值一起送出
        // （不管是不是正常操作流程），伺服器端還是要以帳號的姓名為準，
        // 不能讓用戶端夾帶的 teacherName 蓋過去。
        $account = User::factory()->create(['name' => '真正的姓名']);

        Livewire::actingAs($this->admin())
            ->test(TeacherManager::class)
            ->set('userId', $account->id)
            ->set('teacherName', '不應該用到這個名字')
            ->call('createTeacher')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('teachers', ['teacher_name' => '真正的姓名']);
        $this->assertDatabaseMissing('teachers', ['teacher_name' => '不應該用到這個名字']);
    }

    public function test_name_is_required_when_no_account_is_linked(): void
    {
        Livewire::actingAs($this->admin())
            ->test(TeacherManager::class)
            ->set('teacherName', '')
            ->call('createTeacher')
            ->assertHasErrors('teacherName');
    }

    public function test_linking_an_account_already_used_by_another_teacher_fails_validation(): void
    {
        $account = User::factory()->create();
        Teacher::factory()->create(['user_id' => $account->id]);

        Livewire::actingAs($this->admin())
            ->test(TeacherManager::class)
            ->set('teacherName', '重複連結')
            ->set('userId', $account->id)
            ->call('createTeacher')
            ->assertHasErrors('userId');
    }

    public function test_linking_an_account_already_used_by_a_student_fails_validation(): void
    {
        $account = User::factory()->create();
        Student::factory()->create(['user_id' => $account->id]);

        Livewire::actingAs($this->admin())
            ->test(TeacherManager::class)
            ->set('teacherName', '想搶帳號的老師')
            ->set('userId', $account->id)
            ->call('createTeacher')
            ->assertHasErrors('userId');
    }

    public function test_admin_can_edit_a_teachers_name(): void
    {
        $teacher = Teacher::factory()->create(['teacher_name' => '舊名字']);

        Livewire::actingAs($this->admin())
            ->test(TeacherManager::class)
            ->call('startEdit', $teacher->id)
            ->set('teacherName', '新名字')
            ->call('updateTeacher')
            ->assertHasNoErrors();

        $this->assertSame('新名字', $teacher->fresh()->teacher_name);
    }

    public function test_admin_can_delete_a_teacher_with_no_homeroom_classes(): void
    {
        $teacher = Teacher::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(TeacherManager::class)
            ->call('deleteTeacher', $teacher->id);

        $this->assertModelMissing($teacher);
    }

    public function test_a_teacher_currently_assigned_as_a_homeroom_teacher_cannot_be_deleted(): void
    {
        $teacher = Teacher::factory()->create();
        SchoolClass::factory()->create(['homeroom_teacher_id' => $teacher->id]);

        Livewire::actingAs($this->admin())
            ->test(TeacherManager::class)
            ->call('deleteTeacher', $teacher->id);

        $this->assertModelExists($teacher);
    }

    public function test_opening_the_create_form_while_editing_closes_the_edit_form(): void
    {
        // 新增表單跟編輯表單共用同一組欄位屬性（$teacherName/$userId）；
        // 兩個表單以前可以同時開著，輸入內容看起來會互相同步，實際上是
        // 同一個屬性被兩邊的輸入框綁定。
        $teacher = Teacher::factory()->create(['teacher_name' => '正在編輯的老師']);

        $component = Livewire::actingAs($this->admin())
            ->test(TeacherManager::class)
            ->call('startEdit', $teacher->id)
            ->assertSet('editingTeacherId', $teacher->id);

        $component->call('toggleCreateForm')
            ->assertSet('showCreateForm', true)
            ->assertSet('editingTeacherId', null)
            ->assertSet('teacherName', '');
    }

    public function test_creating_a_teacher_while_another_is_being_edited_does_not_duplicate_its_data(): void
    {
        // 修正前：編輯表單開著時點「新增老師」，新增表單會沿用正在編輯
        // 那筆老師的姓名／連結帳號，因為兩個表單共用同一組屬性。
        $existing = Teacher::factory()->create(['teacher_name' => '正在編輯的老師']);

        Livewire::actingAs($this->admin())
            ->test(TeacherManager::class)
            ->call('startEdit', $existing->id)
            ->call('toggleCreateForm')
            ->set('teacherName', '全新的老師')
            ->call('createTeacher')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('teachers', ['teacher_name' => '全新的老師']);
        $this->assertSame('正在編輯的老師', $existing->fresh()->teacher_name);
    }
}
