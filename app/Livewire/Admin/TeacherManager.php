<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RequiresPermission;
use App\Models\Teacher;
use App\Models\User;
use App\Rules\UserAccountIsUnlinked;
use Livewire\Component;
use Livewire\WithPagination;

class TeacherManager extends Component
{
    use RequiresPermission, WithPagination;

    protected string $requiredPermission = 'teachers.manage';

    public string $teacherName = '';

    public ?int $userId = null;

    public bool $showCreateForm = false;

    public ?int $editingTeacherId = null;

    /**
     * 有連結帳號時姓名不是必填——會直接沿用帳號的姓名（見
     * Teacher::resolveName()），這裡的 teacherName 值根本不會被用到。
     * 只有沒連結帳號的老師才需要真的驗證有沒有打姓名。
     */
    protected function rules(): array
    {
        return [
            'teacherName' => [$this->userId ? 'nullable' : 'required', 'string', 'max:255'],
            'userId' => [
                'nullable', 'exists:users,id',
                new UserAccountIsUnlinked(ignoreTeacherId: $this->editingTeacherId),
            ],
        ];
    }

    /**
     * 新增表單跟編輯表單共用同一組欄位屬性（$teacherName/$userId）——
     * 如果兩個表單同時顯示，畫面上會看起來像互相同步（其實就是同一個
     * 屬性），送出新增時甚至可能帶著正在編輯那筆資料的值一起送出去，
     * 撞到 unique 限制（見 StudentManager 同樣的問題）。這裡確保兩者
     * 互斥：開新增表單前一定先把編輯狀態清乾淨。
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

    public function createTeacher(): void
    {
        $this->validate();

        $teacher = Teacher::create([
            'teacher_name' => Teacher::resolveName($this->userId, $this->teacherName),
            'user_id' => $this->userId,
        ]);

        $this->reset(['teacherName', 'userId', 'showCreateForm']);

        session()->flash('status', "老師「{$teacher->teacher_name}」建立成功。");
    }

    public function startEdit(Teacher $teacher): void
    {
        // 理由跟 toggleCreateForm() 一樣：新增表單如果還開著，會跟編輯
        // 表單同時顯示、共用同一組欄位屬性。
        $this->showCreateForm = false;

        $this->editingTeacherId = $teacher->id;
        $this->teacherName = $teacher->teacher_name;
        $this->userId = $teacher->user_id;
    }

    public function updateTeacher(): void
    {
        $this->validate();

        $teacher = Teacher::findOrFail($this->editingTeacherId);
        $teacher->update([
            'teacher_name' => Teacher::resolveName($this->userId, $this->teacherName),
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
