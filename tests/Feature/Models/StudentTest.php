<?php

namespace Tests\Feature\Models;

use App\Models\Student;
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
}
