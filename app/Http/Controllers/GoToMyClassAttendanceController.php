<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * 副班長跟導師只會管到自己的一個班級，不需要先選班級——直接從帳號連結
 * 的學生/老師身份反推出班級，導去對應的點名頁面。管理者沒有固定班級，
 * 要點名得從「班級管理」列表點進特定班級。
 */
class GoToMyClassAttendanceController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $schoolClass = Auth::user()->ownSchoolClass();

        if (! $schoolClass) {
            return redirect()->route('dashboard')->withErrors([
                'attendance' => '你的帳號目前沒有連結任何班級，請聯絡管理者。',
            ]);
        }

        return redirect()->route('attendance.show', $schoolClass);
    }
}
