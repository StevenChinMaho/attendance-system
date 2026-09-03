<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Livewire\Attendance\FollowUpManager;
use App\Livewire\AttendanceQuickLink;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * attendance.record.all（「點名所有班級」）。
 *
 * 在這個權限出現之前，「能點全校任何一班」只能靠 classes.manage 換來
 * ——但那同時給了新增/修改/刪除班級的能力。學務處人員要能幫任何一班
 * 補點名，卻不該碰得到班級設定，兩者必須拆得開。
 *
 * 這個檔案釘住的是「拆得開」這件事本身：有新權限就點得了全校的名，
 * 而且**沒有**因此拿到任何管理班級的能力。
 */
class AttendanceRecordAllPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * 學務處人員：能點名（含不限時段）、能寫處理情形、看得到即時看板，
     * 但完全沒有任何 *.manage 權限。
     *
     * @param  list<string>  $permissions
     */
    private function staffWith(array $permissions): User
    {
        $role = Role::create(['name' => '學務處人員', 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
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

    // ---------------------------------------------------------------
    // 權限本身
    // ---------------------------------------------------------------

    public function test_the_permission_is_created_by_the_seeder(): void
    {
        // 權限是資料不是程式碼：只寫在路由或 Policy 裡而沒有進 seeder 的話，
        // 資料庫裡根本沒有這一列，畫面上也勾不到（見 PermissionCoverageTest）。
        $this->assertTrue(Permission::where('name', 'attendance.record.all')->exists());
    }

    public function test_no_built_in_role_holds_it_except_admin(): void
    {
        // 學生／導師的範圍就是自己的班，給了等於取消整個範圍限制。
        $this->assertFalse(Role::findByName('student_rep')->hasPermissionTo('attendance.record.all'));
        $this->assertFalse(Role::findByName('homeroom_teacher')->hasPermissionTo('attendance.record.all'));
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('attendance.record.all'));
    }

    // ---------------------------------------------------------------
    // 點名：範圍擴大到全校
    // ---------------------------------------------------------------

    public function test_it_grants_access_to_any_classes_attendance_page_without_classes_manage(): void
    {
        $class = SchoolClass::factory()->create();

        $staff = $this->staffWith(['attendance.record', 'attendance.record.all']);

        $this->actingAs($staff)
            ->get("/attendance/{$class->id}")
            ->assertOk();
    }

    /**
     * 這是這個權限存在的全部理由——沒有它的話，同樣的效果只能靠
     * classes.manage 換來，而那會一併打開整個班級管理頁面。
     */
    public function test_it_does_not_grant_any_class_management(): void
    {
        $staff = $this->staffWith(['attendance.record', 'attendance.record.all']);

        $this->actingAs($staff)
            ->get('/admin/classes')
            ->assertForbidden();
    }

    public function test_it_does_not_leak_into_the_other_admin_pages_either(): void
    {
        $staff = $this->staffWith(['attendance.record', 'attendance.record.all']);

        foreach (['/admin/users', '/admin/teachers', '/admin/students', '/admin/roles', '/admin/audit'] as $page) {
            $this->actingAs($staff)
                ->get($page)
                ->assertForbidden();
        }
    }

    /**
     * 這個權限只放寬「範圍」，不代表「可以點名」——那是 attendance.record。
     * 兩層檢查的順序在 SchoolClassPolicy::recordAttendance() 裡，這裡釘住
     * 少了第一層就一律擋下。
     */
    public function test_it_alone_does_not_grant_the_ability_to_record(): void
    {
        $class = SchoolClass::factory()->create();

        $staff = $this->staffWith(['attendance.record.all']);

        $this->actingAs($staff)
            ->get("/attendance/{$class->id}")
            ->assertForbidden();
    }

    public function test_the_quick_link_lists_every_class_in_the_selected_period(): void
    {
        $classA = SchoolClass::factory()->create(['grade' => 1, 'class_number' => 1]);
        $classB = SchoolClass::factory()->create(['grade' => 2, 'class_number' => 2]);

        $staff = $this->staffWith(['attendance.record', 'attendance.record.all']);

        Livewire::actingAs($staff)
            ->test(AttendanceQuickLink::class)
            ->assertSee($classA->shortLabel())
            ->assertSee($classB->shortLabel());
    }

    // ---------------------------------------------------------------
    // 處理情形：範圍跟著擴大，但動作本身仍要各自的權限
    // ---------------------------------------------------------------

    public function test_it_extends_the_follow_up_scope_to_any_class(): void
    {
        // 能幫任何一班點名，卻不能在剛送出的那筆紀錄上寫處理情形，
        // 是說不通的。
        $record = $this->absentRecordFor(SchoolClass::factory()->create());

        $staff = $this->staffWith([
            'attendance.record',
            'attendance.record.all',
            'attendance.follow_up.manage',
        ]);

        Livewire::actingAs($staff)
            ->test(FollowUpManager::class, ['record' => $record])
            ->set('content', '已電聯家長')
            ->call('addFollowUp')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('attendance_follow_ups', ['content' => '已電聯家長']);
    }

    public function test_it_does_not_grant_follow_up_management_by_itself(): void
    {
        $record = $this->absentRecordFor(SchoolClass::factory()->create());

        $staff = $this->staffWith(['attendance.record', 'attendance.record.all']);

        $this->assertFalse($staff->can('manageFollowUp', $record));

        // 元件層（FollowUpManager::boot() 每次 hydrate 的獨立 authorize）
        // 已經由 RecorderTest::test_a_role_without_the_follow_up_permission_does_not_see_the_section
        // 涵蓋，這裡只釘 Policy 這一層：新權限不會順便把處理情形打開。
    }

    // ---------------------------------------------------------------
    // 沒有這個權限的人，範圍不能因此變寬
    // ---------------------------------------------------------------

    public function test_a_homeroom_teacher_still_cannot_reach_another_class(): void
    {
        $ownClass = SchoolClass::factory()->create();
        $otherClass = SchoolClass::factory()->create();

        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('homeroom_teacher');
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);
        $ownClass->update(['homeroom_teacher_id' => $teacher->id]);

        $this->actingAs($teacherUser)->get("/attendance/{$ownClass->id}")->assertOk();
        $this->actingAs($teacherUser)->get("/attendance/{$otherClass->id}")->assertForbidden();
    }

    /**
     * classes.manage 原本就有的全校範圍不能因為新增這個權限而消失
     * ——那會是安靜的權限退化。
     */
    public function test_classes_manage_still_grants_the_same_scope_on_its_own(): void
    {
        $class = SchoolClass::factory()->create();

        $staff = $this->staffWith(['attendance.record', 'classes.manage']);

        $this->actingAs($staff)
            ->get("/attendance/{$class->id}")
            ->assertOk();
    }
}
