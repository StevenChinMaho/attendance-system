<?php

namespace Tests\Feature;

use App\Livewire\AcademicPeriodSwitcher;
use App\Models\User;
use App\Support\AcademicPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AcademicPeriodSwitcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_switching_broadcasts_the_change_for_other_components_to_pick_up(): void
    {
        // 實際寫入 session 的動作是 AcademicPeriod::setSelected()，這條路徑
        // 已經由 AcademicPeriodTest（純粹呼叫，不經過 Livewire）跟
        // StatusBoardTest/SchoolClassManagerTest（消費端元件確實讀到值）
        // 驗證過；這裡只需要確認合法輸入會讓元件保留新值、並廣播事件。
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        // 112 目前不在下拉選單範圍內（沒有任何班級紀錄時，範圍是「目前
        // 學年度～+10」），選一個確實落在範圍內的值，只驗證合法切換本身
        // 的行為，不是在測 yearOptions() 的範圍計算（那是 AcademicPeriodTest
        // 的事）。
        $validYear = AcademicPeriod::currentYear() + 1;

        Livewire::actingAs($admin)
            ->test(AcademicPeriodSwitcher::class)
            ->set('year', $validYear)
            ->set('semester', 2)
            ->assertSet('year', $validYear)
            ->assertSet('semester', 2)
            ->assertDispatched('academic-period-changed');
    }

    public function test_a_year_outside_the_dropdown_range_is_rejected(): void
    {
        // $year 是 wire:model 綁定的 public 屬性，client 端理論上可以送
        // 任意整數，即使畫面上只提供下拉選單——不在 AcademicPeriod::yearOptions()
        // 範圍內的值必須被拒絕，不能寫進 session。
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(AcademicPeriodSwitcher::class)
            ->set('year', 9999)
            ->assertNotDispatched('academic-period-changed');

        $this->assertNotSame(9999, AcademicPeriod::selectedYear());
    }

    public function test_a_semester_outside_one_or_two_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(AcademicPeriodSwitcher::class)
            ->set('semester', 3)
            ->assertNotDispatched('academic-period-changed');

        $this->assertNotSame(3, AcademicPeriod::selectedSemester());
    }
}
