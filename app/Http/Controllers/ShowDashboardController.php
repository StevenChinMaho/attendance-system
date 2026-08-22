<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 登入後首頁的內容依角色而不同：導師/管理者看即時點名看板，副班長
 * 看不到全校總覽（只需要透過 Recorder 管好自己班），落到一個簡單的
 * 歡迎頁。決定「該渲染哪個畫面」放在 controller，而不是寫在 Blade
 * 裡的條件判斷，跟這個專案其他需要挑 view 的路由（ShowAttendanceController、
 * GoToMyClassAttendanceController）用同一個模式，view 本身維持單一用途。
 */
class ShowDashboardController extends Controller
{
    public function __invoke(): View
    {
        if (Auth::user()->can('attendance.dashboard.view')) {
            return view('dashboard.status-board');
        }

        return view('dashboard.welcome');
    }
}
