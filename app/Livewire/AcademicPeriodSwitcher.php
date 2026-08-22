<?php

namespace App\Livewire;

use App\Support\AcademicPeriod;
use Livewire\Component;

/**
 * nav bar 上的學年度／學期切換選單——這是全系統最高層級的篩選（見
 * system_structure.md 學年制度），所以放在共用導覽列一次，而不是複製
 * 到每個受影響的頁面各自做一份。實際的篩選狀態存在 session（見
 * App\Support\AcademicPeriod），這個元件只負責「顯示可選範圍」跟
 * 「寫入使用者的選擇」，真正套用篩選的是各自元件上的
 * App\Livewire\Concerns\ScopesToSelectedAcademicPeriod。
 */
class AcademicPeriodSwitcher extends Component
{
    public int $year;

    public int $semester;

    public function mount(): void
    {
        $this->year = AcademicPeriod::selectedYear();
        $this->semester = AcademicPeriod::selectedSemester();
    }

    public function updatedYear(): void
    {
        $this->apply();
    }

    public function updatedSemester(): void
    {
        $this->apply();
    }

    /**
     * $year/$semester 是 wire:model 綁定的 public 屬性，client 端的互動
     * 請求理論上可以送任意整數，即使畫面上只給了下拉選單——只接受落在
     * 下拉選單本身允許範圍內的值，避免 session 被寫入沒有意義的學年度
     * ／學期組合。
     */
    protected function apply(): void
    {
        if (! in_array($this->year, AcademicPeriod::yearOptions(), true)) {
            $this->year = AcademicPeriod::selectedYear();

            return;
        }

        if (! array_key_exists($this->semester, AcademicPeriod::semesterOptions())) {
            $this->semester = AcademicPeriod::selectedSemester();

            return;
        }

        AcademicPeriod::setSelected($this->year, $this->semester);

        // 廣播給同一頁面上其他掛載的元件（例如 StatusBoard、
        // SchoolClassManager），讓它們立刻依新篩選重新渲染，不需要使用者
        // 自己再重新整理頁面。
        $this->dispatch('academic-period-changed');
    }

    public function render()
    {
        return view('livewire.academic-period-switcher', [
            'yearOptions' => AcademicPeriod::yearOptions(),
            'semesterOptions' => AcademicPeriod::semesterOptions(),
        ]);
    }
}
