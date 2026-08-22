<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Livewire\Attendance\Recorder;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecorderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function studentRepFor(SchoolClass $schoolClass): User
    {
        $rep = User::factory()->create();
        $rep->assignRole('student_rep');
        Student::factory()->for($schoolClass, 'schoolClass')->create(['user_id' => $rep->id]);

        return $rep;
    }

    private function homeroomTeacherFor(SchoolClass $schoolClass): User
    {
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('homeroom_teacher');
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);
        $schoolClass->update(['homeroom_teacher_id' => $teacher->id]);

        return $teacherUser;
    }

    public function test_guest_is_redirected_away_from_the_attendance_page(): void
    {
        $class = SchoolClass::factory()->create();

        $this->get("/attendance/{$class->id}")->assertRedirect('/');
    }

    public function test_student_rep_of_a_different_class_is_forbidden(): void
    {
        $class = SchoolClass::factory()->create();
        $otherClass = SchoolClass::factory()->create();
        $rep = $this->studentRepFor($otherClass);

        $this->actingAs($rep)->get("/attendance/{$class->id}")->assertForbidden();
    }

    public function test_student_rep_can_view_their_own_classes_attendance_page(): void
    {
        $class = SchoolClass::factory()->create();
        Student::factory()->for($class, 'schoolClass')->create(['name' => '陳小明']);
        $rep = $this->studentRepFor($class);

        $this->actingAs($rep)
            ->get("/attendance/{$class->id}")
            ->assertOk()
            ->assertSee('陳小明');
    }

    public function test_homeroom_teacher_can_view_their_own_classes_attendance_page(): void
    {
        $class = SchoolClass::factory()->create();
        $teacherUser = $this->homeroomTeacherFor($class);

        $this->actingAs($teacherUser)
            ->get("/attendance/{$class->id}")
            ->assertOk();
    }

    public function test_admin_can_view_any_classes_attendance_page(): void
    {
        $class = SchoolClass::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get("/attendance/{$class->id}")
            ->assertOk();
    }

    public function test_mine_route_redirects_student_rep_to_their_own_class(): void
    {
        $class = SchoolClass::factory()->create();
        $rep = $this->studentRepFor($class);

        $this->actingAs($rep)
            ->get('/attendance')
            ->assertRedirect(route('attendance.show', $class));
    }

    public function test_mine_route_redirects_homeroom_teacher_to_their_own_class(): void
    {
        $class = SchoolClass::factory()->create();
        $teacherUser = $this->homeroomTeacherFor($class);

        $this->actingAs($teacherUser)
            ->get('/attendance')
            ->assertRedirect(route('attendance.show', $class));
    }

    public function test_mine_route_redirects_teacher_to_their_most_recent_homeroom_class(): void
    {
        // 一位導師可能帶過好幾個學年度的班級（每學年的班級都是獨立紀錄），
        // /attendance 應該導去「現在」帶的那個班，不是隨機/最舊的一筆。
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('homeroom_teacher');
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);

        $oldClass = SchoolClass::factory()->create([
            'academic_year' => 112, 'homeroom_teacher_id' => $teacher->id,
        ]);
        $currentClass = SchoolClass::factory()->create([
            'academic_year' => 113, 'homeroom_teacher_id' => $teacher->id,
        ]);

        $this->actingAs($teacherUser)
            ->get('/attendance')
            ->assertRedirect(route('attendance.show', $currentClass));

        // 導師換帶新班級後，舊班級的點名權限自然收回——那個班級的紀錄已經
        // 隨學年凍結，不需要（也不應該）再被繼續編輯，管理者仍可用 admin
        // 身份處理歷史資料的例外狀況。
        $this->actingAs($teacherUser)->get("/attendance/{$oldClass->id}")->assertForbidden();
    }

    public function test_mine_route_redirects_to_dashboard_when_no_class_is_linked(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get('/attendance')
            ->assertRedirect(route('dashboard'));
    }

    public function test_defaults_to_present_for_everyone_when_no_session_exists_yet(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->for($class, 'schoolClass')->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->assertSet("statuses.{$student->id}", AttendanceStatus::Present->value);
    }

    public function test_mark_all_present_resets_every_students_status(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->for($class, 'schoolClass')->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set("statuses.{$student->id}", AttendanceStatus::Absent->value)
            ->call('markAllPresent')
            ->assertSet("statuses.{$student->id}", AttendanceStatus::Present->value);
    }

    public function test_submit_creates_a_session_and_records_for_every_student(): void
    {
        $class = SchoolClass::factory()->create();
        $students = Student::factory()->for($class, 'schoolClass')->count(3)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set("statuses.{$students[1]->id}", AttendanceStatus::Late->value)
            ->call('submit');

        $this->assertDatabaseCount('attendance_sessions', 1);

        $session = AttendanceSession::first();
        $this->assertSame($class->id, $session->school_class_id);
        $this->assertSame($admin->id, $session->recorded_by);
        $this->assertSame(3, $session->records()->count());

        $lateRecord = AttendanceRecord::where('student_id', $students[1]->id)->first();
        $this->assertSame(AttendanceStatus::Late, $lateRecord->status);
        $this->assertSame($admin->id, $lateRecord->updated_by);
    }

    public function test_resubmitting_the_same_period_updates_the_existing_session_instead_of_duplicating(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->for($class, 'schoolClass')->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $component = Livewire::actingAs($admin)->test(Recorder::class, ['schoolClass' => $class]);
        $component->call('submit');
        $component->set("statuses.{$student->id}", AttendanceStatus::Late->value)->call('submit');

        $this->assertDatabaseCount('attendance_sessions', 1);
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertSame(AttendanceStatus::Late, AttendanceRecord::first()->status);
    }

    public function test_reopening_an_already_submitted_session_loads_its_saved_statuses(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->for($class, 'schoolClass')->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set("statuses.{$student->id}", AttendanceStatus::SickLeave->value)
            ->call('submit');

        // 重新進入同一個班級/日期/時段的點名頁，應該要看到剛剛存的狀態，
        // 而不是又預設回「全部出席」。
        Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->assertSet("statuses.{$student->id}", AttendanceStatus::SickLeave->value);
    }

    public function test_switching_period_loads_a_different_sessions_statuses(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->for($class, 'schoolClass')->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set('period', 'MORNING')
            ->set("statuses.{$student->id}", AttendanceStatus::Late->value)
            ->call('submit')
            ->set('period', 'NOON')
            ->assertSet("statuses.{$student->id}", AttendanceStatus::Present->value);
    }

    public function test_losing_access_to_the_class_mid_session_blocks_further_submissions(): void
    {
        // can:recordAttendance,schoolClass 的路由 middleware 只在整頁載入
        // 那一刻檢查一次；Recorder::boot() 是專門補這個洞的第二層檢查，
        // 這裡驗證它真的每次互動請求都會重跑，不是只在第一次 mount 生效。
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->for($class, 'schoolClass')->create();
        $rep = $this->studentRepFor($class);

        $component = Livewire::actingAs($rep)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set("statuses.{$student->id}", AttendanceStatus::Absent->value);

        // 副班長被拔掉權限（例如轉班、畢業、或帳號被停權後移除角色）。
        $rep->removeRole('student_rep');

        $component->call('submit')->assertForbidden();

        $this->assertDatabaseMissing('attendance_records', ['student_id' => $student->id]);
    }

    public function test_submit_ignores_injected_student_ids_from_outside_the_class(): void
    {
        // $statuses 是 wire:model 綁定的 public 陣列屬性，client 端的更新
        // 請求理論上可以夾帶任意 key。submit() 必須只信任伺服器端查出來的
        // 班級名單，不能直接把 $statuses 的每個 key 都當成合法學生寫入。
        $class = SchoolClass::factory()->create(['grade' => 1]);
        Student::factory()->for($class, 'schoolClass')->create();

        $otherClass = SchoolClass::factory()->create(['grade' => 2]);
        $foreignStudent = Student::factory()->for($otherClass, 'schoolClass')->create();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set("statuses.{$foreignStudent->id}", AttendanceStatus::Absent->value)
            ->call('submit');

        $this->assertDatabaseMissing('attendance_records', ['student_id' => $foreignStudent->id]);
    }
}
