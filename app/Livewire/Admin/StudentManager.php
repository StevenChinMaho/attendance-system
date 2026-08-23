<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RequiresPermission;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Rules\UserAccountIsUnlinked;
use Illuminate\Validation\Rule;
use Livewire\Component;

class StudentManager extends Component
{
    use RequiresPermission;

    protected string $requiredPermission = 'students.manage';

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

    /**
     * 新增表單跟編輯表單共用同一組欄位屬性（$studentNumber/$seatNumber/
     * $name/$gender/$userId）——如果兩個表單同時顯示，畫面上會看起來
     * 像互相同步（其實就是同一個屬性），送出新增時甚至可能帶著正在
     * 編輯那筆學生的學號／座號一起送出去，撞到這個班級裡的 unique
     * 限制直接噴 500。這裡確保兩者互斥：開新增表單前一定先把編輯狀態
     * 清乾淨。
     */
    public function toggleCreateForm(): void
    {
        if ($this->showCreateForm) {
            $this->showCreateForm = false;

            return;
        }

        $this->cancelEdit();
        $this->showCreateForm = true;
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
        // 理由跟 toggleCreateForm() 一樣：新增表單如果還開著，會跟編輯
        // 表單同時顯示、共用同一組欄位屬性。
        $this->showCreateForm = false;

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
            'students' => $this->schoolClass->students()->with('user')->orderBySeatNumber()->get(),
            'availableUsers' => User::availableForLinking(exceptStudentId: $this->editingStudentId)
                ->orderBy('name')
                ->get(),
        ]);
    }
}
