<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RequiresPermission;
use App\Livewire\Concerns\ScopesToSelectedAcademicPeriod;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\User;
use App\Rules\UserAccountIsUnlinked;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class SchoolClassManager extends Component
{
    use RequiresPermission, ScopesToSelectedAcademicPeriod, WithPagination;

    protected string $requiredPermission = 'classes.manage';

    public string $academicYear = '';

    public string $semester = '';

    public string $grade = '';

    public string $classNumber = '';

    public ?int $homeroomTeacherId = null;

    public bool $showCreateForm = false;

    public ?int $editingClassId = null;

    /**
     * 「指派導師」以前一定要先跳到「教師管理」把老師建好，才能回這裡
     * 選——第一次設定新班級的導師時，兩個頁面來回切換很容易讓人搞不
     * 清楚「班級管理」跟「教師管理」的關係。這裡讓指派導師的下拉選單
     * 旁邊多一個「新增老師」的小面板，建立完直接自動選進 $homeroomTeacherId
     * （不管目前開著的是新增班級表單還是編輯班級表單，兩者共用同一個
     * 屬性），不用真的離開這一頁。
     */
    public bool $showQuickAddTeacher = false;

    public string $newTeacherName = '';

    public ?int $newTeacherUserId = null;

    protected function quickAddTeacherRules(): array
    {
        return [
            'newTeacherName' => [$this->newTeacherUserId ? 'nullable' : 'required', 'string', 'max:255'],
            'newTeacherUserId' => [
                'nullable', 'exists:users,id',
                new UserAccountIsUnlinked,
            ],
        ];
    }

    public function toggleQuickAddTeacher(): void
    {
        $this->showQuickAddTeacher = ! $this->showQuickAddTeacher;

        if ($this->showQuickAddTeacher) {
            $this->reset(['newTeacherName', 'newTeacherUserId']);
        }
    }

    public function quickAddTeacher(): void
    {
        $this->validate($this->quickAddTeacherRules());

        $teacher = Teacher::create([
            'teacher_name' => Teacher::resolveName($this->newTeacherUserId, $this->newTeacherName),
            'user_id' => $this->newTeacherUserId,
        ]);

        $this->homeroomTeacherId = $teacher->id;
        $this->showQuickAddTeacher = false;
        $this->reset(['newTeacherName', 'newTeacherUserId']);

        session()->flash('status', "老師「{$teacher->teacher_name}」建立成功，已自動選為導師。");
    }

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

    /**
     * 新增表單跟編輯表單共用同一組欄位屬性（$grade/$classNumber/
     * $homeroomTeacherId）——如果兩個表單同時顯示，畫面上會看起來像
     * 互相同步（其實就是同一個屬性），送出新增時甚至可能帶著正在編輯
     * 那個班級的年級／代號一起送出去，撞到同學年度學期的 unique 限制
     * 直接噴 500。這裡確保兩者互斥：開新增表單前一定先把編輯狀態清
     * 乾淨。
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

        session()->flash('status', "班級「{$class->shortLabel()}」建立成功。");
    }

    public function startEdit(SchoolClass $schoolClass): void
    {
        // 理由跟 toggleCreateForm() 一樣：新增表單如果還開著，會跟編輯
        // 表單同時顯示、共用同一組欄位屬性。
        $this->showCreateForm = false;

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

        session()->flash('status', "班級「{$class->shortLabel()}」已更新。");
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingClassId', 'academicYear', 'semester', 'grade', 'classNumber', 'homeroomTeacherId']);
    }

    /**
     * 只有完全沒有學生、也從來沒有點過名的班級才能刪——
     * students.school_class_id／attendance_sessions.school_class_id 都是
     * cascadeOnDelete，真的執行下去會連同這個班級的學生名冊、點名紀錄、
     * 處理情形一起整串刪光，對一個已經在用的班級來說是毀滅性的操作。
     * 這個限制只用來清掉「建錯的空班級」，不是用來清掉舊學年度的班級
     * ——舊班級即使已經沒有在點名，學生名冊跟歷史紀錄還是要保留。
     */
    public function deleteClass(SchoolClass $schoolClass): void
    {
        if ($schoolClass->students()->exists() || $schoolClass->attendanceSessions()->exists()) {
            session()->flash('error', "班級「{$schoolClass->shortLabel()}」已經有學生或點名紀錄，為避免資料一併被刪除，無法刪除這個班級。");

            return;
        }

        $schoolClass->delete();

        session()->flash('status', "班級「{$schoolClass->shortLabel()}」已刪除。");
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
            // homeroomTeacher.user 一併 eager load：Teacher::displayName()
            // 有連結帳號時會讀 $this->user->name，沒有 eager load 的話
            // 每一列班級都會各自多發一次查詢。
            'classes' => SchoolClass::with(['homeroomTeacher.user', 'students'])
                ->where('academic_year', $this->selectedAcademicYear)
                ->where('semester', $this->selectedSemester)
                ->orderBy('grade')
                ->orderByClassNumber()
                ->paginate(15),
            'teachers' => Teacher::with('user')->orderBy('teacher_name')->get(),
            'availableUsersForTeacher' => User::availableForLinking()->orderBy('name')->get(),
        ]);
    }
}
