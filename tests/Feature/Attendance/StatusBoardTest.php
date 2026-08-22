<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Livewire\Attendance\StatusBoard;
use App\Livewire\Concerns\AttendancePeriods;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\AcademicPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StatusBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guest_is_redirected_away_from_the_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/');
    }

    public function test_student_rep_sees_the_plain_welcome_page_not_the_board(): void
    {
        $rep = User::factory()->create();
        $rep->assignRole('student_rep');

        $this->actingAs($rep)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('即時點名看板');
    }

    public function test_homeroom_teacher_sees_the_status_board(): void
    {
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('homeroom_teacher');

        $this->actingAs($teacherUser)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('即時點名看板');
    }

    public function test_admin_sees_the_status_board(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('即時點名看板');
    }

    public function test_losing_the_dashboard_permission_mid_session_blocks_further_polling(): void
    {
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('homeroom_teacher');

        $component = Livewire::actingAs($teacherUser)->test(StatusBoard::class);
        $component->assertOk();

        $teacherUser->removeRole('homeroom_teacher');

        // wire:poll 觸發的其實就是一次 $refresh 呼叫。
        $component->call('$refresh')->assertForbidden();
    }

    public function test_a_class_with_no_session_for_the_selected_period_shows_as_not_submitted(): void
    {
        $class = SchoolClass::factory()->create();
        Student::factory()->for($class, 'schoolClass')->count(3)->create();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(StatusBoard::class)
            ->assertSee('尚未點名')
            ->assertSee($class->shortLabel());
    }

    public function test_a_submitted_session_shows_correct_counts_and_exception_list(): void
    {
        $class = SchoolClass::factory()->create();
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('homeroom_teacher');
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);
        $class->update(['homeroom_teacher_id' => $teacher->id]);

        $absent = Student::factory()->for($class, 'schoolClass')->create(['name' => '缺席同學']);
        $present = Student::factory()->for($class, 'schoolClass')->create(['name' => '出席同學']);

        $session = $class->attendanceSessions()->create([
            'date' => now()->toDateString(),
            'period' => AttendancePeriods::current(),
            'recorded_by' => $teacherUser->id,
        ]);
        $session->records()->create([
            'student_id' => $absent->id,
            'status' => AttendanceStatus::Absent,
            'updated_by' => $teacherUser->id,
        ]);
        $session->records()->create([
            'student_id' => $present->id,
            'status' => AttendanceStatus::Present,
            'updated_by' => $teacherUser->id,
        ]);

        Livewire::actingAs($teacherUser)
            ->test(StatusBoard::class)
            ->assertSee('已點名')
            ->assertSee('缺席同學')
            ->assertDontSee('出席同學'); // 出席不算例外，不需要在「需留意學生」裡列出
    }

    public function test_classes_outside_the_selected_academic_period_are_not_shown(): void
    {
        // 看板只顯示 nav bar 目前選取的學年度／學期，其他學年度已經凍結
        // 的班級不該混在「目前」的總覽裡——見 App\Support\AcademicPeriod。
        $otherYearClass = SchoolClass::factory()->create(['academic_year' => 112]);
        $currentClass = SchoolClass::factory()->create();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(StatusBoard::class)
            ->assertSee($currentClass->shortLabel())
            ->assertDontSee($otherYearClass->shortLabel());
    }

    public function test_switching_the_academic_period_immediately_refreshes_the_board(): void
    {
        $currentClass = SchoolClass::factory()->create(['grade' => 1, 'class_number' => '1']);
        $otherClass = SchoolClass::factory()->create(['academic_year' => 112, 'semester' => 1, 'grade' => 1, 'class_number' => '2']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $component = Livewire::actingAs($admin)->test(StatusBoard::class);
        $component->assertSee($currentClass->shortLabel())->assertDontSee($otherClass->shortLabel());

        // 模擬 nav bar 的 AcademicPeriodSwitcher 已經把新選擇寫進 session，
        // 並廣播 academic-period-changed 事件——看板不需要使用者自己手動
        // 重新整理頁面就要立刻反映新篩選。
        AcademicPeriod::setSelected(112, 1);
        $component->dispatch('academic-period-changed');

        $component->assertSee($otherClass->shortLabel())->assertDontSee($currentClass->shortLabel());
    }
}
