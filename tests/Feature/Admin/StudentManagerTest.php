<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\StudentManager;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentManagerTest extends TestCase
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

    public function test_guest_is_redirected_away_from_the_students_page(): void
    {
        $class = SchoolClass::factory()->create();

        $this->get("/admin/classes/{$class->id}/students")->assertRedirect('/');
    }

    public function test_non_admin_is_forbidden_from_the_students_page(): void
    {
        $class = SchoolClass::factory()->create();
        $rep = User::factory()->create();
        $rep->assignRole('student_rep');

        $this->actingAs($rep)
            ->get("/admin/classes/{$class->id}/students")
            ->assertForbidden();
    }

    public function test_admin_can_create_a_student_in_a_class(): void
    {
        $class = SchoolClass::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->set('studentNumber', '10001')
            ->set('seatNumber', '1')
            ->set('name', '陳小明')
            ->set('gender', '男')
            ->call('createStudent')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('students', [
            'school_class_id' => $class->id,
            'name' => '陳小明',
        ]);
    }

    public function test_duplicate_student_number_within_the_same_class_fails_validation(): void
    {
        $class = SchoolClass::factory()->create();
        Student::factory()->for($class, 'schoolClass')->create(['student_number' => '10001']);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->set('studentNumber', '10001')
            ->set('seatNumber', '2')
            ->set('name', '另一個學生')
            ->set('gender', '女')
            ->call('createStudent')
            ->assertHasErrors('studentNumber');
    }

    public function test_duplicate_seat_number_within_the_same_class_fails_validation(): void
    {
        $class = SchoolClass::factory()->create();
        Student::factory()->for($class, 'schoolClass')->create(['seat_number' => '1']);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->set('studentNumber', '99999')
            ->set('seatNumber', '1')
            ->set('name', '另一個學生')
            ->set('gender', '女')
            ->call('createStudent')
            ->assertHasErrors('seatNumber');
    }

    public function test_same_student_number_in_a_different_class_is_allowed(): void
    {
        $otherClass = SchoolClass::factory()->create();
        Student::factory()->for($otherClass, 'schoolClass')->create(['student_number' => '10001']);

        $class = SchoolClass::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->set('studentNumber', '10001')
            ->set('seatNumber', '1')
            ->set('name', '陳小明')
            ->set('gender', '男')
            ->call('createStudent')
            ->assertHasNoErrors();
    }

    public function test_admin_can_link_a_student_rep_account_to_a_student(): void
    {
        $class = SchoolClass::factory()->create();
        $account = User::factory()->create();
        $account->assignRole('student_rep');

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->set('studentNumber', '10001')
            ->set('seatNumber', '1')
            ->set('name', '副班長')
            ->set('gender', '男')
            ->set('userId', $account->id)
            ->call('createStudent')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('students', ['name' => '副班長', 'user_id' => $account->id]);
    }

    public function test_linking_an_account_already_used_by_a_teacher_fails_validation(): void
    {
        $class = SchoolClass::factory()->create();
        $account = User::factory()->create();
        Teacher::factory()->create(['user_id' => $account->id]);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->set('studentNumber', '99999')
            ->set('seatNumber', '2')
            ->set('name', '想搶帳號的學生')
            ->set('gender', '女')
            ->set('userId', $account->id)
            ->call('createStudent')
            ->assertHasErrors('userId');
    }

    public function test_linking_an_account_already_used_by_another_student_fails_validation(): void
    {
        $class = SchoolClass::factory()->create();
        $account = User::factory()->create();
        Student::factory()->for($class, 'schoolClass')->create(['user_id' => $account->id]);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->set('studentNumber', '99999')
            ->set('seatNumber', '2')
            ->set('name', '另一個學生')
            ->set('gender', '女')
            ->set('userId', $account->id)
            ->call('createStudent')
            ->assertHasErrors('userId');
    }

    public function test_opening_the_create_form_while_editing_closes_the_edit_form(): void
    {
        // 新增表單跟編輯表單共用同一組欄位屬性（$studentNumber/
        // $seatNumber/$name/$gender/$userId）；兩個表單以前可以同時開
        // 著，輸入內容看起來會互相同步，實際上是同一個屬性被兩邊的
        // 輸入框綁定。
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->for($class, 'schoolClass')->create(['student_number' => '10001']);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->call('startEdit', $student->id)
            ->assertSet('editingStudentId', $student->id)
            ->call('toggleCreateForm')
            ->assertSet('showCreateForm', true)
            ->assertSet('editingStudentId', null)
            ->assertSet('studentNumber', '');
    }

    public function test_creating_a_student_while_another_is_being_edited_does_not_duplicate_its_unique_fields(): void
    {
        // 使用者實測回報的 500 錯誤：編輯表單開著時點「新增學生」，新增
        // 表單會沿用正在編輯那位學生的學號／座號，同一班內撞到 unique
        // 限制直接噴 500，而不是正常顯示驗證錯誤。
        $class = SchoolClass::factory()->create();
        $existing = Student::factory()->for($class, 'schoolClass')->create([
            'student_number' => '10001', 'seat_number' => '1', 'name' => '正在編輯的學生',
        ]);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->call('startEdit', $existing->id)
            ->call('toggleCreateForm')
            ->set('studentNumber', '20002')
            ->set('seatNumber', '2')
            ->set('name', '全新的學生')
            ->set('gender', '男')
            ->call('createStudent')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('students', ['student_number' => '20002', 'name' => '全新的學生']);
        $this->assertSame('10001', $existing->fresh()->student_number);
    }
}
