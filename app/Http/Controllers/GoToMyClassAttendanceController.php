<?php

namespace App\Http\Controllers;

use App\Support\AcademicPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * 學生跟導師只會管到自己的一個班級，不需要先選班級——直接從帳號連結
 * 的學生/老師身份反推出班級，導去對應的點名頁面。管理者沒有固定班級，
 * 要點名得從「班級管理」列表點進特定班級。
 *
 * 只在 nav bar 目前選取的學年度／學期（見 App\Support\AcademicPeriod）
 * 裡找班級——一位導師可能同時或跨學年帶過不只一班（見
 * User::ownSchoolClasses()），這裡只負責「自動導去哪一班」這個快捷
 * 方式，不是「這個帳號能不能管理某班」的授權判斷（那是
 * SchoolClassPolicy 的事，不受這個篩選限制）。同一個選取範圍裡有不只
 * 一班的情況，改用 nav bar 的班級選單自己挑，這裡固定挑第一筆
 * （依班級代號排序）。
 */
class GoToMyClassAttendanceController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $schoolClass = Auth::user()->ownSchoolClasses()
            ->where('academic_year', AcademicPeriod::selectedYear())
            ->where('semester', AcademicPeriod::selectedSemester())
            ->first();

        if (! $schoolClass) {
            return redirect()->route('dashboard')->withErrors([
                'attendance' => '你在目前選取的學年度／學期沒有帶班，可以切換導覽列的學年度查看其他學期。',
            ]);
        }

        return redirect()->route('attendance.show', $schoolClass);
    }
}
