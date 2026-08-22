<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RequiresAdminRole;
use App\Models\Teacher;
use App\Models\User;
use App\Rules\UserAccountIsUnlinked;
use Livewire\Component;
use Livewire\WithPagination;

class TeacherManager extends Component
{
    use RequiresAdminRole, WithPagination;

    public string $teacherName = '';

    public ?int $userId = null;

    public bool $showCreateForm = false;

    public ?int $editingTeacherId = null;

    protected function rules(): array
    {
        return [
            'teacherName' => ['required', 'string', 'max:255'],
            'userId' => [
                'nullable', 'exists:users,id',
                new UserAccountIsUnlinked(ignoreTeacherId: $this->editingTeacherId),
            ],
        ];
    }

    public function createTeacher(): void
    {
        $this->validate();

        $teacher = Teacher::create([
            'teacher_name' => $this->teacherName,
            'user_id' => $this->userId,
        ]);

        $this->reset(['teacherName', 'userId', 'showCreateForm']);

        session()->flash('status', "老師「{$teacher->teacher_name}」建立成功。");
    }

    public function startEdit(Teacher $teacher): void
    {
        $this->editingTeacherId = $teacher->id;
        $this->teacherName = $teacher->teacher_name;
        $this->userId = $teacher->user_id;
    }

    public function updateTeacher(): void
    {
        $this->validate();

        $teacher = Teacher::findOrFail($this->editingTeacherId);
        $teacher->update([
            'teacher_name' => $this->teacherName,
            'user_id' => $this->userId,
        ]);

        $this->cancelEdit();

        session()->flash('status', "老師「{$teacher->teacher_name}」已更新。");
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingTeacherId', 'teacherName', 'userId']);
    }

    public function render()
    {
        return view('livewire.admin.teacher-manager', [
            'teachers' => Teacher::with('user')->orderBy('teacher_name')->paginate(15),
            'availableUsers' => User::availableForLinking(exceptTeacherId: $this->editingTeacherId)
                ->orderBy('name')
                ->get(),
        ]);
    }
}
