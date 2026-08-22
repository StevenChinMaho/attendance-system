<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * 初始的角色與權限。之後甲方若要調整權限階級，直接在後台調整
     * roles/permissions 資料即可，不需要改這個 seeder 或程式碼——
     * 這裡只負責建立「起始」的三種角色。
     *
     * 部分權限（attendance.* 開頭）對應的功能還沒實作，先建立起來是為了
     * 讓角色結構一開始就正確，之後點名功能上線時不需要再回頭調整角色。
     */
    public function run(): void
    {
        $permissions = [
            'attendance.record',        // 送出/查看自己班級的點名（副班長、導師、管理者）
            'attendance.follow_up.manage', // 建立/編輯「處理情形」（導師、管理者）
            'attendance.dashboard.view', // 查看全校即時點名看板（導師、管理者，副班長不用）
            'students.manage',          // 編輯學生資訊、所屬班級（管理者）
            'classes.manage',           // 新增/修改班級（管理者）
            'users.manage',             // 建立帳號、指派角色（管理者）
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $studentRep = Role::firstOrCreate(['name' => 'student_rep', 'guard_name' => 'web']);
        $studentRep->syncPermissions(['attendance.record']);

        $homeroomTeacher = Role::firstOrCreate(['name' => 'homeroom_teacher', 'guard_name' => 'web']);
        $homeroomTeacher->syncPermissions([
            'attendance.record',
            'attendance.follow_up.manage',
            'attendance.dashboard.view',
        ]);

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions($permissions);
    }
}
