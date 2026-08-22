<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RequiresAdminRole;
use App\Livewire\Concerns\ScopesToSelectedAcademicPeriod;
use App\Models\SchoolClass;
use App\Models\Teacher;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class SchoolClassManager extends Component
{
    use RequiresAdminRole, ScopesToSelectedAcademicPeriod, WithPagination;

    public string $academicYear = '';

    public string $semester = '';

    public string $grade = '';

    public string $classNumber = '';

    public ?int $homeroomTeacherId = null;

    public bool $showCreateForm = false;

    public ?int $editingClassId = null;

    /**
     * 新增班級時，學年度／學期不開放自由輸入，一律鎖定成 nav bar 目前
     * 選取的學年度／學期（$selectedAcademicYear/$selectedSemester，見
     * App\Livewire\Concerns\ScopesToSelectedAcademicPeriod）——避免瀏覽
     * 某個學年度的列表時手滑把新班級建到別的學年度去，新班級應該永遠
     * 落在使用者當下正在看的那個學年度。編輯既有班級不受此限制，維持
     * 可自由調整（例如更正資料建檔錯誤）。
     */
    protected function createRules(): array
    {
        return [
            'grade' => ['required', 'integer', 'in:1,2,3'],
            'classNumber' => [
                'required', 'string', 'max:255',
                Rule::unique('school_classes', 'class_number')
                    ->where('academic_year', $this->selectedAcademicYear)
                    ->where('semester', $this->selectedSemester)
                    ->where('grade', $this->grade),
            ],
            'homeroomTeacherId' => ['nullable', 'exists:teachers,id'],
        ];
    }

    protected function updateRules(): array
    {
        return [
            'academicYear' => ['required', 'integer', 'min:100', 'max:200'],
            'semester' => ['required', 'integer', 'in:1,2'],
            'grade' => ['required', 'integer', 'in:1,2,3'],
            'classNumber' => [
                'required', 'string', 'max:255',
                Rule::unique('school_classes', 'class_number')
                    ->where('academic_year', $this->academicYear)
                    ->where('semester', $this->semester)
                    ->where('grade', $this->grade)
                    ->ignore($this->editingClassId),
            ],
            'homeroomTeacherId' => ['nullable', 'exists:teachers,id'],
        ];
    }

    protected function messages(): array
    {
        return [
            'classNumber.unique' => '同一個學年度、學期、年級底下已經有這個班級代號了。',
        ];
    }

    public function createClass(): void
    {
        $this->validate($this->createRules());

        $class = SchoolClass::create([
            'academic_year' => $this->selectedAcademicYear,
            'semester' => $this->selectedSemester,
            'grade' => $this->grade,
            'class_number' => $this->classNumber,
            'homeroom_teacher_id' => $this->homeroomTeacherId,
        ]);

        $this->reset(['grade', 'classNumber', 'homeroomTeacherId', 'showCreateForm']);

        session()->flash('status', "班級「{$class->label()}」建立成功。");
    }

    public function startEdit(SchoolClass $schoolClass): void
    {
        $this->editingClassId = $schoolClass->id;
        $this->academicYear = (string) $schoolClass->academic_year;
        $this->semester = (string) $schoolClass->semester;
        $this->grade = (string) $schoolClass->grade;
        $this->classNumber = $schoolClass->class_number;
        $this->homeroomTeacherId = $schoolClass->homeroom_teacher_id;
    }

    public function updateClass(): void
    {
        $this->validate($this->updateRules());

        $class = SchoolClass::findOrFail($this->editingClassId);
        $class->update([
            'academic_year' => $this->academicYear,
            'semester' => $this->semester,
            'grade' => $this->grade,
            'class_number' => $this->classNumber,
            'homeroom_teacher_id' => $this->homeroomTeacherId,
        ]);

        $this->cancelEdit();

        session()->flash('status', "班級「{$class->label()}」已更新。");
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingClassId', 'academicYear', 'semester', 'grade', 'classNumber', 'homeroomTeacherId']);
    }

    /**
     * 學年度／學期一切換，先前分頁選到的頁碼很可能已經超出新篩選結果
     * 的範圍——ScopesToSelectedAcademicPeriod 本身不假設每個用它的元件
     * 都有分頁，所以分頁重置放在這裡而不是共用 trait 裡。
     */
    #[On('academic-period-changed')]
    public function resetPageOnPeriodChange(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.admin.school-class-manager', [
            'classes' => SchoolClass::with(['homeroomTeacher', 'students'])
                ->where('academic_year', $this->selectedAcademicYear)
                ->where('semester', $this->selectedSemester)
                ->orderBy('grade')
                ->orderByClassNumber()
                ->paginate(15),
            'teachers' => Teacher::orderBy('teacher_name')->get(),
        ]);
    }
}
