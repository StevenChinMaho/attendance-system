<?php

namespace App\Policies;

use App\Models\AttendanceRecord;
use App\Models\User;

/**
 * 「處理情形」只有導師跟管理者能建立/查看——副班長雖然能點名，但沒有
 * attendance.follow_up.manage 權限（見 RolePermissionSeeder），跟
 * SchoolClassPolicy 一樣是權限（能不能做這種事）+ 範圍（哪個班）
 * 兩層一起檢查。
 */
class AttendanceRecordPolicy
{
    /**
     * can('classes.manage') 一律放行，理由跟 SchoolClassPolicy::
     * recordAttendance() 完全一樣：檢查 permission 而不是寫死
     * hasRole('admin')，這樣一個被賦予跟 admin 同等權限組合的自訂身分
     * （見 App\Livewire\Admin\RoleManager），才不會因為沒有連結任何
     * Teacher/Student 業務身份、ownSchoolClasses() 必定是空集合，而
     * 完全無法管理任何班級的處理情形。
     */
    public function manageFollowUp(User $user, AttendanceRecord $record): bool
    {
        if (! $user->can('attendance.follow_up.manage')) {
            return false;
        }

        if ($user->can('classes.manage')) {
            return true;
        }

        return $user->ownSchoolClasses()->contains('id', $record->attendanceSession->school_class_id);
    }
}
