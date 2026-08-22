<?php

namespace App\Livewire\Concerns;

use App\Support\AcademicPeriod;
use Livewire\Attributes\On;

/**
 * 學年度／學期篩選是跨元件共用的狀態（存在 session，見
 * App\Support\AcademicPeriod），SchoolClassManager 跟 StatusBoard 都要
 * 依「目前選取」的學年度／學期篩選各自的列表，用共用 trait 而不是
 * 各自複製一份——理由跟 App\Livewire\Concerns\RequiresAdminRole 一樣。
 *
 * boot{TraitName}() 是 Livewire 的 per-trait 生命週期 hook 命名慣例，
 * 在 mount() 跟之後每一次 hydrate（每次互動請求，含 wire:poll）都會
 * 重跑，所以就算沒有下面的事件監聽，元件本來就會在下一次任何互動時
 * 讀到 session 裡最新的選擇。事件監聽的用意是讓「切換學年度」不需要
 * 使用者自己在同一個元件上再多做一次互動就能立刻反映在畫面上——nav
 * bar 的 App\Livewire\AcademicPeriodSwitcher 換好之後，會 dispatch 這個
 * 瀏覽器事件廣播給同一頁面上所有掛載的元件，讓他們重新渲染。
 */
trait ScopesToSelectedAcademicPeriod
{
    public int $selectedAcademicYear;

    public int $selectedSemester;

    public function bootScopesToSelectedAcademicPeriod(): void
    {
        $this->selectedAcademicYear = AcademicPeriod::selectedYear();
        $this->selectedSemester = AcademicPeriod::selectedSemester();
    }

    #[On('academic-period-changed')]
    public function refreshSelectedAcademicPeriod(): void
    {
        // 方法本身特意留空：真正重新讀取 session 的動作已經由
        // bootScopesToSelectedAcademicPeriod() 在這次事件觸發的 hydrate
        // 一開始做完了，這個方法只是讓 Livewire 知道這個元件要對
        // academic-period-changed 事件重新渲染。
    }
}
