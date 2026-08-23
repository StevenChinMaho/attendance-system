<?php

namespace App\Support;

/**
 * 角色（spatie 的 roles.name）在介面上一律顯示中文，不是資料庫裡的英文
 * 代號——三個系統內建角色的代號是程式碼多處依賴的固定字串（見
 * App\Livewire\Admin\RoleManager::PROTECTED_ROLE_NAMES），不能直接改
 * 掉，所以用這個對照表做顯示層的翻譯。之後 /admin/roles 新增的自訂
 * 角色，建立時就直接輸入好懂的中文名稱即可（見 RoleManager 建立表單
 * 的說明文字），不需要另外收錄到這個表裡——找不到對照的名稱就直接
 * 顯示原始名稱。
 */
final class RoleLabel
{
    private const LABELS = [
        'admin' => '管理者',
        'homeroom_teacher' => '導師',
        'student_rep' => '副班長',
    ];

    public static function forName(string $name): string
    {
        return self::LABELS[$name] ?? $name;
    }
}
