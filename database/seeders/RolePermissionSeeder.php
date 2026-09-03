<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * 初始的角色與權限。之後甲方若要調整權限階級，不需要改這個 seeder
     * 或程式碼——直接用 /admin/roles（App\Livewire\Admin\RoleManager）
     * 新增角色、勾選要開放的頁面權限即可，這個 seeder 只負責建立「起始」
     * 的三種角色與所有頁面對應的權限清單。
     *
     * 每一個 admin.* 開頭的權限對應一整個後台頁面（見 routes/web.php
     * 掛在各 admin 路由上的 can:xxx middleware），是刻意做成「頁面級」
     * 而不是頁面內單一動作級的granularity——例如「教師管理」頁面只有
     * 開/關，沒有再細分「只能看不能改」。
     */
    public function run(): void
    {
        $permissions = [
            'attendance.record',        // 送出/查看自己班級的點名（學生、導師、管理者）
            'attendance.record.anytime', // 不受 07:00～17:00 時段限制（導師、管理者）
            'attendance.record.all',    // 點名範圍擴大到全校所有班級，不必是自己帶的班
            'attendance.follow_up.manage', // 建立/編輯「處理情形」（學生、導師、管理者）
            'attendance.dashboard.view', // 查看全校即時點名看板（導師、管理者，學生不用）
            'users.manage',             // /admin/users：建立帳號、指派角色
            'teachers.manage',          // /admin/teachers：新增/編輯教師資料
            'classes.manage',           // /admin/classes：新增/修改班級
            'students.manage',          // /admin/classes/{id}/students：編輯學生資訊
            'roles.manage',             // /admin/roles：新增角色、調整角色的權限
            'audit.view',               // /admin/audit：查閱稽核紀錄（全校範圍，唯讀）
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // 學生（角色代號仍是 student_rep，程式碼多處依賴這個固定字串，
        // 見 RoleManager::PROTECTED_ROLE_NAMES；介面上顯示的中文名稱由
        // App\Support\RoleLabel 決定）：除了點名，也能填寫「處理情形」
        // ——範圍一樣只限自己的班級，由 AttendanceRecordPolicy 的
        // ownSchoolClasses() 檢查把關，不會因此看得到別班的紀錄。
        //
        // 刻意沒有 attendance.record.anytime：學生只能在校內作息時間
        // （見 App\Support\AttendanceWindow）內送出點名單。
        $studentRep = Role::firstOrCreate(['name' => 'student_rep', 'guard_name' => 'web']);
        $studentRep->syncPermissions([
            'attendance.record',
            'attendance.follow_up.manage',
        ]);

        // 導師有 attendance.record.anytime：補登昨天漏點的、下班後才收到
        // 家長回覆要更正狀態，都是正當的行政作業，不該被時間擋住。
        $homeroomTeacher = Role::firstOrCreate(['name' => 'homeroom_teacher', 'guard_name' => 'web']);
        $homeroomTeacher->syncPermissions([
            'attendance.record',
            'attendance.record.anytime',
            'attendance.follow_up.manage',
            'attendance.dashboard.view',
        ]);

        // attendance.record.all 三種內建身分都沒有給：
        //   - 學生／導師的範圍就是自己的班（見 User::ownSchoolClasses()），
        //     給了等於取消掉整個範圍限制。
        //   - admin 不必特別給，它下面 syncPermissions($permissions) 拿到
        //     全部權限，而且本來就有 classes.manage。
        // 這個權限是為了「要能幫任何一班點名，但不該能改班級設定」的身分
        // （例如學務處人員）而存在的，那種身分請在 /admin/roles 自訂。
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions($permissions);
    }
}
