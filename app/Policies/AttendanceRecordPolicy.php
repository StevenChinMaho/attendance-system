<?php

namespace App\Policies;

use App\Models\AttendanceRecord;
use App\Models\User;

/**
 * 「處理情形」學生、導師、管理者都能建立/查看（三種內建身分都有
 * attendance.follow_up.manage 權限，見 RolePermissionSeeder），跟
 * SchoolClassPolicy 一樣是權限（能不能做這種事）+ 範圍（哪個班）
 * 兩層一起檢查——有權限不代表看得到別班的紀錄，範圍那一層仍然只放行
 * 自己名下的班級。
 */
class AttendanceRecordPolicy
{
    /**
     * 範圍是全校的帳號一律放行，判斷集中在 User::hasAllClassAccess()。
     *
     * attendance.record.all（「點名所有班級」）也算在內：能幫任何一班點名
     * 卻不能在剛剛送出的那筆紀錄上寫處理情形，是說不通的。但它只放寬
     * 「範圍」——處理情形本身仍然要有 attendance.follow_up.manage 才做得了，
     * 那是上面第一道檢查。
     */
    public function manageFollowUp(User $user, AttendanceRecord $record): bool
    {
        if (! $user->can('attendance.follow_up.manage')) {
            return false;
        }

        if ($user->hasAllClassAccess()) {
            return true;
        }

        return $user->ownSchoolClasses()->contains('id', $record->attendanceSession->school_class_id);
    }
}
