<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RequiresPermission;
use App\Livewire\Concerns\SortsColumns;
use App\Models\Teacher;
use App\Models\User;
use App\Rules\UserAccountIsUnlinked;
use App\Support\AuditLog;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

class TeacherManager extends Component
{
    use RequiresPermission, SortsColumns, WithPagination;

    protected string $requiredPermission = 'teachers.manage';

    public string $search = '';

    /**
     * 「連結登入帳號」下拉選單的過濾字串。理由同 StudentManager：帳號
     * 會持續累積，一次列出全部到後來根本挑不到人。
     */
    public string $accountSearch = '';

    /** 帳號選單一次最多列出幾筆，超過請用搜尋縮小範圍。 */
    private const ACCOUNT_PICKER_LIMIT = 50;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search');
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '';
    }

    /**
     * 姓名這一欄畫面上顯示的是 displayName()——有連結帳號時優先用帳號的
     * 姓名，沒有才用 teachers.teacher_name（見 HasLinkableAccountName）。
     * 排序也必須照同一個規則，否則畫面上明明是照姓名排，卻會有幾列
     * 「看起來沒排到」——那些正是有連結帳號的老師。
     *
     * COALESCE 裡的欄位名是寫死的字串，$direction 只會是 'asc'／'desc'
     * （SortsColumns::activeSortDirection() 保證），沒有客戶端輸入被串
     * 進 SQL。
     *
     * @return array<string, string|\Closure>
     */
    protected function sortableColumns(): array
    {
        return [
            'name' => fn (Builder $query, string $direction) => $query
                ->orderByRaw("COALESCE(users.name, teachers.teacher_name) {$direction}"),
            'username' => 'users.username',
        ];
    }

    protected function defaultSortColumn(): string
    {
        return 'name';
    }

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

        AuditLog::admin('建立老師', [
            'teacher_id' => $teacher->id,
            'teacher_name' => $teacher->teacher_name,
            'linked_user_id' => $teacher->user_id,
        ], $teacher);

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

        AuditLog::admin('更新老師', [
            'teacher_id' => $teacher->id,
            'teacher_name' => $teacher->teacher_name,
            'linked_user_id' => $teacher->user_id,
        ], $teacher);

        $this->cancelEdit();

        session()->flash('status', "老師「{$teacher->teacher_name}」已更新。");
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingTeacherId', 'teacherName', 'userId']);
    }

    /**
     * 目前還在帶班（homeroomClasses 不是空集合）的老師不能刪——刪掉只是
     * 把 school_classes.homeroom_teacher_id 靜靜地 null 掉（migration
     * 設的是 nullOnDelete），畫面上會突然冒出「尚未指派」，管理者不見得
     * 記得回去處理。要刪的話請先到班級管理把導師改指派給別人或清空。
     */
    public function deleteTeacher(Teacher $teacher): void
    {
        if ($teacher->homeroomClasses()->exists()) {
            session()->flash('error', "老師「{$teacher->displayName()}」目前還是某個班級的導師，無法刪除，請先在班級管理改指派其他導師。");

            return;
        }

        AuditLog::admin('刪除老師', [
            'teacher_id' => $teacher->id,
            'teacher_name' => $teacher->displayName(),
            'linked_user_id' => $teacher->user_id,
        ]);

        $teacher->delete();

        session()->flash('status', "老師「{$teacher->displayName()}」已刪除。");
    }

    public function render()
    {
        // leftJoin 而不是 whereHas：姓名的排序與搜尋都要同時看得到
        // teachers.teacher_name 與 users.name（見 sortableColumns()），
        // 而且沒有連結帳號的老師不能因為 join 而消失，所以是 left join。
        // select('teachers.*') 不能省——不然 users 的 id/name 會蓋掉
        // Teacher model 自己的欄位。
        $teachers = Teacher::query()
            ->select('teachers.*')
            ->leftJoin('users', 'users.id', '=', 'teachers.user_id')
            ->with('user')
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.$this->search.'%';

                $query->where(fn (Builder $inner) => $inner
                    ->where('teachers.teacher_name', 'like', $term)
                    ->orWhere('users.name', 'like', $term)
                    ->orWhere('users.username', 'like', $term));
            });

        return view('livewire.admin.teacher-manager', [
            'teachers' => $this->applySort($teachers)->paginate(15),
            'availableUsers' => $this->accountsForPicker(),
        ]);
    }

    /**
     * 目前已選中的帳號一定要留在清單裡——不然使用者一開始過濾，正在
     * 編輯的那筆連結就會從 select 掉出去，看起來像被清空了，一送出就
     * 真的被清掉。
     *
     * @return Collection<int, User>
     */
    protected function accountsForPicker(): Collection
    {
        $accounts = User::availableForLinking(exceptTeacherId: $this->editingTeacherId)
            ->when($this->accountSearch !== '', function ($query) {
                $term = '%'.$this->accountSearch.'%';

                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('username', 'like', $term));
            })
            ->orderBy('name')
            ->limit(self::ACCOUNT_PICKER_LIMIT)
            ->get();

        if ($this->userId !== null && ! $accounts->contains('id', $this->userId)) {
            $selected = User::find($this->userId);

            if ($selected) {
                $accounts->prepend($selected);
            }
        }

        return $accounts;
    }
}
