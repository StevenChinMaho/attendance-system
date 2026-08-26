<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * 學生身分只能在校內作息時間內點名（07:00～17:00），避免深夜或清晨
 * 出現不該有的點名操作；導師、管理者不受限制——他們本來就有補登、
 * 事後更正的需求，時間限制反而會擋住正當的行政作業。
 *
 * 「誰不受限制」不是看角色名稱，而是看有沒有 attendance.record.anytime
 * 權限（見 RolePermissionSeeder、App\Livewire\Attendance\Recorder），
 * 理由跟這個專案其他權限檢查一樣：寫死 hasRole('student_rep') 的話，
 * 之後在 /admin/roles 建一個「跟導師權限一樣」的自訂身分，會莫名其妙
 * 也被時間限制擋住——同樣形狀的 bug 這個專案已經發生並修過一次。
 *
 * 跟 AcademicPeriod／AttendancePeriods／ClassCode 一樣是 plain final
 * class 而不是 trait：PHP 不允許從 use 它的類別以外存取 trait 常數，
 * Blade 或別的類別要拿 START_HOUR 就會編譯失敗。
 */
final class AttendanceWindow
{
    public const START_HOUR = 7;

    /**
     * 開放到 17:00 之前（也就是 16:59 還可以，17:00 整就關閉）——用整點
     * 比較而不是比到秒，邊界才不會出現「17:00:00 可以但 17:00:01 不行」
     * 這種需要看秒數才解釋得通的行為。
     */
    public const END_HOUR = 17;

    public static function isOpen(?Carbon $at = null): bool
    {
        $hour = ($at ?? now())->hour;

        return $hour >= self::START_HOUR && $hour < self::END_HOUR;
    }

    /**
     * 給畫面上的提示文字用，例如「07:00～17:00」——開始/結束時間只定義
     * 在這個類別的常數裡，Blade 不要自己再寫死一次字串。
     */
    public static function label(): string
    {
        return sprintf('%02d:00～%02d:00', self::START_HOUR, self::END_HOUR);
    }
}
