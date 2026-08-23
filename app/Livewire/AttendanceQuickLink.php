<?php

namespace App\Livewire;

use App\Livewire\Concerns\ScopesToSelectedAcademicPeriod;
use App\Models\SchoolClass;
use Livewire\Component;

/**
 * nav bar 上的「點名」快捷入口，副班長、導師、管理者共用同一個介面，
 * 不再是管理者得另外從「班級管理」列表點進去才能點名——差別只在於
 * 「可以選哪些班級」：副班長/導師只看得到自己名下的班級（見
 * User::ownSchoolClasses()），管理者沒有固定班級，看的是「目前選取
 * 學年度／學期」裡的全部班級。只有一個班可選時顯示單一連結，超過一
 * 個班顯示下拉選單，介面邏輯三種角色共用一份。
 *
 * 用 Livewire 元件而不是塞進 nav-bar.blade.php 的純 Blade @php 區塊：
 * 一來管理者「查全部班級」這種查詢邏輯不適合直接寫在共用導覽列的
 * Blade 樣板裡；二來 nav bar 本身是靜態 Blade component，只在整頁
 * 載入時渲染一次——這個元件用 ScopesToSelectedAcademicPeriod 訂閱
 * academic-period-changed 事件，讓這個選單能跟著 nav bar 的學年度
 * 切換選單即時更新，不用重新整理整頁。
 */
class AttendanceQuickLink extends Component
{
    use ScopesToSelectedAcademicPeriod;

    public function render()
    {
        $user = auth()->user();

        $classes = $user->hasRole('admin')
            ? SchoolClass::query()
                ->where('academic_year', $this->selectedAcademicYear)
                ->where('semester', $this->selectedSemester)
                ->orderBy('grade')
                ->orderByClassNumber()
                ->get()
            : $user->ownSchoolClasses()
                ->where('academic_year', $this->selectedAcademicYear)
                ->where('semester', $this->selectedSemester);

        return view('livewire.attendance-quick-link', [
            'classes' => $classes,
        ]);
    }
}
