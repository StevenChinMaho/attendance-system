<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\AttendancePeriods;
use App\Livewire\Concerns\ScopesToSelectedAcademicPeriod;
use App\Models\AttendanceRecord;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * 全校即時點名看板，給導師與管理者看（見 RolePermissionSeeder 的
 * attendance.dashboard.view）——不是給學生看的，學生只需要透過
 * Recorder 管好自己班。畫面用 wire:poll 輪詢更新，對應
 * system_structure.md「看板採輪詢方式更新即可，不需要 WebSocket」。
 *
 * 只顯示 nav bar 目前選取的學年度／學期（見
 * App\Livewire\Concerns\ScopesToSelectedAcademicPeriod）——這是全站
 * 最高層級的篩選，看板不例外，不然舊學年度已經凍結的班級會一直混在
 * 「目前」的總覽裡。
 *
 * 看板一次呈現「這一天」上午/中午/下午三個時段的彙總（見 summarize()），
 * 不再像 Recorder 那樣一次只看一個時段——這裡要回答的問題是「今天
 * 整體狀況如何」，早自己選時段反而要來回切三次才看得到全貌。
 */
class StatusBoard extends Component
{
    use ScopesToSelectedAcademicPeriod;

    public string $date = '';

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
    }

    public function render()
    {
        $classes = SchoolClass::query()
            ->where('academic_year', $this->selectedAcademicYear)
            ->where('semester', $this->selectedSemester)
            ->with(['students.user', 'students.departures', 'attendanceSessions' => function ($query) {
                // 這一天三個時段的 session 一次撈出來（不再只篩單一
                // 時段），summarize() 自己依時段分組。
                $query->where('date', $this->date)->with(['records.followUps']);
            }])
            ->orderBy('grade')
            ->orderByClassNumber()
            ->get();

        return view('livewire.attendance.status-board', [
            'summaries' => $classes->map(fn (SchoolClass $class) => $this->summarize($class)),
            'periods' => AttendancePeriods::PERIODS,
        ]);
    }

    /**
     * @return array{class: SchoolClass, total: int, periods: array<string, array{submitted: bool, present?: int, absent?: int}>, exceptions: Collection}
     */
    protected function summarize(SchoolClass $class): array
    {
        $sessionsByPeriod = $class->attendanceSessions->keyBy('period');
        $exceptions = collect();

        $periods = collect(AttendancePeriods::PERIODS)->mapWithKeys(function (string $periodLabel, string $periodValue) use ($sessionsByPeriod, $class, &$exceptions) {
            $session = $sessionsByPeriod->get($periodValue);

            if (! $session) {
                return [$periodValue => ['submitted' => false]];
            }

            $presentCount = $session->records->filter(fn (AttendanceRecord $record) => $record->status->countsAsPresent())->count();

            $exceptionRecords = $session->records->reject(fn (AttendanceRecord $record) => $record->status->countsAsPresent());

            foreach ($exceptionRecords as $record) {
                $exceptions->push([
                    'name' => $class->students->firstWhere('id', $record->student_id)?->displayName() ?? '（學生資料不存在）',
                    'period' => $periodLabel,
                    'status' => $record->status->label(),
                    'followUps' => $record->followUps,
                ]);
            }

            return [$periodValue => [
                'submitted' => true,
                'present' => $presentCount,
                'absent' => $session->records->count() - $presentCount,
            ]];
        });

        return [
            'class' => $class,
            // 已轉出的學生不算進「應到」，但轉出當天算他還在讀，理由跟
            // Recorder::students() 一樣，可能有好幾段轉出/轉入歷史，見
            // Student::isEnrolledOn()——這裡是已經 eager load 好
            // students.departures 的 Collection，用 filter() 而不是
            // 查詢層的條件。
            'total' => $class->students->filter(
                fn (Student $student) => $student->isEnrolledOn($this->date)
            )->count(),
            'periods' => $periods,
            'exceptions' => $exceptions,
        ];
    }
}
