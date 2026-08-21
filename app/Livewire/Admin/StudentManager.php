<?php

namespace App\Livewire\Admin;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Rules\UserAccountIsUnlinked;
use Illuminate\Validation\Rule;
use Livewire\Component;

class StudentManager extends Component
{
    public SchoolClass $schoolClass;

    public string $studentNumber = '';

    public string $seatNumber = '';

    public string $name = '';

    public string $gender = '';

    public ?int $userId = null;

    public bool $showCreateForm = false;

    public ?int $editingStudentId = null;

    public function mount(SchoolClass $schoolClass): void
    {
        $this->schoolClass = $schoolClass;
    }

    protected function rules(): array
    {
        return [
            'studentNumber' => [
                'required', 'string', 'max:255',
                Rule::unique('students', 'student_number')
                    ->where('school_class_id', $this->schoolClass->id)
                    ->ignore($this->editingStudentId),
            ],
            'seatNumber' => [
                'required', 'string', 'max:255',
                Rule::unique('students', 'seat_number')
                    ->where('school_class_id', $this->schoolClass->id)
                    ->ignore($this->editingStudentId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'in:男,女'],
            'userId' => [
                'nullable', 'exists:users,id',
                new UserAccountIsUnlinked(ignoreStudentId: $this->editingStudentId),
            ],
        ];
    }

    protected function messages(): array
    {
        return [
            'studentNumber.unique' => '這個班級裡已經有相同學號的學生了。',
            'seatNumber.unique' => '這個班級裡已經有相同座號的學生了。',
        ];
    }

    public function createStudent(): void
    {
        $this->validate();

        $student = $this->schoolClass->students()->create([
            'student_number' => $this->studentNumber,
            'seat_number' => $this->seatNumber,
            'name' => $this->name,
            'gender' => $this->gender,
            'user_id' => $this->userId,
        ]);

        $this->reset(['studentNumber', 'seatNumber', 'name', 'gender', 'userId', 'showCreateForm']);

        session()->flash('status', "學生「{$student->name}」建立成功。");
    }

    public function startEdit(Student $student): void
    {
        $this->editingStudentId = $student->id;
        $this->studentNumber = $student->student_number;
        $this->seatNumber = $student->seat_number;
        $this->name = $student->name;
        $this->gender = $student->gender;
        $this->userId = $student->user_id;
    }

    public function updateStudent(): void
    {
        $this->validate();

        $student = $this->schoolClass->students()->findOrFail($this->editingStudentId);
        $student->update([
            'student_number' => $this->studentNumber,
            'seat_number' => $this->seatNumber,
            'name' => $this->name,
            'gender' => $this->gender,
            'user_id' => $this->userId,
        ]);

        $this->cancelEdit();

        session()->flash('status', "學生「{$student->name}」已更新。");
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingStudentId', 'studentNumber', 'seatNumber', 'name', 'gender', 'userId']);
    }

    public function render()
    {
        return view('livewire.admin.student-manager', [
            'students' => $this->schoolClass->students()->with('user')->orderBy('seat_number')->get(),
            'availableUsers' => User::availableForLinking(exceptStudentId: $this->editingStudentId)
                ->orderBy('name')
                ->get(),
        ]);
    }
}
