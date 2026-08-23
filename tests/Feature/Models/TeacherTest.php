<?php

namespace Tests\Feature\Models;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_name_uses_the_accounts_name_when_a_user_id_is_given(): void
    {
        $account = User::factory()->create(['name' => '帳號的姓名']);

        $this->assertSame(
            '帳號的姓名',
            Teacher::resolveName($account->id, '手動輸入的姓名（應該被忽略）')
        );
    }

    public function test_resolve_name_falls_back_to_the_manual_name_when_no_user_id_is_given(): void
    {
        $this->assertSame('手動輸入的姓名', Teacher::resolveName(null, '手動輸入的姓名'));
    }

    public function test_display_name_prefers_the_linked_accounts_current_name_over_the_stored_snapshot(): void
    {
        // 就算 teachers.teacher_name 這個欄位裡存的是舊的快照值，只要
        // 還連結著帳號，畫面上一律要顯示帳號目前的姓名——這樣之後如果
        // 系統長出「帳號改名」的功能，這裡不用另外補資料同步。
        $account = User::factory()->create(['name' => '目前的姓名']);
        $teacher = Teacher::factory()->create([
            'user_id' => $account->id,
            'teacher_name' => '建立當下的舊名字',
        ]);

        $this->assertSame('目前的姓名', $teacher->displayName());
    }

    public function test_display_name_uses_the_stored_name_when_no_account_is_linked(): void
    {
        $teacher = Teacher::factory()->create([
            'user_id' => null,
            'teacher_name' => '沒有帳號的老師',
        ]);

        $this->assertSame('沒有帳號的老師', $teacher->displayName());
    }
}
