<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\TeacherManager;
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
        $account = User::factory()->create();
        $account->assignRole('homeroom_teacher');

        Livewire::actingAs($this->admin())
            ->test(TeacherManager::class)
            ->set('teacherName', '李老師')
            ->set('userId', $account->id)
            ->call('createTeacher')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('teachers', ['teacher_name' => '李老師', 'user_id' => $account->id]);
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
}
