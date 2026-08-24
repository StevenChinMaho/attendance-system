<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RequiresPermission;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * 管理某個班級的學生名單——不是建立學生本體（那是 StudentManager 的
 * 事，見該檔案開頭的說明），這裡只做「把既有學生加入這個班（附上
 * 座號）」跟「把某個學生移出這個班」，比照 SchoolClassManager 指派
 * 導師的方式（下拉選單挑既有的 Teacher，不會在那裡順便新增老師本體）。
 */
class ClassRosterManager extends Component
{
    use RequiresPermission;

    protected string $requiredPermission = 'students.manage';

    public SchoolClass $schoolClass;

    public bool $showAttachForm = false;

    public ?int $attachingStudentId = null;

    public string $seatNumber = '';

    public ?int $editingSeatForStudentId = null;

    public function mount(SchoolClass $schoolClass): void
    {
        $this->schoolClass = $schoolClass;
    }

    protected function attachRules(): array
    {
        return [
            'attachingStudentId' => [
                'required',
                Rule::exists('students', 'id')->whereNotIn(
                    'id',
                    $this->schoolClass->students()->pluck('students.id')
                ),
            ],
            'seatNumber' => [
                'required', 'string', 'max:255',
                Rule::unique('school_class_student', 'seat_number')->where('school_class_id', $this->schoolClass->id),
            ],
        ];
    }

    protected function editSeatRules(): array
    {
        return [
            'seatNumber' => [
                'required', 'string', 'max:255',
                Rule::unique('school_class_student', 'seat_number')
                    ->where('school_class_id', $this->schoolClass->id)
                    ->ignore($this->editingSeatForStudentId, 'student_id'),
            ],
        ];
    }

    protected function messages(): array
    {
        return [
            'attachingStudentId.required' => '請選擇要加入的學生。',
            'attachingStudentId.exists' => '這個學生已經在這個班級裡了。',
            'seatNumber.unique' => '這個班級裡已經有相同座號的學生了。',
        ];
    }

    /**
     * 加入表單跟編輯座號表單共用同一個 $seatNumber 屬性——理由跟其他
     * Admin\*Manager 一樣，兩者互斥，開一個之前先把另一個關掉。
     */
    public function toggleAttachForm(): void
    {
        if ($this->showAttachForm) {
            $this->showAttachForm = false;

            return;
        }

        $this->cancelEditSeat();
        $this->reset(['attachingStudentId', 'seatNumber']);
        $this->showAttachForm = true;
    }

    public function attachStudent(): void
    {
        $this->validate($this->attachRules());

        $student = Student::findOrFail($this->attachingStudentId);

        $this->schoolClass->students()->attach($student->id, ['seat_number' => $this->seatNumber]);

        $this->reset(['attachingStudentId', 'seatNumber', 'showAttachForm']);

        session()->flash('status', "學生「{$student->displayName()}」已加入「{$this->schoolClass->shortLabel()}」。");
    }

    public function startEditSeat(Student $student): void
    {
        $this->showAttachForm = false;

        // 重新透過關聯查一次，確認這筆連結真的存在，順便讀出目前的座號
        // （pivot 屬性只有透過這個關聯查出來的 model 才會有）。
        $student = $this->schoolClass->students()->findOrFail($student->id);

        $this->editingSeatForStudentId = $student->id;
        $this->seatNumber = $student->pivot->seat_number;
    }

    public function updateSeat(): void
    {
        $this->validate($this->editSeatRules());

        $this->schoolClass->students()->findOrFail($this->editingSeatForStudentId);

        $this->schoolClass->students()->updateExistingPivot($this->editingSeatForStudentId, [
            'seat_number' => $this->seatNumber,
        ]);

        $this->cancelEditSeat();

        session()->flash('status', '座號已更新。');
    }

    public function cancelEditSeat(): void
    {
        $this->reset(['editingSeatForStudentId', 'seatNumber']);
    }

    /**
     * 移出班級不會刪除學生本體、也不會刪除他過去在這個班的點名紀錄
     * （attendance_records 透過 student_id／attendance_session_id 獨立
     * 對應，不靠這筆連結存在）——但移出之後，如果之後想回頭修正他在
     * 這個班的某一天點名紀錄，Recorder 的名冊會找不到他可以編輯（見
     * CLAUDE.md 的說明），所以在確認訊息裡先講清楚，不是靜靜地移除。
     */
    public function removeStudent(Student $student): void
    {
        $student = $this->schoolClass->students()->findOrFail($student->id);

        $this->schoolClass->students()->detach($student->id);

        session()->flash('status', "學生「{$student->displayName()}」已從「{$this->schoolClass->shortLabel()}」移除。");
    }

    public function render()
    {
        return view('livewire.admin.class-roster-manager', [
            'students' => $this->schoolClass->students()
                ->with(['user', 'currentDeparture'])
                ->orderBySeatNumber()
                ->get(),
            'availableStudents' => Student::whereNotIn(
                'id',
                $this->schoolClass->students()->pluck('students.id')
            )->orderBy('student_number')->get(),
        ]);
    }
}
