<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Livewire\Attendance\Recorder;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentDeparture;
use App\Models\Teacher;
use App\Models\User;
use App\Support\AcademicPeriod;
use App\Support\AttendanceWindow;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
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
        Student::factory()->forClass($schoolClass)->create(['user_id' => $rep->id]);

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
        Student::factory()->forClass($class)->create(['name' => '陳小明']);
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

    public function test_a_custom_role_with_classes_manage_permission_can_view_any_classes_attendance_page(): void
    {
        // 之前回報的問題：自訂身分即使被賦予了跟 admin 一樣的權限組合，
        // 只要沒有被指派到任何一個班（沒有連結 Teacher/Student，
        // ownSchoolClasses() 必定是空集合），就完全點不了名——因為
        // SchoolClassPolicy::recordAttendance() 以前檢查的是寫死的
        // hasRole('admin')，不是實際的權限。這裡驗證改成檢查
        // can('classes.manage') 之後，自訂身分也能跟 admin 一樣不受
        // ownSchoolClasses() 限制。
        $class = SchoolClass::factory()->create();

        $role = Role::create(['name' => 'exam_supervisor', 'guard_name' => 'web']);
        $role->syncPermissions(['attendance.record', 'classes.manage']);

        $user = User::factory()->create();
        $user->assignRole('exam_supervisor');

        $this->actingAs($user)
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

    public function test_mine_route_redirects_to_the_class_within_the_currently_selected_academic_period(): void
    {
        // 一位導師可能帶過好幾個學年度的班級（每學年的班級都是獨立紀錄），
        // /attendance 該自動導去「目前選取」學年度／學期裡的那一班（見
        // App\Support\AcademicPeriod），不是隨便挑一筆。
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('homeroom_teacher');
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);

        $oldClass = SchoolClass::factory()->create([
            'academic_year' => 112, 'semester' => 1, 'homeroom_teacher_id' => $teacher->id,
        ]);
        $currentClass = SchoolClass::factory()->create([
            'academic_year' => 113, 'semester' => 1, 'homeroom_teacher_id' => $teacher->id,
        ]);

        AcademicPeriod::setSelected(113, 1);
        $this->actingAs($teacherUser)
            ->get('/attendance')
            ->assertRedirect(route('attendance.show', $currentClass));

        // 換到舊班級所在的學年度，/attendance 自動導向的目標也跟著換——
        // 直接用網址造訪任一班仍然允許（見 SchoolClassPolicy、
        // User::ownSchoolClasses()，換帶新班不會自動收回舊班存取權），
        // 只有「自動導去哪一班」這件事跟著目前選取的學年度走。
        AcademicPeriod::setSelected(112, 1);
        $this->actingAs($teacherUser)
            ->get('/attendance')
            ->assertRedirect(route('attendance.show', $oldClass));

        $this->actingAs($teacherUser)->get("/attendance/{$oldClass->id}")->assertOk();
    }

    public function test_mine_route_shows_a_helpful_error_when_no_class_exists_in_the_selected_period(): void
    {
        $class = SchoolClass::factory()->create(['academic_year' => 112, 'semester' => 1]);
        $rep = $this->studentRepFor($class);

        // 目前選取的預設是「目前」學年度／學期，跟這位學生實際的班級
        // 所在學年度不同——不代表帳號沒有連結任何班級，只是不在目前
        // 選取的範圍裡，提示應該引導使用者切換學年度而不是聯絡管理者。
        $this->actingAs($rep)
            ->get('/attendance')
            ->assertRedirect(route('dashboard'));
    }

    public function test_teacher_with_concurrent_homeroom_classes_can_access_both(): void
    {
        // 一位導師理論上可能同時身兼不只一個班的導師（teachers.id 對
        // school_classes.homeroom_teacher_id 是一對多），兩班都要能點名，
        // 不能因為 ownSchoolClass() 只挑得出一筆就被擋掉另一班。
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('homeroom_teacher');
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);

        $classA = SchoolClass::factory()->create(['homeroom_teacher_id' => $teacher->id]);
        $classB = SchoolClass::factory()->create(['homeroom_teacher_id' => $teacher->id]);

        $this->actingAs($teacherUser)->get("/attendance/{$classA->id}")->assertOk();
        $this->actingAs($teacherUser)->get("/attendance/{$classB->id}")->assertOk();
    }

    public function test_nav_bar_shows_a_class_picker_when_the_account_has_multiple_classes(): void
    {
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('homeroom_teacher');
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);

        $classA = SchoolClass::factory()->create(['homeroom_teacher_id' => $teacher->id, 'grade' => 1, 'class_number' => '1']);
        $classB = SchoolClass::factory()->create(['homeroom_teacher_id' => $teacher->id, 'grade' => 2, 'class_number' => '2']);

        $response = $this->actingAs($teacherUser)->get('/dashboard');

        $response->assertSee($classA->shortLabel());
        $response->assertSee($classB->shortLabel());
    }

    public function test_nav_bar_shows_a_plain_link_when_the_account_has_only_one_class(): void
    {
        $class = SchoolClass::factory()->create();
        $rep = $this->studentRepFor($class);

        $this->actingAs($rep)
            ->get('/dashboard')
            ->assertSee(route('attendance.mine'), false);
    }

    public function test_mine_route_redirects_to_dashboard_when_no_class_is_linked(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get('/attendance')
            ->assertRedirect(route('dashboard'));
    }

    public function test_a_student_marked_as_left_before_today_does_not_appear_in_todays_roster(): void
    {
        $class = SchoolClass::factory()->create();
        $enrolled = Student::factory()->forClass($class)->create();
        $left = Student::factory()->forClass($class)->create();
        StudentDeparture::factory()->for($left)->create(['left_at' => now()->subDay()->toDateString(), 'returned_at' => null]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $studentIds = Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->viewData('students')
            ->pluck('id')
            ->all();

        $this->assertContains($enrolled->id, $studentIds);
        $this->assertNotContains($left->id, $studentIds);
    }

    public function test_a_student_who_left_today_still_appears_in_todays_roster(): void
    {
        // 轉出當天算他還在讀（當天可能上午還在校才辦轉學），要能繼續
        // 幫他點名，隔天才真正從名冊消失——見 Recorder::students()。
        $class = SchoolClass::factory()->create();
        $left = Student::factory()->forClass($class)->create();
        StudentDeparture::factory()->for($left)->create(['left_at' => now()->toDateString(), 'returned_at' => null]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $studentIds = Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->viewData('students')
            ->pluck('id')
            ->all();

        $this->assertContains($left->id, $studentIds);
    }

    public function test_a_student_who_left_appears_when_correcting_a_date_before_they_left(): void
    {
        $class = SchoolClass::factory()->create();
        $left = Student::factory()->forClass($class)->create();
        StudentDeparture::factory()->for($left)->create(['left_at' => now()->subDay()->toDateString(), 'returned_at' => null]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $studentIds = Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set('date', now()->subDays(2)->toDateString())
            ->viewData('students')
            ->pluck('id')
            ->all();

        $this->assertContains($left->id, $studentIds);
    }

    public function test_roster_correctly_excludes_a_student_across_multiple_separate_departure_periods(): void
    {
        // 轉出又轉入又轉出——同一個學生的第二段轉出不該覆蓋掉第一段的
        // 邊界，兩段轉出期間、跟中間那段轉入期間都要各自判斷正確。
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();
        StudentDeparture::factory()->for($student)->create(['left_at' => '2026-03-01', 'returned_at' => '2026-04-01']);
        StudentDeparture::factory()->for($student)->create(['left_at' => '2026-06-01', 'returned_at' => null]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $component = Livewire::actingAs($admin)->test(Recorder::class, ['schoolClass' => $class]);

        $duringFirstDeparture = $component->set('date', '2026-03-15')->viewData('students')->pluck('id')->all();
        $duringReturn = $component->set('date', '2026-04-15')->viewData('students')->pluck('id')->all();
        $duringSecondDeparture = $component->set('date', '2026-06-15')->viewData('students')->pluck('id')->all();

        $this->assertNotContains($student->id, $duringFirstDeparture);
        $this->assertContains($student->id, $duringReturn);
        $this->assertNotContains($student->id, $duringSecondDeparture);
    }

    public function test_no_non_today_warning_is_shown_by_default(): void
    {
        $class = SchoolClass::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->assertDontSee('非本日');
    }

    public function test_a_non_today_warning_is_shown_after_switching_to_a_different_date(): void
    {
        // 補登/更正過去的點名紀錄是合理操作，但切換日期後容易忘記自己
        // 不是在點今天的名，尤其送出後畫面看起來跟平常點名沒有兩樣。
        $class = SchoolClass::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set('date', now()->subDay()->toDateString())
            ->assertSee('非本日');
    }

    public function test_defaults_to_present_for_everyone_when_no_session_exists_yet(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->assertSet("statuses.{$student->id}", AttendanceStatus::Present->value);
    }

    public function test_submit_creates_a_session_and_records_for_every_student(): void
    {
        $class = SchoolClass::factory()->create();
        $students = Student::factory()->forClass($class)->count(3)->create();
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
        $student = Student::factory()->forClass($class)->create();
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
        $student = Student::factory()->forClass($class)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set("statuses.{$student->id}", AttendanceStatus::EarlyLeave->value)
            ->call('submit');

        // 重新進入同一個班級/日期/時段的點名頁，應該要看到剛剛存的狀態，
        // 而不是又預設回「全部出席」。
        Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->assertSet("statuses.{$student->id}", AttendanceStatus::EarlyLeave->value);
    }

    public function test_switching_period_loads_a_different_sessions_statuses(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();
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
        $student = Student::factory()->forClass($class)->create();
        $rep = $this->studentRepFor($class);

        $component = Livewire::actingAs($rep)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set("statuses.{$student->id}", AttendanceStatus::Absent->value);

        // 學生被拔掉權限（例如轉班、畢業、或帳號被停權後移除角色）。
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
        Student::factory()->forClass($class)->create();

        $otherClass = SchoolClass::factory()->create(['grade' => 2]);
        $foreignStudent = Student::factory()->forClass($otherClass)->create();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set("statuses.{$foreignStudent->id}", AttendanceStatus::Absent->value)
            ->call('submit');

        $this->assertDatabaseMissing('attendance_records', ['student_id' => $foreignStudent->id]);
    }

    public function test_marking_a_student_absent_is_recorded_in_the_activity_log(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set("statuses.{$student->id}", AttendanceStatus::Absent->value)
            ->call('submit');

        $this->assertTrue(
            Activity::where('log_name', 'attendance_record')
                ->where('causer_id', $admin->id)
                ->where('properties->student_id', $student->id)
                ->where('properties->new', AttendanceStatus::Absent->value)
                ->exists()
        );
    }

    public function test_a_routine_all_present_first_submission_is_not_logged_as_activity(): void
    {
        // 全班第一次點名、大家都出席是例行狀態，不是需要留意的例外，
        // 不應該每天都在稽核紀錄裡留下 30 筆「出席」的雜訊。
        $class = SchoolClass::factory()->create();
        Student::factory()->forClass($class)->count(3)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->call('submit');

        $this->assertSame(0, Activity::where('log_name', 'attendance_record')->count());
    }

    public function test_correcting_an_already_recorded_status_is_logged_with_old_and_new_values(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $component = Livewire::actingAs($admin)->test(Recorder::class, ['schoolClass' => $class]);
        $component->set("statuses.{$student->id}", AttendanceStatus::Absent->value)->call('submit');
        $component->set("statuses.{$student->id}", AttendanceStatus::Present->value)->call('submit');

        $this->assertTrue(
            Activity::where('log_name', 'attendance_record')
                ->where('properties->old', AttendanceStatus::Absent->value)
                ->where('properties->new', AttendanceStatus::Present->value)
                ->exists()
        );
    }

    public function test_follow_up_section_only_shows_for_non_present_students_to_authorized_roles(): void
    {
        $class = SchoolClass::factory()->create();
        $present = Student::factory()->forClass($class)->create(['name' => '出席同學']);
        $absent = Student::factory()->forClass($class)->create(['name' => '缺席同學']);

        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('homeroom_teacher');
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);
        $class->update(['homeroom_teacher_id' => $teacher->id]);

        Livewire::actingAs($teacherUser)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set("statuses.{$absent->id}", AttendanceStatus::Absent->value)
            ->call('submit')
            ->assertSeeHtml('addFollowUp');

        // 學生身分現在也有 attendance.follow_up.manage 權限，自己班級的
        // 處理情形填得了，畫面上要看得到這個區塊。
        $rep = $this->studentRepFor($class);
        Livewire::actingAs($rep)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->assertSeeHtml('addFollowUp');
    }

    public function test_a_role_without_the_follow_up_permission_does_not_see_the_section(): void
    {
        // 三種內建身分目前剛好都有 attendance.follow_up.manage，但
        // /admin/roles 建的自訂身分可以只勾點名不勾處理情形——那種身分
        // 就不該看到這個區塊，權限檢查不能因為內建身分都通過就省略。
        $class = SchoolClass::factory()->create();
        $absent = Student::factory()->forClass($class)->create(['name' => '缺席同學']);

        $teacherUser = $this->homeroomTeacherFor($class);
        Livewire::actingAs($teacherUser)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set("statuses.{$absent->id}", AttendanceStatus::Absent->value)
            ->call('submit');

        $recordOnly = Role::create(['name' => '只能點名', 'guard_name' => 'web']);
        $recordOnly->syncPermissions(['attendance.record']);

        $limited = User::factory()->create();
        $limited->assignRole($recordOnly);
        Student::factory()->forClass($class)->create(['user_id' => $limited->id]);

        Livewire::actingAs($limited)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->assertDontSeeHtml('addFollowUp');
    }

    public function test_follow_up_history_stays_visible_after_status_is_corrected_back_to_present(): void
    {
        // 導師先把學生記成缺席、留了處理情形，後來查證其實有到、把狀態
        // 改回出席——之前留的紀錄不該因此從畫面上完全消失不見。
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();

        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('homeroom_teacher');
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);
        $class->update(['homeroom_teacher_id' => $teacher->id]);

        $component = Livewire::actingAs($teacherUser)->test(Recorder::class, ['schoolClass' => $class]);
        $component->set("statuses.{$student->id}", AttendanceStatus::Absent->value)->call('submit');

        $record = AttendanceRecord::where('student_id', $student->id)->first();
        $record->followUps()->create(['created_by' => $teacherUser->id, 'content' => '查證後其實有到']);

        $component->set("statuses.{$student->id}", AttendanceStatus::Present->value)
            ->call('submit')
            ->assertSeeHtml('addFollowUp')
            ->assertSee('查證後其實有到');
    }

    public function test_a_student_can_submit_inside_the_attendance_window(): void
    {
        Carbon::setTestNow('2026-08-26 09:00:00');

        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();
        $rep = $this->studentRepFor($class);

        Livewire::actingAs($rep)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set("statuses.{$student->id}", AttendanceStatus::Absent->value)
            ->call('submit');

        $this->assertDatabaseHas('attendance_records', [
            'student_id' => $student->id,
            'status' => AttendanceStatus::Absent->value,
        ]);
    }

    public function test_a_student_cannot_submit_outside_the_attendance_window(): void
    {
        // 畫面上按鈕會是 disabled，但 wire:click 的請求可以被直接送出來
        // ——真正的把關在 Recorder::submit() 裡，這裡直接 call('submit')
        // 就是在驗那一層，不是驗畫面。
        Carbon::setTestNow('2026-08-26 22:00:00');

        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();
        $rep = $this->studentRepFor($class);

        Livewire::actingAs($rep)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set("statuses.{$student->id}", AttendanceStatus::Absent->value)
            ->call('submit');

        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseCount('attendance_sessions', 0);
    }

    public function test_a_homeroom_teacher_can_submit_outside_the_attendance_window(): void
    {
        // 導師有 attendance.record.anytime——補登昨天漏點的、下班後才收到
        // 家長回覆要更正狀態，都不該被時間擋住。
        Carbon::setTestNow('2026-08-26 22:00:00');

        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();
        $teacherUser = $this->homeroomTeacherFor($class);

        Livewire::actingAs($teacherUser)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set("statuses.{$student->id}", AttendanceStatus::Absent->value)
            ->call('submit');

        $this->assertDatabaseHas('attendance_records', [
            'student_id' => $student->id,
            'status' => AttendanceStatus::Absent->value,
        ]);
    }

    public function test_an_admin_can_submit_outside_the_attendance_window(): void
    {
        Carbon::setTestNow('2026-08-26 22:00:00');

        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set("statuses.{$student->id}", AttendanceStatus::Absent->value)
            ->call('submit');

        $this->assertDatabaseHas('attendance_records', ['student_id' => $student->id]);
    }

    public function test_a_student_still_sees_the_page_outside_the_window_with_an_explanation(): void
    {
        // 時段外只是不能送出，不是整頁擋掉——還是要看得到目前的點名狀況，
        // 而且要有明確的提示說明為什麼不能送，不然會以為系統壞了。
        Carbon::setTestNow('2026-08-26 22:00:00');

        $class = SchoolClass::factory()->create();
        Student::factory()->forClass($class)->create(['name' => '陳小明']);
        $rep = $this->studentRepFor($class);

        Livewire::actingAs($rep)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->assertOk()
            ->assertSee('陳小明')
            ->assertSee('現在不在可以點名的時間內')
            ->assertSee(AttendanceWindow::label());
    }

    public function test_no_time_restriction_notice_is_shown_to_a_student_inside_the_window(): void
    {
        Carbon::setTestNow('2026-08-26 09:00:00');

        $class = SchoolClass::factory()->create();
        $rep = $this->studentRepFor($class);

        Livewire::actingAs($rep)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->assertDontSee('現在不在可以點名的時間內');
    }

    public function test_no_time_restriction_notice_is_shown_to_a_teacher_outside_the_window(): void
    {
        Carbon::setTestNow('2026-08-26 22:00:00');

        $class = SchoolClass::factory()->create();
        $teacherUser = $this->homeroomTeacherFor($class);

        Livewire::actingAs($teacherUser)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->assertDontSee('現在不在可以點名的時間內');
    }
}
