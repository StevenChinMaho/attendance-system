<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\ClassRosterManager;
use App\Models\AttendanceRecord;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 加入/移出某個班級的既有學生——不是建立學生本體，那是 StudentManagerTest
 * 的事。見 App\Livewire\Admin\ClassRosterManager 開頭的說明。
 */
class ClassRosterManagerTest extends TestCase
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

    public function test_guest_is_redirected_away_from_the_roster_page(): void
    {
        $class = SchoolClass::factory()->create();

        $this->get("/admin/classes/{$class->id}/students")->assertRedirect('/');
    }

    public function test_non_admin_is_forbidden_from_the_roster_page(): void
    {
        $class = SchoolClass::factory()->create();
        $rep = User::factory()->create();
        $rep->assignRole('student_rep');

        $this->actingAs($rep)
            ->get("/admin/classes/{$class->id}/students")
            ->assertForbidden();
    }

    public function test_admin_can_attach_an_existing_student_to_the_class(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(ClassRosterManager::class, ['schoolClass' => $class])
            ->set('attachingStudentId', $student->id)
            ->set('seatNumber', '1')
            ->call('attachStudent')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('school_class_student', [
            'school_class_id' => $class->id,
            'student_id' => $student->id,
            'seat_number' => '1',
        ]);
    }

    public function test_attaching_a_student_already_in_the_class_fails_validation(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class, '1')->create();

        Livewire::actingAs($this->admin())
            ->test(ClassRosterManager::class, ['schoolClass' => $class])
            ->set('attachingStudentId', $student->id)
            ->set('seatNumber', '2')
            ->call('attachStudent')
            ->assertHasErrors('attachingStudentId');
    }

    public function test_duplicate_seat_number_within_the_class_fails_validation(): void
    {
        $class = SchoolClass::factory()->create();
        Student::factory()->forClass($class, '1')->create();
        $newStudent = Student::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(ClassRosterManager::class, ['schoolClass' => $class])
            ->set('attachingStudentId', $newStudent->id)
            ->set('seatNumber', '1')
            ->call('attachStudent')
            ->assertHasErrors('seatNumber');
    }

    public function test_a_student_can_be_attached_to_more_than_one_class(): void
    {
        // 多對多的重點——同一個學生（例如跨學期升級）可以同時連到好幾筆
        // SchoolClass，見 SchoolClass::students() 的說明。
        $oldClass = SchoolClass::factory()->create();
        $newClass = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($oldClass, '1')->create();

        Livewire::actingAs($this->admin())
            ->test(ClassRosterManager::class, ['schoolClass' => $newClass])
            ->set('attachingStudentId', $student->id)
            ->set('seatNumber', '5')
            ->call('attachStudent')
            ->assertHasNoErrors();

        $fresh = $student->fresh();
        $this->assertCount(2, $fresh->schoolClasses);
    }

    public function test_admin_can_update_a_students_seat_number_in_the_class(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class, '1')->create();

        Livewire::actingAs($this->admin())
            ->test(ClassRosterManager::class, ['schoolClass' => $class])
            ->call('startEditSeat', $student->id)
            ->assertSet('seatNumber', '1')
            ->set('seatNumber', '9')
            ->call('updateSeat')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('school_class_student', [
            'school_class_id' => $class->id,
            'student_id' => $student->id,
            'seat_number' => '9',
        ]);
    }

    public function test_updating_a_seat_number_to_one_already_taken_fails_validation(): void
    {
        $class = SchoolClass::factory()->create();
        Student::factory()->forClass($class, '1')->create();
        $student = Student::factory()->forClass($class, '2')->create();

        Livewire::actingAs($this->admin())
            ->test(ClassRosterManager::class, ['schoolClass' => $class])
            ->call('startEditSeat', $student->id)
            ->set('seatNumber', '1')
            ->call('updateSeat')
            ->assertHasErrors('seatNumber');
    }

    public function test_admin_can_remove_a_student_from_the_class(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class, '1')->create();

        Livewire::actingAs($this->admin())
            ->test(ClassRosterManager::class, ['schoolClass' => $class])
            ->call('removeStudent', $student->id);

        // 移出班級不會刪除學生本體，只是拆掉這筆連結。
        $this->assertModelExists($student);
        $this->assertCount(0, $student->fresh()->schoolClasses);
    }

    public function test_removing_a_student_does_not_delete_their_attendance_history(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class, '1')->create();
        $record = AttendanceRecord::factory()->for($student)->create();

        Livewire::actingAs($this->admin())
            ->test(ClassRosterManager::class, ['schoolClass' => $class])
            ->call('removeStudent', $student->id);

        $this->assertModelExists($record);
    }

    public function test_a_student_from_another_class_cannot_be_removed_through_this_page(): void
    {
        $class = SchoolClass::factory()->create();
        $otherClass = SchoolClass::factory()->create();
        $studentInOtherClass = Student::factory()->forClass($otherClass, '1')->create();

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->admin())
            ->test(ClassRosterManager::class, ['schoolClass' => $class])
            ->call('removeStudent', $studentInOtherClass->id);
    }

    public function test_opening_the_attach_form_while_editing_a_seat_number_closes_that_form(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class, '1')->create();

        Livewire::actingAs($this->admin())
            ->test(ClassRosterManager::class, ['schoolClass' => $class])
            ->call('startEditSeat', $student->id)
            ->assertSet('editingSeatForStudentId', $student->id)
            ->call('toggleAttachForm')
            ->assertSet('showAttachForm', true)
            ->assertSet('editingSeatForStudentId', null);
    }
}
