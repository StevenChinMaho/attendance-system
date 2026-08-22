<?php

namespace App\Livewire\Attendance;

use App\Enums\AttendanceStatus;
use App\Livewire\Concerns\AttendancePeriods;
use App\Livewire\Concerns\ScopesToSelectedAcademicPeriod;
use App\Models\SchoolClass;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * 全校即時點名看板，給導師與管理者看（見 RolePermissionSeeder 的
 * attendance.dashboard.view）——不是給副班長看的，副班長只需要透過
 * Recorder 管好自己班。畫面用 wire:poll 輪詢更新，對應
 * system_structure.md「看板採輪詢方式更新即可，不需要 WebSocket」。
 *
 * 只顯示 nav bar 目前選取的學年度／學期（見
 * App\Livewire\Concerns\ScopesToSelectedAcademicPeriod）——這是全站
 * 最高層級的篩選，看板不例外，不然舊學年度已經凍結的班級會一直混在
 * 「目前」的總覽裡。
 */
class StatusBoard extends Component
{
    use ScopesToSelectedAcademicPeriod;

    public string $date = '';

    public string $period = '';

    /**
     * boot() 每次請求（含 wire:poll 的輪詢請求）都會重跑，不只是初次
     * mount——理由跟 Recorder::boot() 一樣：這個元件雖然只在
     * ShowDashboardController 決定要不要 render 它時檢查過一次，但那個
     * 檢查只在整頁載入當下生效，之後的每一次輪詢請求都要重新驗證。用
     * $this->authorize() 而不是 abort_unless()，跟 Recorder::boot() 用
     * 同一套寫法，避免同樣的事情在兩個地方各自用不同方式實作。
     */
    public function boot(): void
    {
        $this->authorize('attendance.dashboard.view');
    }

    public function mount(): void
    {
        $this->date = now()->toDateString();
        $this->period = AttendancePeriods::current();
    }

    public function render()
    {
        $classes = SchoolClass::query()
            ->where('academic_year', $this->selectedAcademicYear)
            ->where('semester', $this->selectedSemester)
            ->with(['students', 'attendanceSessions' => function ($query) {
                $query->where('date', $this->date)
                    ->where('period', $this->period)
                    ->with('records');
            }])
            ->orderBy('grade')
            ->orderByClassNumber()
            ->get();

        return view('livewire.attendance.status-board', [
            'summaries' => $classes->map(fn (SchoolClass $class) => $this->summarize($class)),
            'statusOptions' => AttendanceStatus::cases(),
        ]);
    }

    /**
     * @return array{class: SchoolClass, submitted: bool, total: int, counts: Collection, exceptions: Collection}
     */
    protected function summarize(SchoolClass $class): array
    {
        // 一個班同一天同一時段只會有一筆 session（migration 的唯一索引
        // 保證），eager load 時已經用 date+period 篩過，這裡直接取第一筆。
        $session = $class->attendanceSessions->first();

        if (! $session) {
            return [
                'class' => $class,
                'submitted' => false,
                'total' => $class->students->count(),
                'counts' => collect(),
                'exceptions' => collect(),
            ];
        }

        $records = $session->records;

        $counts = collect(AttendanceStatus::cases())->mapWithKeys(
            fn (AttendanceStatus $status) => [$status->value => $records->where('status', $status)->count()]
        );

        $exceptions = $records
            ->reject(fn ($record) => $record->status === AttendanceStatus::Present)
            ->map(fn ($record) => [
                'name' => $class->students->firstWhere('id', $record->student_id)?->name ?? '（學生資料不存在）',
                'status' => $record->status->label(),
            ])
            ->values();

        return [
            'class' => $class,
            'submitted' => true,
            'total' => $class->students->count(),
            'counts' => $counts,
            'exceptions' => $exceptions,
        ];
    }
}
