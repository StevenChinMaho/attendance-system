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
    public function manageFollowUp(User $user, AttendanceRecord $record): bool
    {
        if (! $user->can('attendance.follow_up.manage')) {
            return false;
        }

        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->ownSchoolClasses()->contains('id', $record->attendanceSession->school_class_id);
    }
}
