<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Livewire\Attendance\FollowUpManager;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FollowUpManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function absentRecordFor(SchoolClass $schoolClass): AttendanceRecord
    {
        $student = Student::factory()->forClass($schoolClass)->create();
        $session = AttendanceSession::factory()->for($schoolClass, 'schoolClass')->create();

        return AttendanceRecord::factory()->for($session, 'attendanceSession')->create([
            'student_id' => $student->id,
            'status' => AttendanceStatus::Absent,
        ]);
    }

    public function test_homeroom_teacher_of_the_class_can_add_a_follow_up(): void
    {
        $class = SchoolClass::factory()->create();
        $record = $this->absentRecordFor($class);

        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('homeroom_teacher');
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);
        $class->update(['homeroom_teacher_id' => $teacher->id]);

        Livewire::actingAs($teacherUser)
            ->test(FollowUpManager::class, ['record' => $record])
            ->set('content', '電聯未接')
            ->call('addFollowUp')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('attendance_follow_ups', [
            'attendance_record_id' => $record->id,
            'created_by' => $teacherUser->id,
            'content' => '電聯未接',
        ]);
    }

    public function test_admin_can_add_a_follow_up_to_any_class(): void
    {
        $class = SchoolClass::factory()->create();
        $record = $this->absentRecordFor($class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(FollowUpManager::class, ['record' => $record])
            ->set('content', '9:19已到')
            ->call('addFollowUp')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('attendance_follow_ups', ['content' => '9:19已到']);
    }

    public function test_a_custom_role_with_classes_manage_permission_can_add_a_follow_up_to_any_class(): void
    {
        // 跟 RecorderTest 那個回歸測試同一個問題：自訂身分即使被賦予
        // attendance.follow_up.manage，只要沒有連結 Teacher/Student、
        // ownSchoolClasses() 是空集合，以前就完全不能管理任何班級的
        // 處理情形——AttendanceRecordPolicy::manageFollowUp() 改成檢查
        // can('classes.manage') 之後才會跟 admin 一樣不受此限制。
        $class = SchoolClass::factory()->create();
        $record = $this->absentRecordFor($class);

        $role = Role::create(['name' => 'exam_supervisor', 'guard_name' => 'web']);
        $role->syncPermissions(['attendance.follow_up.manage', 'classes.manage']);

        $user = User::factory()->create();
        $user->assignRole('exam_supervisor');

        Livewire::actingAs($user)
            ->test(FollowUpManager::class, ['record' => $record])
            ->set('content', '9:19已到')
            ->call('addFollowUp')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('attendance_follow_ups', ['content' => '9:19已到']);
    }

    public function test_a_student_can_add_a_follow_up_for_their_own_class(): void
    {
        // 學生身分（角色代號 student_rep）現在也有 attendance.follow_up.manage
        // 權限，自己班級的處理情形填得了。
        $class = SchoolClass::factory()->create();
        $record = $this->absentRecordFor($class);

        $repUser = User::factory()->create();
        $repUser->assignRole('student_rep');
        Student::factory()->forClass($class)->create(['user_id' => $repUser->id]);

        Livewire::actingAs($repUser)
            ->test(FollowUpManager::class, ['record' => $record])
            ->set('content', '已電聯家長')
            ->call('addFollowUp')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('attendance_follow_ups', [
            'attendance_record_id' => $record->id,
            'content' => '已電聯家長',
            'created_by' => $repUser->id,
        ]);
    }

    public function test_a_student_cannot_add_a_follow_up_for_another_class(): void
    {
        // 有權限不等於沒有範圍限制——學生能填處理情形之後，「只限自己
        // 班級」這一層就成了唯一擋住他碰到別班紀錄的防線。
        $class = SchoolClass::factory()->create();
        $otherClass = SchoolClass::factory()->create(['grade' => 2]);
        $record = $this->absentRecordFor($class);

        $repUser = User::factory()->create();
        $repUser->assignRole('student_rep');
        Student::factory()->forClass($otherClass)->create(['user_id' => $repUser->id]);

        Livewire::actingAs($repUser)
            ->test(FollowUpManager::class, ['record' => $record])
            ->assertForbidden();

        $this->assertDatabaseMissing('attendance_follow_ups', ['attendance_record_id' => $record->id]);
    }

    public function test_homeroom_teacher_of_a_different_class_cannot_add_a_follow_up(): void
    {
        $class = SchoolClass::factory()->create();
        $otherClass = SchoolClass::factory()->create(['grade' => 2]);
        $record = $this->absentRecordFor($class);

        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('homeroom_teacher');
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);
        $otherClass->update(['homeroom_teacher_id' => $teacher->id]);

        Livewire::actingAs($teacherUser)
            ->test(FollowUpManager::class, ['record' => $record])
            ->assertForbidden();
    }

    public function test_losing_the_follow_up_permission_mid_session_blocks_further_additions(): void
    {
        $class = SchoolClass::factory()->create();
        $record = $this->absentRecordFor($class);

        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('homeroom_teacher');
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);
        $class->update(['homeroom_teacher_id' => $teacher->id]);

        $component = Livewire::actingAs($teacherUser)
            ->test(FollowUpManager::class, ['record' => $record])
            ->set('content', '想搶著加的紀錄');

        $teacherUser->removeRole('homeroom_teacher');

        $component->call('addFollowUp')->assertForbidden();

        $this->assertDatabaseMissing('attendance_follow_ups', ['content' => '想搶著加的紀錄']);
    }

    public function test_creating_a_follow_up_is_recorded_in_the_activity_log(): void
    {
        $class = SchoolClass::factory()->create();
        $record = $this->absentRecordFor($class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(FollowUpManager::class, ['record' => $record])
            ->set('content', '請假一天')
            ->call('addFollowUp');

        $this->assertTrue(
            Activity::where('log_name', 'attendance_follow_up')
                ->where('causer_id', $admin->id)
                ->exists()
        );
    }
}
