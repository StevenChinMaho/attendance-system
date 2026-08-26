<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Livewire\Attendance\MorningAbsenceList;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Support\AcademicPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MorningAbsenceListTest extends TestCase
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

    private function classIn(int $grade, int $classNumber): SchoolClass
    {
        return SchoolClass::factory()->create([
            'academic_year' => AcademicPeriod::currentYear(),
            'semester' => AcademicPeriod::currentSemester(),
            'grade' => $grade,
            'class_number' => $classNumber,
        ]);
    }

    /**
     * 建立一次上午點名，statuses 是 [學生 => 狀態]。
     *
     * @param  array<int, AttendanceStatus>  $statuses
     */
    private function recordMorning(SchoolClass $class, array $statuses, ?string $date = null): void
    {
        $recorder = User::factory()->create();
        $recorder->assignRole('admin');

        $session = $class->attendanceSessions()->create([
            'date' => $date ?? now()->toDateString(),
            'period' => MorningAbsenceList::PERIOD,
            'recorded_by' => $recorder->id,
        ]);

        foreach ($statuses as $studentId => $status) {
            $session->records()->create([
                'student_id' => $studentId,
                'status' => $status,
                'updated_by' => $recorder->id,
            ]);
        }
    }

    public function test_guest_is_redirected_away(): void
    {
        $this->get('/attendance/morning-absences')->assertRedirect('/');
    }

    public function test_a_student_is_forbidden(): void
    {
        // 這是全校範圍的檢視，權限比照即時看板——學生只該看得到自己班。
        $rep = User::factory()->create();
        $rep->assignRole('student_rep');

        $this->actingAs($rep)->get('/attendance/morning-absences')->assertForbidden();
    }

    public function test_a_homeroom_teacher_can_view_it(): void
    {
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('homeroom_teacher');

        $this->actingAs($teacherUser)
            ->get('/attendance/morning-absences')
            ->assertOk()
            ->assertSee('上午缺席詳細清單');
    }

    public function test_losing_the_permission_mid_session_blocks_further_interaction(): void
    {
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('homeroom_teacher');

        $component = Livewire::actingAs($teacherUser)->test(MorningAbsenceList::class);
        $component->assertOk();

        $teacherUser->removeRole('homeroom_teacher');

        $component->call('$refresh')->assertForbidden();
    }

    public function test_absent_and_early_leave_students_are_listed_but_late_and_present_are_not(): void
    {
        $class = $this->classIn(1, 1);
        $absent = Student::factory()->forClass($class, '3')->create(['name' => '黃小明']);
        $earlyLeave = Student::factory()->forClass($class, '5')->create(['name' => '金小華']);
        $late = Student::factory()->forClass($class, '7')->create(['name' => '遲到同學']);
        $present = Student::factory()->forClass($class, '9')->create(['name' => '出席同學']);

        $this->recordMorning($class, [
            $absent->id => AttendanceStatus::Absent,
            $earlyLeave->id => AttendanceStatus::EarlyLeave,
            $late->id => AttendanceStatus::Late,
            $present->id => AttendanceStatus::Present,
        ]);

        $rows = Livewire::actingAs($this->admin())->test(MorningAbsenceList::class)->viewData('rows');
        $names = $rows->first()['absentees']->pluck('name');

        $this->assertTrue($names->contains('黃小明'));
        $this->assertTrue($names->contains('金小華'));
        $this->assertFalse($names->contains('遲到同學'), '遲到的人有到校，不列進這張表');
        $this->assertFalse($names->contains('出席同學'));
    }

    public function test_a_class_with_a_submitted_session_and_no_absentees_shows_as_all_present(): void
    {
        $class = $this->classIn(1, 4);
        $student = Student::factory()->forClass($class, '1')->create();
        $this->recordMorning($class, [$student->id => AttendanceStatus::Present]);

        $row = Livewire::actingAs($this->admin())->test(MorningAbsenceList::class)->viewData('rows')->first();

        $this->assertTrue($row['submitted']);
        $this->assertCount(0, $row['absentees']);
    }

    public function test_a_class_that_never_submitted_is_still_listed(): void
    {
        // 「未送出」跟「到齊」在紙本上的意義完全相反——沒送出的班級如果
        // 直接不出現在表上，學務處就看不出來漏了誰。
        $class = $this->classIn(2, 2);
        Student::factory()->forClass($class, '1')->create();

        $row = Livewire::actingAs($this->admin())->test(MorningAbsenceList::class)->viewData('rows')->first();

        $this->assertFalse($row['submitted']);
        $this->assertCount(0, $row['absentees']);
    }

    public function test_the_page_shows_both_all_present_and_not_submitted_labels(): void
    {
        $allPresent = $this->classIn(1, 4);
        $student = Student::factory()->forClass($allPresent, '1')->create();
        $this->recordMorning($allPresent, [$student->id => AttendanceStatus::Present]);

        $notSubmitted = $this->classIn(2, 2);
        Student::factory()->forClass($notSubmitted, '1')->create();

        Livewire::actingAs($this->admin())
            ->test(MorningAbsenceList::class)
            ->assertSee('到齊')
            ->assertSee('未送出');
    }

    public function test_classes_are_listed_by_their_three_digit_code_in_order(): void
    {
        $this->classIn(2, 3);
        $this->classIn(1, 11);
        $this->classIn(1, 2);

        $codes = Livewire::actingAs($this->admin())
            ->test(MorningAbsenceList::class)
            ->viewData('rows')
            ->pluck('code')
            ->all();

        $this->assertSame(['102', '111', '203'], $codes);
    }

    public function test_absentees_are_ordered_by_seat_number_naturally(): void
    {
        // 座號是字串欄位，直接排序會讓 "10" 跑到 "2" 前面。
        $class = $this->classIn(1, 1);
        $seat22 = Student::factory()->forClass($class, '22')->create(['name' => '張同學']);
        $seat8 = Student::factory()->forClass($class, '8')->create(['name' => '許同學']);
        $seat12 = Student::factory()->forClass($class, '12')->create(['name' => '林同學']);

        $this->recordMorning($class, [
            $seat22->id => AttendanceStatus::Absent,
            $seat8->id => AttendanceStatus::Absent,
            $seat12->id => AttendanceStatus::Absent,
        ]);

        $seats = Livewire::actingAs($this->admin())
            ->test(MorningAbsenceList::class)
            ->viewData('rows')
            ->first()['absentees']
            ->pluck('seat_number')
            ->all();

        $this->assertSame(['8', '12', '22'], $seats);
    }

    public function test_only_the_morning_period_is_included(): void
    {
        $class = $this->classIn(1, 1);
        $student = Student::factory()->forClass($class, '1')->create(['name' => '下午缺席同學']);

        $recorder = $this->admin();
        $session = $class->attendanceSessions()->create([
            'date' => now()->toDateString(),
            'period' => 'AFTERNOON',
            'recorded_by' => $recorder->id,
        ]);
        $session->records()->create([
            'student_id' => $student->id,
            'status' => AttendanceStatus::Absent,
            'updated_by' => $recorder->id,
        ]);

        $row = Livewire::actingAs($recorder)->test(MorningAbsenceList::class)->viewData('rows')->first();

        // 下午的 session 不算數，這一班上午是「未送出」。
        $this->assertFalse($row['submitted']);
        $this->assertCount(0, $row['absentees']);
    }

    public function test_switching_the_date_shows_that_days_list(): void
    {
        $class = $this->classIn(1, 1);
        $student = Student::factory()->forClass($class, '3')->create(['name' => '昨天缺席同學']);
        $yesterday = now()->subDay()->toDateString();

        $this->recordMorning($class, [$student->id => AttendanceStatus::Absent], $yesterday);

        $component = Livewire::actingAs($this->admin())->test(MorningAbsenceList::class);

        // 預設是今天，昨天那筆不該出現。
        $this->assertCount(0, $component->viewData('rows')->first()['absentees']);

        $component->set('date', $yesterday);
        $this->assertCount(1, $component->viewData('rows')->first()['absentees']);
        $component->assertSee('昨天缺席同學')->assertSee('非本日');
    }

    public function test_classes_outside_the_selected_academic_period_are_not_listed(): void
    {
        SchoolClass::factory()->create(['academic_year' => 112, 'semester' => 1, 'grade' => 1, 'class_number' => 9]);
        $this->classIn(1, 1);

        $codes = Livewire::actingAs($this->admin())
            ->test(MorningAbsenceList::class)
            ->viewData('rows')
            ->pluck('code')
            ->all();

        $this->assertSame(['101'], $codes);
    }

    public function test_a_student_removed_from_the_class_still_shows_their_record(): void
    {
        // 移出班級不會刪掉點名紀錄（見 ClassRosterManager）——紙本上寧可
        // 標示查不到，也不要整列消失讓人數對不起來。
        $class = $this->classIn(1, 1);
        $student = Student::factory()->forClass($class, '3')->create(['name' => '已轉走同學']);
        $this->recordMorning($class, [$student->id => AttendanceStatus::Absent]);

        $class->students()->detach($student->id);

        $absentees = Livewire::actingAs($this->admin())
            ->test(MorningAbsenceList::class)
            ->viewData('rows')
            ->first()['absentees'];

        $this->assertCount(1, $absentees);
        $this->assertSame('（已不在此班級）', $absentees->first()['name']);
    }

    public function test_the_print_only_header_carries_the_title_and_the_listed_date(): void
    {
        // 瀏覽器自己印的頁首日期是「列印當下」，補印昨天的名單時會是錯的
        // ——紙本的標題與日期必須由頁面自己畫一份。
        $this->classIn(1, 1);
        $yesterday = now()->subDay();

        Livewire::actingAs($this->admin())
            ->test(MorningAbsenceList::class)
            ->set('date', $yesterday->toDateString())
            ->assertSee('上午缺席詳細清單')
            ->assertSee($yesterday->format('Y-m-d'))
            ->assertSeeHtml('window.print()');
    }
}
