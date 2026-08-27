<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\User;
use App\Support\BackupStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 備份過期警告。
 *
 * 備份最常見的失敗方式不是當下報錯，而是某天默默停掉、直到真的需要還原
 * 時才被發現——那正好是最糟的時間點。這個警告是整套備份機制裡唯一能
 * 主動把那件事講出來的部分，所以行為要釘死。
 */
class BackupStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'backup.monitor_enabled' => true,
            'backup.warn_after_hours' => 48,
        ]);
    }

    private function recordBackupAt(Carbon $when): void
    {
        BackupRun::create([
            'completed_at' => $when,
            'file_name' => 'attendance-'.$when->format('Y-m-d-His').'.sql.gz',
            'size_bytes' => 1_234_567,
        ]);
    }

    private function auditor(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    // ---------------------------------------------------------------
    // 判斷邏輯
    // ---------------------------------------------------------------

    public function test_a_recent_backup_is_not_stale(): void
    {
        $this->recordBackupAt(now()->subHours(6));

        $this->assertFalse(BackupStatus::isStale());
    }

    public function test_a_backup_older_than_the_threshold_is_stale(): void
    {
        $this->recordBackupAt(now()->subHours(60));

        $this->assertTrue(BackupStatus::isStale());
    }

    /**
     * 門檻設 48 小時而不是 24，是因為備份每天一次，抓 24 小時的話只要
     * 稍微延遲就會誤報；48 小時代表「連續漏掉兩次」。
     */
    public function test_a_single_missed_run_does_not_trigger_the_warning(): void
    {
        $this->recordBackupAt(now()->subHours(30));

        $this->assertFalse(BackupStatus::isStale());
    }

    /**
     * 「從來沒有備份過」是最需要示警的狀況——代表備份可能從一開始就
     * 沒設定好，而那正是最容易被忽略的失敗方式。
     */
    public function test_never_having_backed_up_counts_as_stale(): void
    {
        $this->assertTrue(BackupStatus::isStale());
        $this->assertStringContainsString('從來沒有成功備份過', BackupStatus::warningMessage());
    }

    public function test_the_newest_run_is_the_one_that_counts(): void
    {
        $this->recordBackupAt(now()->subDays(10));
        $this->recordBackupAt(now()->subHours(2));
        $this->recordBackupAt(now()->subDays(5));

        $this->assertFalse(BackupStatus::isStale());
    }

    /**
     * 本機開發環境沒有備份容器，監控開著只會讓每一頁都跳警告。
     */
    public function test_the_warning_is_disabled_by_default(): void
    {
        config(['backup.monitor_enabled' => false]);

        $this->assertFalse(BackupStatus::isStale());
    }

    // ---------------------------------------------------------------
    // 畫面
    // ---------------------------------------------------------------

    public function test_the_banner_appears_for_an_account_with_the_audit_permission(): void
    {
        $this->recordBackupAt(now()->subDays(6));

        $this->actingAs($this->auditor())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('資料庫備份異常');
    }

    public function test_the_banner_is_hidden_when_backups_are_healthy(): void
    {
        $this->recordBackupAt(now()->subHours(3));

        $this->actingAs($this->auditor())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('資料庫備份異常');
    }

    /**
     * 導師與學生看到也無從處理，只會變成每天都看到、然後學會忽略的雜訊。
     */
    public function test_the_banner_is_hidden_from_accounts_without_the_permission(): void
    {
        $this->recordBackupAt(now()->subDays(6));

        $teacher = User::factory()->create();
        $teacher->assignRole('homeroom_teacher');

        $this->actingAs($teacher)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('資料庫備份異常');
    }

    /**
     * 稽核權限是獨立的，不綁在 admin 角色上——只勾 audit.view 的自訂
     * 身分也該看得到警告（他們正是負責看這類東西的人）。
     */
    public function test_a_custom_role_with_only_audit_view_sees_the_banner(): void
    {
        $this->recordBackupAt(now()->subDays(6));

        $role = Role::create(['name' => '學務處人員', 'guard_name' => 'web']);
        $role->givePermissionTo(['audit.view', 'attendance.dashboard.view']);

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('資料庫備份異常');
    }

    public function test_the_message_names_the_time_of_the_last_backup(): void
    {
        $when = now()->subDays(6);
        $this->recordBackupAt($when);

        $this->assertStringContainsString($when->format('Y-m-d H:i'), BackupStatus::warningMessage());
    }

    /**
     * 這是維運訊息，印出來的點名單／缺席清單上不該出現。
     */
    public function test_the_banner_is_excluded_from_print(): void
    {
        $this->recordBackupAt(now()->subDays(6));

        $this->actingAs($this->auditor())
            ->get('/dashboard')
            ->assertSee('print:hidden', escape: false);
    }
}
