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
     *
     * can('classes.manage') 的帳號（內建的 admin，或是 /admin/roles 建立
     * 出來、被賦予這個權限的自訂身分）永遠放行，不受 ownSchoolClasses()
     * 限制——這裡刻意檢查 permission 而不是寫死 hasRole('admin')：一個
     * 自訂身分就算被賦予了跟 admin 一模一樣的權限組合，也不會連結到任何
     * 一個 Teacher/Student 業務身份，ownSchoolClasses() 對它必定回傳空
     * 集合，如果只認字面上的 admin 角色，這種自訂身分會完全點不了名，
     * 即使它「應該」擁有等同 admin 的存取範圍。classes.manage 是刻意選定
     * 的判斷依據，因為它本來就代表「能新增/修改任何班級」的管理層級，
     * 授權範圍延伸到「能操作任何班級的點名」是一致的。
     */
    public function recordAttendance(User $user, SchoolClass $schoolClass): bool
    {
        if (! $user->can('attendance.record')) {
            return false;
        }

        if ($user->can('classes.manage')) {
            return true;
        }

        return $user->ownSchoolClasses()->contains('id', $schoolClass->id);
    }
}
