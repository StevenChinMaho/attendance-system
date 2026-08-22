<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;

/**
 * spatie/laravel-permission 只回答「這個帳號有沒有這種權限」（例如能不能
 * 點名），回答不了「只能對自己班做這件事」這種資料範圍限制——這一層要靠
 * Policy 結合 users → students/teachers 的關聯自己判斷，見 CLAUDE.md
 * 「Row-level scoping」。
 */
class SchoolClassPolicy
{
    /**
     * 這個帳號可不可以幫這個班級點名（新增或修改 attendance_sessions）。
     * 副班長/導師可能同時或跨學年帶過不只一個班（見
     * User::ownSchoolClasses()），只要目標班級在名下清單裡就放行，不限定
     * 只能是「目前最新」那一筆——過去帶過的班級之後仍可能需要回頭補登
     * 或更正點名紀錄。
     */
    public function recordAttendance(User $user, SchoolClass $schoolClass): bool
    {
        if (! $user->can('attendance.record')) {
            return false;
        }

        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->ownSchoolClasses()->contains('id', $schoolClass->id);
    }
}
