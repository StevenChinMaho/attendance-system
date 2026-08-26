<?php

namespace Tests\Feature\Support;

use App\Support\AttendanceWindow;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceWindowTest extends TestCase
{
    public function test_the_window_is_open_during_school_hours(): void
    {
        $this->assertTrue(AttendanceWindow::isOpen(Carbon::parse('2026-08-26 07:00:00')));
        $this->assertTrue(AttendanceWindow::isOpen(Carbon::parse('2026-08-26 12:30:00')));
        $this->assertTrue(AttendanceWindow::isOpen(Carbon::parse('2026-08-26 16:59:59')));
    }

    public function test_the_window_is_closed_before_it_opens(): void
    {
        $this->assertFalse(AttendanceWindow::isOpen(Carbon::parse('2026-08-26 06:59:59')));
        $this->assertFalse(AttendanceWindow::isOpen(Carbon::parse('2026-08-26 00:00:00')));
    }

    public function test_the_window_is_closed_from_the_closing_hour_onwards(): void
    {
        // 17:00 整就關閉——用整點比較，邊界不會出現「17:00:00 可以但
        // 17:00:01 不行」這種要看秒數才解釋得通的行為。
        $this->assertFalse(AttendanceWindow::isOpen(Carbon::parse('2026-08-26 17:00:00')));
        $this->assertFalse(AttendanceWindow::isOpen(Carbon::parse('2026-08-26 23:59:59')));
    }

    public function test_it_falls_back_to_the_current_time(): void
    {
        Carbon::setTestNow('2026-08-26 09:00:00');
        $this->assertTrue(AttendanceWindow::isOpen());

        Carbon::setTestNow('2026-08-26 22:00:00');
        $this->assertFalse(AttendanceWindow::isOpen());

        Carbon::setTestNow();
    }

    public function test_the_label_reflects_the_configured_hours(): void
    {
        // 提示文字裡的時間不該在 Blade 裡再寫死一次。
        $this->assertSame('07:00～17:00', AttendanceWindow::label());
    }
}
