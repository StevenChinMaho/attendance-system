<?php

namespace Tests\Feature\Models;

use App\Models\AttendanceRecord;
use App\Models\Student;
use App\Models\StudentDeparture;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_name_uses_the_accounts_name_when_a_user_id_is_given(): void
    {
        $account = User::factory()->create(['name' => '帳號的姓名']);

        $this->assertSame(
            '帳號的姓名',
            Student::resolveName($account->id, '手動輸入的姓名（應該被忽略）')
        );
    }

    public function test_resolve_name_falls_back_to_the_manual_name_when_no_user_id_is_given(): void
    {
        $this->assertSame('手動輸入的姓名', Student::resolveName(null, '手動輸入的姓名'));
    }

    public function test_display_name_prefers_the_linked_accounts_current_name_over_the_stored_snapshot(): void
    {
        $account = User::factory()->create(['name' => '目前的姓名']);
        $student = Student::factory()->create([
            'user_id' => $account->id,
            'name' => '建立當下的舊名字',
        ]);

        $this->assertSame('目前的姓名', $student->displayName());
    }

    public function test_display_name_uses_the_stored_name_when_no_account_is_linked(): void
    {
        $student = Student::factory()->create([
            'user_id' => null,
            'name' => '沒有帳號的學生',
        ]);

        $this->assertSame('沒有帳號的學生', $student->displayName());
    }

    public function test_is_enrolled_on_is_true_for_a_student_with_no_departures(): void
    {
        $student = Student::factory()->create();
        $student->load('departures');

        $this->assertTrue($student->isEnrolledOn('2026-08-24'));
    }

    public function test_is_enrolled_on_is_false_for_dates_within_an_open_departure(): void
    {
        $student = Student::factory()->create();
        StudentDeparture::factory()->for($student)->create(['left_at' => '2026-08-01', 'returned_at' => null]);
        $student->load('departures');

        $this->assertTrue($student->isEnrolledOn('2026-08-01'), '轉出當天本身還算在讀');
        $this->assertFalse($student->isEnrolledOn('2026-08-02'));
        $this->assertFalse($student->isEnrolledOn('2026-12-31'), '還沒轉入，之後的日子都算不在讀');
    }

    public function test_is_enrolled_on_is_true_again_once_a_departure_has_a_return_date(): void
    {
        $student = Student::factory()->create();
        StudentDeparture::factory()->for($student)->create(['left_at' => '2026-08-01', 'returned_at' => '2026-09-01']);
        $student->load('departures');

        $this->assertFalse($student->isEnrolledOn('2026-08-15'));
        $this->assertTrue($student->isEnrolledOn('2026-09-01'), '轉入當天本身算在讀');
        $this->assertTrue($student->isEnrolledOn('2026-09-15'));
    }

    public function test_is_enrolled_on_correctly_handles_multiple_separate_departure_periods(): void
    {
        // 轉出又轉入又轉出——這是這個功能真正要解決的情境，見 CLAUDE.md
        // 對 Student::isEnrolledOn() 的說明：每一段各自獨立判斷，不會
        // 因為後面的轉出把前面那段的邊界洗掉。
        $student = Student::factory()->create();
        StudentDeparture::factory()->for($student)->create(['left_at' => '2026-03-01', 'returned_at' => '2026-04-01']);
        StudentDeparture::factory()->for($student)->create(['left_at' => '2026-06-01', 'returned_at' => null]);
        $student->load('departures');

        $this->assertFalse($student->isEnrolledOn('2026-03-15'), '第一段轉出期間');
        $this->assertTrue($student->isEnrolledOn('2026-04-15'), '兩段轉出中間，已經轉入');
        $this->assertFalse($student->isEnrolledOn('2026-06-15'), '第二段轉出期間');
    }

    public function test_current_departure_is_null_when_never_left(): void
    {
        $student = Student::factory()->create();

        $this->assertNull($student->currentDeparture);
    }

    public function test_current_departure_is_null_after_returning(): void
    {
        $student = Student::factory()->create();
        StudentDeparture::factory()->for($student)->create(['returned_at' => now()]);

        $this->assertNull($student->fresh()->currentDeparture);
    }

    public function test_current_departure_is_the_open_departure(): void
    {
        $student = Student::factory()->create();
        StudentDeparture::factory()->for($student)->create(['returned_at' => now()]);
        $open = StudentDeparture::factory()->for($student)->create(['returned_at' => null]);

        $this->assertTrue($student->fresh()->currentDeparture->is($open));
    }

    public function test_has_attendance_history_is_false_with_no_records(): void
    {
        $student = Student::factory()->create();

        $this->assertFalse($student->hasAttendanceHistory());
    }

    public function test_has_attendance_history_is_true_once_a_record_exists(): void
    {
        $student = Student::factory()->create();
        AttendanceRecord::factory()->for($student)->create();

        $this->assertTrue($student->hasAttendanceHistory());
    }
}
