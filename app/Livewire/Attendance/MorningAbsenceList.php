<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\RequiresPermission;
use App\Livewire\Concerns\ScopesToSelectedAcademicPeriod;
use App\Models\AttendanceRecord;
use App\Models\SchoolClass;
use App\Support\ClassCode;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * 上午缺席詳細清單——給學務處列印貼出／存查用的紙本名單，取代以往人工
 * 抄寫的那張遲到名單（見 system_structure.md 使用場景）。
 *
 * 跟 StatusBoard 的差別：看板是「每班幾人到、幾人沒到」的即時總覽，這裡
 * 是「沒到的是誰」的逐人清單，而且只看上午一個時段。兩者資料來源相同但
 * 用途不同，沒有共用彙總邏輯——看板要三個時段併排、要處理情形的懸浮
 * 卡片，這裡只要一個時段的平鋪清單，硬要共用只會讓兩邊都變複雜。
 *
 * 「到齊」跟「未送出」是兩件不同的事，都要照樣列出來（不是略過）：紙本
 * 的用途正是讓學務處一眼看出「哪一班還沒回報」，如果未送出的班級直接
 * 不出現在表上，那張紙就看不出漏了誰——這跟 attendance_sessions 存在
 * 與否就是「有沒有點名」的信號是同一件事（見 CLAUDE.md）。
 */
class MorningAbsenceList extends Component
{
    use RequiresPermission, ScopesToSelectedAcademicPeriod;

    protected string $requiredPermission = 'attendance.dashboard.view';

    /**
     * 這份清單固定看上午——名稱就叫「上午缺席詳細清單」，時段不是使用者
     * 可調的參數。日期則比照 Recorder/StatusBoard 可以往回選，補印昨天
     * 漏印的名單是實際會發生的需求。
     */
    public const PERIOD = 'MORNING';

    public string $date = '';

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    public function render()
    {
        $classes = SchoolClass::query()
            ->where('academic_year', $this->selectedAcademicYear)
            ->where('semester', $this->selectedSemester)
            // students.user：Student::displayName() 有連結帳號時會讀
            // $this->user->name，沒 eager load 會每個學生各自多查一次。
            ->with(['students.user', 'attendanceSessions' => function ($query) {
                $query->where('date', $this->date)->where('period', self::PERIOD)->with('records');
            }])
            ->orderBy('grade')
            ->orderByClassNumber()
            ->get();

        return view('livewire.attendance.morning-absence-list', [
            'rows' => $classes->map(fn (SchoolClass $class) => $this->summarize($class)),
        ]);
    }

    /**
     * 一個班一列資料，absentees 可能是空的（到齊）；session 不存在時
     * submitted 為 false（未送出），此時 absentees 一定是空的，畫面上
     * 要顯示的是「未送出」而不是「到齊」——這兩種狀況在紙本上的意義
     * 完全相反，不能混為一談。
     *
     * @return array{code: string, submitted: bool, absentees: Collection<int, array{seat_number: string, name: string, status: string}>}
     */
    protected function summarize(SchoolClass $class): array
    {
        $session = $class->attendanceSessions->first();

        return [
            'code' => ClassCode::format($class->grade, $class->class_number),
            'submitted' => (bool) $session,
            'absentees' => $session ? $this->absenteesIn($class, $session->records) : collect(),
        ];
    }

    /**
     * 「缺席」或「早退」的學生——剛好跟 AttendanceStatus::countsAsPresent()
     * 的反面一致（遲到的人有到校，不列進這張表）。座號在多對多的中間表
     * pivot 上，不在 students 表（見 SchoolClass::students()）。
     *
     * @param  Collection<int, AttendanceRecord>  $records
     * @return Collection<int, array{seat_number: string, name: string, status: string}>
     */
    protected function absenteesIn(SchoolClass $class, Collection $records): Collection
    {
        $studentsById = $class->students->keyBy('id');

        return $records
            ->reject(fn (AttendanceRecord $record) => $record->status->countsAsPresent())
            ->map(function (AttendanceRecord $record) use ($studentsById) {
                $student = $studentsById->get($record->student_id);

                return [
                    // 這個學生可能已經被移出班級（見 ClassRosterManager），
                    // 但點名紀錄還在——紙本上寧可標示查不到，也不要整列
                    // 消失讓人數對不起來。
                    'seat_number' => $student?->pivot->seat_number ?? '—',
                    'name' => $student?->displayName() ?? '（已不在此班級）',
                    'status' => $record->status->label(),
                ];
            })
            // 座號是字串（見 Student::scopeOrderBySeatNumber() 的說明），
            // 直接排序會讓 "10" 跑到 "2" 前面。這裡是已經載入記憶體的
            // Collection，用不到查詢層那個 LENGTH() 技巧，補零到固定寬度
            // 就等價。
            ->sortBy(fn (array $row) => str_pad($row['seat_number'], 10, '0', STR_PAD_LEFT))
            ->values();
    }
}
