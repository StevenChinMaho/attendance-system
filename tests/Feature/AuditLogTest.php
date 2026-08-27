<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Livewire\Account\ChangePassword;
use App\Livewire\Admin\RoleManager;
use App\Livewire\Admin\UserManager;
use App\Livewire\Attendance\Recorder;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['username' => 'sysadmin']);
        $admin->assignRole('admin');

        return $admin;
    }

    private function classWithTeacher(): array
    {
        $class = SchoolClass::factory()->create();
        $teacherUser = User::factory()->create(['username' => 'homeroom']);
        $teacherUser->assignRole('homeroom_teacher');
        $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);
        $class->update(['homeroom_teacher_id' => $teacher->id]);

        return [$class, $teacherUser];
    }

    private function logs(string $logName): Collection
    {
        return Activity::where('log_name', $logName)->orderBy('id')->get();
    }

    // ---------------------------------------------------------------
    // 缺口 1：整份點名單送出
    // ---------------------------------------------------------------

    /**
     * 這是這批改動最重要的一條。logStatusChanges() 刻意不記「第一次點名
     * ＋出席」，所以在此之前，一份「全班到齊」的點名單送出後稽核紀錄
     * 裡一筆都沒有——而那正是「有人冒用帳號隨手送出一份全到」最需要被
     * 記下來的情境。
     */
    public function test_an_all_present_submission_is_logged(): void
    {
        [$class, $teacherUser] = $this->classWithTeacher();
        Student::factory()->forClass($class, '01')->create();
        Student::factory()->forClass($class, '02')->create();

        Livewire::actingAs($teacherUser)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->call('submit');

        // 逐一學生的紀錄應該是空的（全都是預設的出席，不算例外）……
        $this->assertCount(0, $this->logs(AuditLog::ATTENDANCE_RECORD));

        // ……但「有人送出了點名單」這件事一定要留下來。
        $sessionLogs = $this->logs(AuditLog::ATTENDANCE_SESSION);
        $this->assertCount(1, $sessionLogs);

        $log = $sessionLogs->first();
        $this->assertSame('點名單送出', $log->description);
        $this->assertTrue($teacherUser->is($log->causer));

        $properties = $log->properties;
        $this->assertSame($class->id, $properties['school_class_id']);
        $this->assertSame($class->shortLabel(), $properties['school_class']);
        $this->assertSame(2, $properties['student_count']);
        $this->assertSame(2, $properties['status_counts'][AttendanceStatus::Present->value]);
        $this->assertSame(0, $properties['status_counts'][AttendanceStatus::Absent->value]);
    }

    /**
     * 日期與時段直接存進 properties，事後查閱不必再 join
     * attendance_sessions（見 AuditLog 的說明）。
     */
    public function test_the_submission_log_is_self_contained(): void
    {
        [$class, $teacherUser] = $this->classWithTeacher();
        Student::factory()->forClass($class, '01')->create();

        Livewire::actingAs($teacherUser)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set('date', '2026-03-05')
            ->set('period', 'NOON')
            ->call('submit');

        $properties = $this->logs(AuditLog::ATTENDANCE_SESSION)->first()->properties;

        $this->assertSame('2026-03-05', $properties['date']);
        $this->assertSame('NOON', $properties['period']);
        $this->assertSame('中午', $properties['period_label']);
    }

    public function test_resubmitting_the_same_session_is_logged_separately(): void
    {
        [$class, $teacherUser] = $this->classWithTeacher();
        Student::factory()->forClass($class, '01')->create();

        Livewire::actingAs($teacherUser)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->call('submit');

        Livewire::actingAs($teacherUser)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->call('submit');

        $descriptions = $this->logs(AuditLog::ATTENDANCE_SESSION)->pluck('description')->all();

        $this->assertSame(['點名單送出', '點名單重新送出'], $descriptions);
    }

    public function test_a_status_change_still_records_who_and_what(): void
    {
        [$class, $teacherUser] = $this->classWithTeacher();
        $student = Student::factory()->forClass($class, '01')->create(['student_number' => '10001']);

        Livewire::actingAs($teacherUser)
            ->test(Recorder::class, ['schoolClass' => $class])
            ->set("statuses.{$student->id}", AttendanceStatus::Absent->value)
            ->call('submit');

        $log = $this->logs(AuditLog::ATTENDANCE_RECORD)->first();

        $this->assertSame('出席狀態建立', $log->description);
        $this->assertTrue($teacherUser->is($log->causer));
        $this->assertSame('10001', $log->properties['student_number']);
        $this->assertNull($log->properties['old']);
        $this->assertSame(AttendanceStatus::Absent->value, $log->properties['new']);
    }

    // ---------------------------------------------------------------
    // 缺口 2：登入相關
    // ---------------------------------------------------------------

    public function test_a_successful_login_is_logged_with_the_client_ip(): void
    {
        $user = User::factory()->create(['username' => 'teacher1', 'password' => 'a-strong-password']);

        $this->post('/login', [
            'username' => 'teacher1',
            'password' => 'a-strong-password',
        ], ['X-Forwarded-For' => '203.0.113.9']);

        $log = $this->logs(AuditLog::AUTH)->first();

        $this->assertSame('登入成功', $log->description);
        $this->assertTrue($user->is($log->causer));
        $this->assertSame('teacher1', $log->properties['username']);
        // IP 要是真實的客戶端位址，不是最後一跳的代理——這依賴
        // bootstrap/app.php 的 trustProxies，見 TrustedProxiesTest。
        $this->assertSame('203.0.113.9', $log->properties['ip']);
    }

    public function test_a_wrong_password_is_logged_against_the_account(): void
    {
        $user = User::factory()->create(['username' => 'teacher1', 'password' => 'a-strong-password']);

        $this->post('/login', ['username' => 'teacher1', 'password' => 'wrong']);

        $log = $this->logs(AuditLog::AUTH)->first();

        $this->assertSame('登入失敗：密碼錯誤', $log->description);
        $this->assertTrue($user->is($log->causer));
        $this->assertSame('teacher1', $log->properties['username']);
    }

    /**
     * 把密碼打進帳號欄是很常見的手誤。如果不管帳號存不存在都照記，
     * 那個字串就會以明文躺在管理者看得到的稽核紀錄裡。帳號不存在時
     * 只留 IP 與時間，「有人在亂試」仍然看得出來。
     */
    public function test_an_unknown_username_is_not_written_into_the_log(): void
    {
        $this->post('/login', [
            'username' => 'this-might-actually-be-a-password',
            'password' => 'whatever',
        ], ['X-Forwarded-For' => '203.0.113.9']);

        $log = $this->logs(AuditLog::AUTH)->first();

        $this->assertSame('登入失敗：帳號不存在', $log->description);
        $this->assertNull($log->causer);
        $this->assertNull($log->properties['username']);
        $this->assertSame('203.0.113.9', $log->properties['ip']);

        $this->assertStringNotContainsString(
            'this-might-actually-be-a-password',
            json_encode($log->properties),
        );
    }

    public function test_a_disabled_account_login_attempt_is_logged(): void
    {
        $user = User::factory()->inactive()->create([
            'username' => 'suspended',
            'password' => 'a-strong-password',
        ]);

        $this->post('/login', ['username' => 'suspended', 'password' => 'a-strong-password']);

        $log = $this->logs(AuditLog::AUTH)->first();

        $this->assertSame('登入失敗：帳號已停用', $log->description);
        $this->assertTrue($user->is($log->causer));
    }

    public function test_hitting_the_rate_limiter_is_logged(): void
    {
        User::factory()->create(['username' => 'teacher1', 'password' => 'a-strong-password']);

        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', ['username' => 'teacher1', 'password' => 'wrong-'.$i]);
        }

        $descriptions = $this->logs(AuditLog::AUTH)->pluck('description')->all();

        $this->assertSame('登入被頻率限制擋下', end($descriptions));
    }

    public function test_logout_is_logged(): void
    {
        $user = User::factory()->create(['username' => 'teacher1']);

        $this->actingAs($user)->post('/logout');

        $log = $this->logs(AuditLog::AUTH)->first();

        $this->assertSame('登出', $log->description);
        $this->assertTrue($user->is($log->causer));
    }

    public function test_no_password_value_ever_reaches_the_log(): void
    {
        User::factory()->create(['username' => 'teacher1', 'password' => 'super-secret-pw']);

        $this->post('/login', ['username' => 'teacher1', 'password' => 'super-secret-pw']);
        $this->post('/login', ['username' => 'teacher1', 'password' => 'super-secret-pw-typo']);

        $everything = Activity::all()
            ->map(fn (Activity $a) => json_encode([$a->description, $a->properties, $a->attribute_changes]))
            ->join(' ');

        $this->assertStringNotContainsString('super-secret-pw', $everything);
    }

    // ---------------------------------------------------------------
    // 缺口 3：後台管理動作
    // ---------------------------------------------------------------

    public function test_creating_an_account_is_logged_without_the_password(): void
    {
        Livewire::actingAs($this->admin())
            ->test(UserManager::class)
            ->set('name', '王老師')
            ->set('username', 'wang')
            ->set('password', 'the-initial-password')
            ->set('role', 'homeroom_teacher')
            ->call('createUser');

        $log = $this->logs(AuditLog::ADMIN)->first();

        $this->assertSame('建立帳號', $log->description);
        $this->assertSame('wang', $log->properties['username']);
        $this->assertSame('homeroom_teacher', $log->properties['role']);
        $this->assertStringNotContainsString('the-initial-password', json_encode($log->properties));
    }

    /**
     * 身分變更等於權限變更，「誰把某個帳號變成管理者」在這之前完全查不到。
     */
    public function test_a_role_change_records_both_the_old_and_the_new_role(): void
    {
        $target = User::factory()->create(['username' => 'target']);
        $target->assignRole('student_rep');

        Livewire::actingAs($this->admin())
            ->test(UserManager::class)
            ->call('startEdit', $target)
            ->set('role', 'admin')
            ->call('updateUser');

        $log = $this->logs(AuditLog::ADMIN)->first();

        $this->assertSame('更新帳號', $log->description);
        $this->assertSame('student_rep', $log->properties['role_from']);
        $this->assertSame('admin', $log->properties['role_to']);
    }

    public function test_resetting_a_password_is_logged_without_the_new_password(): void
    {
        $target = User::factory()->create(['username' => 'target']);
        $target->assignRole('student_rep');

        Livewire::actingAs($this->admin())
            ->test(UserManager::class)
            ->call('startResetPassword', $target)
            ->set('newPassword', 'brand-new-password')
            ->call('resetPassword');

        $log = $this->logs(AuditLog::ADMIN)->first();

        $this->assertSame('重設帳號密碼', $log->description);
        $this->assertSame('target', $log->properties['username']);
        $this->assertStringNotContainsString('brand-new-password', json_encode($log->properties));
    }

    public function test_deleting_an_account_keeps_a_readable_snapshot(): void
    {
        $target = User::factory()->create(['username' => 'target', 'name' => '待刪帳號']);
        $target->assignRole('student_rep');

        Livewire::actingAs($this->admin())
            ->test(UserManager::class)
            ->call('deleteUser', $target);

        $log = $this->logs(AuditLog::ADMIN)->first();

        $this->assertSame('刪除帳號', $log->description);
        // 資料列已經不存在了，紀錄本身必須看得懂是誰被刪掉。
        $this->assertSame('target', $log->properties['username']);
        $this->assertSame('待刪帳號', $log->properties['name']);
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_toggling_a_role_permission_is_logged(): void
    {
        $admin = $this->admin();

        $component = Livewire::actingAs($admin)
            ->test(RoleManager::class)
            ->set('name', '教務處人員')
            ->set('selectedPermissions', ['classes.manage'])
            ->call('createRole');

        $role = Role::where('name', '教務處人員')->sole();

        $component->call('togglePermission', $role->id, 'students.manage');
        $component->call('togglePermission', $role->id, 'classes.manage');

        $descriptions = $this->logs(AuditLog::ADMIN)->pluck('description')->all();

        $this->assertSame(['建立身分', '身分新增權限', '身分移除權限'], $descriptions);
    }

    public function test_changing_your_own_password_is_logged(): void
    {
        $user = User::factory()->create(['username' => 'teacher1', 'password' => 'old-password']);

        Livewire::actingAs($user)
            ->test(ChangePassword::class)
            ->set('currentPassword', 'old-password')
            ->set('newPassword', 'the-new-password')
            ->set('newPassword_confirmation', 'the-new-password')
            ->call('updatePassword');

        $log = $this->logs(AuditLog::AUTH)->first();

        $this->assertSame('自行變更密碼', $log->description);
        $this->assertTrue($user->is($log->causer));
        $this->assertStringNotContainsString('the-new-password', json_encode($log->properties));
    }
}
