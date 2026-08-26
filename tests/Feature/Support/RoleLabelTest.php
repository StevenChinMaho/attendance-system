<?php

namespace Tests\Feature\Support;

use App\Support\RoleLabel;
use Tests\TestCase;

class RoleLabelTest extends TestCase
{
    public function test_the_three_built_in_roles_get_a_chinese_label(): void
    {
        $this->assertSame('管理者', RoleLabel::forName('admin'));
        $this->assertSame('導師', RoleLabel::forName('homeroom_teacher'));
        $this->assertSame('學生', RoleLabel::forName('student_rep'));
    }

    public function test_an_unrecognised_custom_role_name_falls_back_to_itself(): void
    {
        // 自訂角色沒有內建對照，建立時就直接輸入中文名稱（見
        // App\Livewire\Admin\RoleManager 建立表單的說明文字），這裡回傳
        // 原始名稱本身即可，不需要另外翻譯。
        $this->assertSame('考務助理', RoleLabel::forName('考務助理'));
        $this->assertSame('exam_supervisor', RoleLabel::forName('exam_supervisor'));
    }
}
