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

    public function test_no_non_current_period_warning_is_shown_by_default(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(StatusBoard::class)
            ->assertDontSee('非本學期');
    }

    public function test_a_non_current_period_warning_is_shown_after_switching_away(): void
    {
        // 即時看板是每天會一直盯著看的頁面，停留在別的學期卻沒發現特別
        // 容易造成誤判（以為今天都沒人點名，其實是看錯學期）。
        AcademicPeriod::setSelected(AcademicPeriod::currentYear() + 1, 1);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(StatusBoard::class)
            ->assertSee('非本學期');
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

    public function test_a_class_with_no_sessions_for_the_day_shows_every_period_as_not_submitted(): void
    {
        $class = SchoolClass::factory()->create();
        Student::factory()->for($class, 'schoolClass')->count(3)->create();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(StatusBoard::class)
            ->assertSee('未點名')
            ->assertSee($class->shortLabel());
    }

    public function test_late_counts_as_present_and_early_leave_counts_as_absent(): void
    {
        // 看板把四種狀態併成「出席」（出席、遲到）跟「缺席」（缺席、
        // 早退）兩欄——遲到人還是有到校，早退人提早離校，見
        // AttendanceStatus::countsAsPresent()。
        $class = SchoolClass::factory()->create();
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('homeroom_teacher');
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);
        $class->update(['homeroom_teacher_id' => $teacher->id]);

        $late = Student::factory()->for($class, 'schoolClass')->create(['name' => '遲到同學']);
        $earlyLeave = Student::factory()->for($class, 'schoolClass')->create(['name' => '早退同學']);
        $absent = Student::factory()->for($class, 'schoolClass')->create(['name' => '缺席同學']);
        $present = Student::factory()->for($class, 'schoolClass')->create(['name' => '出席同學']);

        $period = AttendancePeriods::current();

        $session = $class->attendanceSessions()->create([
            'date' => now()->toDateString(),
            'period' => $period,
            'recorded_by' => $teacherUser->id,
        ]);
        $session->records()->createMany([
            ['student_id' => $late->id, 'status' => AttendanceStatus::Late, 'updated_by' => $teacherUser->id],
            ['student_id' => $earlyLeave->id, 'status' => AttendanceStatus::EarlyLeave, 'updated_by' => $teacherUser->id],
            ['student_id' => $absent->id, 'status' => AttendanceStatus::Absent, 'updated_by' => $teacherUser->id],
            ['student_id' => $present->id, 'status' => AttendanceStatus::Present, 'updated_by' => $teacherUser->id],
        ]);

        $summaries = Livewire::actingAs($teacherUser)->test(StatusBoard::class)->viewData('summaries');
        $summary = $summaries->first(fn ($summary) => $summary['class']->is($class));

        $this->assertSame(2, $summary['periods'][$period]['present']);
        $this->assertSame(2, $summary['periods'][$period]['absent']);

        // 需留意學生只列缺席跟早退，遲到不算例外，出席更不用說。
        $exceptionNames = $summary['exceptions']->pluck('name');
        $this->assertTrue($exceptionNames->contains('缺席同學'));
        $this->assertTrue($exceptionNames->contains('早退同學'));
        $this->assertFalse($exceptionNames->contains('遲到同學'));
        $this->assertFalse($exceptionNames->contains('出席同學'));
    }

    public function test_exception_entries_carry_their_follow_up_notes_for_the_hover_tooltip(): void
    {
        // 「需留意學生」游標懸浮要能看到處理情形，資料要跟著彙總結果
        // 一起帶出來（見 StatusBoard::render() 的 with(['records.followUps'])），
        // 不是等使用者真的懸浮時才另外查。
        $class = SchoolClass::factory()->create();
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('homeroom_teacher');
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);
        $class->update(['homeroom_teacher_id' => $teacher->id]);

        $student = Student::factory()->for($class, 'schoolClass')->create(['name' => '缺席同學']);

        $session = $class->attendanceSessions()->create([
            'date' => now()->toDateString(),
            'period' => AttendancePeriods::current(),
            'recorded_by' => $teacherUser->id,
        ]);
        $record = $session->records()->create([
            'student_id' => $student->id,
            'status' => AttendanceStatus::Absent,
            'updated_by' => $teacherUser->id,
        ]);
        $record->followUps()->create(['created_by' => $teacherUser->id, 'content' => '電聯未接']);

        Livewire::actingAs($teacherUser)
            ->test(StatusBoard::class)
            ->assertSee('缺席同學')
            ->assertSee('電聯未接');
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
