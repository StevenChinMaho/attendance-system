<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\StudentManager;
use App\Models\AttendanceRecord;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentDeparture;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

        $this->assertDatabaseHas('students', ['name' => '陳小明']);
        $this->assertDatabaseHas('school_class_student', [
            'school_class_id' => $class->id,
            'seat_number' => '1',
        ]);
    }

    public function test_duplicate_student_number_within_the_same_class_fails_validation(): void
    {
        $class = SchoolClass::factory()->create();
        Student::factory()->forClass($class)->create(['student_number' => '10001']);

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
        Student::factory()->forClass($class, '1')->create();

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->set('studentNumber', '99999')
            ->set('seatNumber', '1')
            ->set('name', '另一個學生')
            ->set('gender', '女')
            ->call('createStudent')
            ->assertHasErrors('seatNumber');
    }

    public function test_same_student_number_in_a_different_class_is_rejected(): void
    {
        // 學號是全校唯一，不是只在單一班級內唯一——同一個真實學生從入學
        // 到畢業自始至終只有一筆 students 資料，不該有兩個不同班級、不同
        // 學生共用同一個學號的情況。
        $otherClass = SchoolClass::factory()->create();
        Student::factory()->forClass($otherClass)->create(['student_number' => '10001']);

        $class = SchoolClass::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->set('studentNumber', '10001')
            ->set('seatNumber', '1')
            ->set('name', '陳小明')
            ->set('gender', '男')
            ->call('createStudent')
            ->assertHasErrors('studentNumber');
    }

    public function test_admin_can_link_a_student_rep_account_to_a_student(): void
    {
        // 連結帳號時姓名不需要（也不應該）手動打，直接沿用帳號本身的
        // users.name——見 Student::resolveName() 的說明，跟教師管理
        // 同一套處理方式。
        $class = SchoolClass::factory()->create();
        $account = User::factory()->create(['name' => '副班長']);
        $account->assignRole('student_rep');

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->set('studentNumber', '10001')
            ->set('seatNumber', '1')
            ->set('gender', '男')
            ->set('userId', $account->id)
            ->call('createStudent')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('students', ['name' => '副班長', 'user_id' => $account->id]);
    }

    public function test_a_manually_typed_name_is_ignored_when_an_account_is_linked_to_a_student(): void
    {
        $class = SchoolClass::factory()->create();
        $account = User::factory()->create(['name' => '真正的姓名']);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->set('studentNumber', '10001')
            ->set('seatNumber', '1')
            ->set('name', '不應該用到這個名字')
            ->set('gender', '男')
            ->set('userId', $account->id)
            ->call('createStudent')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('students', ['name' => '真正的姓名']);
        $this->assertDatabaseMissing('students', ['name' => '不應該用到這個名字']);
    }

    public function test_name_is_required_when_no_account_is_linked_to_a_student(): void
    {
        $class = SchoolClass::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->set('studentNumber', '10001')
            ->set('seatNumber', '1')
            ->set('name', '')
            ->set('gender', '男')
            ->call('createStudent')
            ->assertHasErrors('name');
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
        Student::factory()->forClass($class)->create(['user_id' => $account->id]);

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

    public function test_admin_can_mark_a_student_as_left_defaulting_to_today(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->call('startMarkAsLeft', $student->id)
            ->assertSet('leftDate', now()->toDateString())
            ->call('confirmMarkAsLeft')
            ->assertHasNoErrors();

        $this->assertSame(now()->toDateString(), $student->fresh()->currentDeparture->left_at->toDateString());
    }

    public function test_admin_can_manually_enter_a_past_date_when_marking_a_student_as_left(): void
    {
        // 常見情境：admin 是事後才幫忙補標記，實際轉出日是過去某一天，
        // 不該一律等於「現在按下去的這一刻」。
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();
        $actualLeaveDate = now()->subMonth()->toDateString();

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->call('startMarkAsLeft', $student->id)
            ->set('leftDate', $actualLeaveDate)
            ->call('confirmMarkAsLeft')
            ->assertHasNoErrors();

        $this->assertSame($actualLeaveDate, $student->fresh()->currentDeparture->left_at->toDateString());
    }

    public function test_marking_as_left_requires_a_valid_date(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->call('startMarkAsLeft', $student->id)
            ->set('leftDate', '')
            ->call('confirmMarkAsLeft')
            ->assertHasErrors('leftDate');

        $this->assertNull($student->fresh()->currentDeparture);
    }

    public function test_admin_can_restore_a_student_marked_as_left_with_a_manually_entered_date(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();
        StudentDeparture::factory()->for($student)->create(['left_at' => '2026-08-01', 'returned_at' => null]);
        $returnDate = '2026-08-15';

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->call('startRestore', $student->id)
            ->assertSet('returnedDate', now()->toDateString())
            ->set('returnedDate', $returnDate)
            ->call('confirmRestore')
            ->assertHasNoErrors();

        $fresh = $student->fresh();
        $this->assertNull($fresh->currentDeparture);
        $this->assertSame($returnDate, $fresh->departures()->latest()->first()->returned_at->toDateString());
    }

    public function test_restoring_rejects_a_return_date_before_the_departure_date(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();
        StudentDeparture::factory()->for($student)->create(['left_at' => '2026-08-15', 'returned_at' => null]);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->call('startRestore', $student->id)
            ->set('returnedDate', '2026-08-01')
            ->call('confirmRestore')
            ->assertHasErrors('returnedDate');

        $this->assertNotNull($student->fresh()->currentDeparture);
    }

    public function test_a_student_who_leaves_and_returns_multiple_times_keeps_every_period_separately(): void
    {
        // 這是這整個功能真正要處理的情境：轉出又轉入又轉出，每一段都要
        // 各自完整保留，不能因為第二次轉出就把第一次那段的邊界洗掉。
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();
        $component = Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class]);

        $component->call('startMarkAsLeft', $student->id)
            ->set('leftDate', '2026-03-01')
            ->call('confirmMarkAsLeft');

        $component->call('startRestore', $student->id)
            ->set('returnedDate', '2026-04-01')
            ->call('confirmRestore');

        $component->call('startMarkAsLeft', $student->id)
            ->set('leftDate', '2026-06-01')
            ->call('confirmMarkAsLeft');

        $fresh = $student->fresh();
        $this->assertCount(2, $fresh->departures);
        $this->assertNotNull($fresh->currentDeparture);
        $this->assertSame('2026-06-01', $fresh->currentDeparture->left_at->toDateString());

        $fresh->load('departures');
        $this->assertFalse($fresh->isEnrolledOn('2026-03-15'), '第一段轉出期間');
        $this->assertTrue($fresh->isEnrolledOn('2026-04-15'), '兩段轉出中間');
        $this->assertFalse($fresh->isEnrolledOn('2026-06-15'), '第二段轉出期間，還沒轉入');
    }

    public function test_a_student_from_another_class_cannot_be_marked_as_left_through_this_page(): void
    {
        $class = SchoolClass::factory()->create();
        $otherClass = SchoolClass::factory()->create();
        $studentInOtherClass = Student::factory()->forClass($otherClass)->create();

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->call('startMarkAsLeft', $studentInOtherClass->id)
            ->call('confirmMarkAsLeft');
    }

    public function test_a_student_from_another_class_cannot_be_restored_through_this_page(): void
    {
        $class = SchoolClass::factory()->create();
        $otherClass = SchoolClass::factory()->create();
        $studentInOtherClass = Student::factory()->forClass($otherClass)->create();
        StudentDeparture::factory()->for($studentInOtherClass, 'student')->create(['returned_at' => null]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->call('startRestore', $studentInOtherClass->id)
            ->call('confirmRestore');
    }

    public function test_opening_the_mark_as_left_panel_while_editing_closes_the_edit_form(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->call('startEdit', $student->id)
            ->assertSet('editingStudentId', $student->id)
            ->call('startMarkAsLeft', $student->id)
            ->assertSet('editingStudentId', null)
            ->assertSet('markingLeftStudentId', $student->id);
    }

    public function test_admin_can_delete_a_student_with_no_attendance_history(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->call('deleteStudent', $student->id);

        $this->assertModelMissing($student);
    }

    public function test_a_student_with_attendance_history_cannot_be_deleted(): void
    {
        // 真的轉學的學生幾乎一定已經有點名紀錄——這正是為什麼「轉出」
        // 跟「刪除」必須是兩個獨立功能，見 Student::hasAttendanceHistory()。
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create();
        AttendanceRecord::factory()->for($student)->create();

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class, ['schoolClass' => $class])
            ->call('deleteStudent', $student->id);

        $this->assertModelExists($student);
    }

    public function test_opening_the_create_form_while_editing_closes_the_edit_form(): void
    {
        // 新增表單跟編輯表單共用同一組欄位屬性（$studentNumber/
        // $seatNumber/$name/$gender/$userId）；兩個表單以前可以同時開
        // 著，輸入內容看起來會互相同步，實際上是同一個屬性被兩邊的
        // 輸入框綁定。
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->forClass($class)->create(['student_number' => '10001']);

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
        $existing = Student::factory()->forClass($class, '1')->create([
            'student_number' => '10001', 'name' => '正在編輯的學生',
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
