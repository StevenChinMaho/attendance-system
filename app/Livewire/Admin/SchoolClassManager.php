<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RequiresAdminRole;
use App\Models\SchoolClass;
use App\Models\Teacher;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class SchoolClassManager extends Component
{
    use RequiresAdminRole, WithPagination;

    public string $academicYear = '';

    public string $semester = '';

    public string $grade = '';

    public string $classNumber = '';

    public ?int $homeroomTeacherId = null;

    public bool $showCreateForm = false;

    public ?int $editingClassId = null;

    protected function rules(): array
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
        $this->validate();

        $class = SchoolClass::create([
            'academic_year' => $this->academicYear,
            'semester' => $this->semester,
            'grade' => $this->grade,
            'class_number' => $this->classNumber,
            'homeroom_teacher_id' => $this->homeroomTeacherId,
        ]);

        $this->reset(['academicYear', 'semester', 'grade', 'classNumber', 'homeroomTeacherId', 'showCreateForm']);

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
        $this->validate();

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

    public function render()
    {
        return view('livewire.admin.school-class-manager', [
            // class_number 是字串欄位（保留容納非數字班級代號的彈性，見 migration
            // 註解），直接排序會讓 "10" 排到 "2" 前面，所以先按長度排、再按字典序，
            // 對純數字代號能達到自然排序的效果，非數字代號則退化成一般字串排序。
            'classes' => SchoolClass::with(['homeroomTeacher', 'students'])
                ->orderByDesc('academic_year')->orderBy('grade')
                ->orderByRaw('LENGTH(class_number) asc')->orderBy('class_number')
                ->paginate(15),
            'teachers' => Teacher::orderBy('teacher_name')->get(),
        ]);
    }
}
