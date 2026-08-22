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
        $student = Student::factory()->for($schoolClass, 'schoolClass')->create();
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

    public function test_student_rep_cannot_add_a_follow_up_even_for_their_own_class(): void
    {
        // 副班長有 attendance.record 權限，但沒有 attendance.follow_up.manage
        // ——能點名，不代表能寫「處理情形」，這是兩種不同的授權。
        $class = SchoolClass::factory()->create();
        $record = $this->absentRecordFor($class);

        $repUser = User::factory()->create();
        $repUser->assignRole('student_rep');
        Student::factory()->for($class, 'schoolClass')->create(['user_id' => $repUser->id]);

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
