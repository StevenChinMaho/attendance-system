<?php

namespace Tests\Feature;

use App\Livewire\AttendanceQuickLink;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\AcademicPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AttendanceQuickLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_sees_a_class_picker_listing_every_class_in_the_selected_period(): void
    {
        // 管理者沒有「自己的班級」，點名快捷選單看的是目前選取學年度／
        // 學期裡的全部班級，不是 User::ownSchoolClasses()（管理者永遠
        // 回傳空集合）。
        $classA = SchoolClass::factory()->create(['grade' => 1, 'class_number' => '1']);
        $classB = SchoolClass::factory()->create(['grade' => 2, 'class_number' => '2']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(AttendanceQuickLink::class)
            ->assertSee($classA->shortLabel())
            ->assertSee($classB->shortLabel());
    }

    public function test_admin_sees_a_plain_link_when_only_one_class_exists_in_the_selected_period(): void
    {
        $class = SchoolClass::factory()->create();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(AttendanceQuickLink::class)
            ->assertSee(route('attendance.show', $class), false);
    }

    public function test_admin_sees_nothing_when_no_class_exists_in_the_selected_period(): void
    {
        // 管理者沒有「本來就該有一個預設班級」的期待，跟副班長/導師不
        // 一樣（見 attendance-quick-link.blade.php）——沒有班級可選就
        // 不顯示任何東西，不需要給錯誤提示連結。
        SchoolClass::factory()->create(['academic_year' => 112, 'semester' => 1]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(AttendanceQuickLink::class)
            ->assertDontSee('點名');
    }

    public function test_admin_class_picker_respects_the_selected_academic_period(): void
    {
        $currentClass = SchoolClass::factory()->create();
        $otherClass = SchoolClass::factory()->create(['academic_year' => 112, 'semester' => 1]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $component = Livewire::actingAs($admin)->test(AttendanceQuickLink::class);
        $component->assertSee(route('attendance.show', $currentClass), false)
            ->assertDontSee(route('attendance.show', $otherClass), false);

        // 模擬 nav bar 的 AcademicPeriodSwitcher 已經把新選擇寫進
        // session，並廣播 academic-period-changed 事件。
        AcademicPeriod::setSelected(112, 1);
        $component->dispatch('academic-period-changed');

        $component->assertSee(route('attendance.show', $otherClass), false)
            ->assertDontSee(route('attendance.show', $currentClass), false);
    }

    public function test_admin_sees_the_attendance_quick_link_in_the_nav_bar(): void
    {
        $class = SchoolClass::factory()->create();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertSee(route('attendance.show', $class), false);
    }
}
