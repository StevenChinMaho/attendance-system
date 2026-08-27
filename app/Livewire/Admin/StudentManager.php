<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RequiresPermission;
use App\Livewire\Concerns\ScopesToSelectedAcademicPeriod;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Rules\UserAccountIsUnlinked;
use App\Support\AuditLog;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * 全校學生管理——不再掛在某個班級底下（見 SchoolClass::students() 的
 * 說明：學生跟班級是多對多，一個學生不屬於單一班級）。這裡管的是學生
 * 本體（學號、姓名、性別、帳號連結、轉出/轉入狀態），跟 TeacherManager/
 * UserManager 同一種形狀，沒有列級的範圍限制——一旦有 students.manage
 * 權限就能管理全校任何學生，不是只能管「自己班」的。「這個學生現在
 * 在哪個班」改到 ClassRosterManager（班級名冊，加入/移除既有學生）
 * 管理，兩者是不同層次的動作：新增一個全新的人 vs 把既有的人排進某個
 * 班級。
 */
class StudentManager extends Component
{
    use RequiresPermission, ScopesToSelectedAcademicPeriod, WithPagination;

    protected string $requiredPermission = 'students.manage';

    /**
     * 全校學生動輒好幾百人，翻頁翻不到人——搜尋（學號／姓名／登入帳號）
     * 與三個下拉篩選（性別／目前班級／在讀狀態）是這一頁的主要導覽方式。
     */
    public string $search = '';

    public string $genderFilter = '';

    /**
     * 班級篩選的值：空字串＝全部，'none'＝目前這個學年度／學期沒有班級，
     * 其餘是 school_classes.id。'none' 這個特例是必要的——匯入完但還沒
     * 編班的學生正是最需要被找出來處理的一群，沒有這個選項就只能一頁一頁
     * 翻著找「未加入班級」。
     */
    public string $classFilter = '';

    public string $statusFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedGenderFilter(): void
    {
        $this->resetPage();
    }

    public function updatedClassFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'genderFilter', 'classFilter', 'statusFilter']);
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || $this->genderFilter !== ''
            || $this->classFilter !== ''
            || $this->statusFilter !== '';
    }

    /**
     * 切換學年度／學期時，原本選的班級可能根本不屬於新的期間，留著會
     * 變成「篩選條件看起來是空的，但表格一筆都沒有」。這個方法名稱跟
     * ScopesToSelectedAcademicPeriod 裡那個空的監聽器不同，Livewire 會
     * 把同一個事件的所有監聽方法都呼叫一次（見 CLAUDE.md）。
     */
    #[On('academic-period-changed')]
    public function resetClassFilterOnPeriodChange(): void
    {
        $this->reset('classFilter');
        $this->resetPage();
    }

    public string $studentNumber = '';

    public string $name = '';

    public string $gender = '';

    public ?int $userId = null;

    public bool $showCreateForm = false;

    public ?int $editingStudentId = null;

    /**
     * 標記已轉出要能手動輸入轉出日期（不是一律「現在按下去的這一刻」）
     * ——admin 很可能是事後才幫忙補標記，實際轉出日通常是過去某一天，
     * 而 Recorder/StatusBoard 排除已轉出學生的邏輯正是照這個日期判斷
     * 「補登哪些過去的日子還要讓他出現在名冊裡」，日期不準的話那個
     * 邊界就會跟著錯。轉入（恢復在讀）同理，也要能手動輸入日期，見
     * $restoringStudentId/$returnedDate。
     */
    public ?int $markingLeftStudentId = null;

    public string $leftDate = '';

    public ?int $restoringStudentId = null;

    public string $returnedDate = '';

    /**
     * 「直接幫這位學生開帳號」的面板。
     *
     * 原本的流程是「先到帳號管理建帳號 → 再回學生管理，從一個列出全校
     * 所有未連結帳號的下拉選單裡把他找出來」。但沒有人會憑空建一個學生
     * 帳號再去想它該給誰——真實的觸發點永遠是「這位學生要負責點名」，
     * 所以起點應該在學生這一列。這樣一來不用跨頁、不用在三百多人裡找到
     * 這位學生（你本來就是為了他才點下去的），也根本不需要那個選單。
     *
     * 同樣的模式在 SchoolClassManager 的「+ 新增老師」快捷面板已經有
     * 先例，這裡是把它補到缺的另一邊。
     */
    public ?int $creatingAccountStudentId = null;

    public string $newAccountUsername = '';

    public string $newAccountPassword = '';

    /**
     * 「連結既有帳號」下拉選單的搜尋字串。帳號會越積越多，一個列出
     * 全部未連結帳號的 select 到後來根本挑不到人。
     */
    public string $accountSearch = '';

    /**
     * 新開的學生帳號一律是這個身分。刻意寫死而不做成選項：這個面板的
     * 語意就是「幫這位學生開一個能點名的帳號」，需要別的身分時應該走
     * 帳號管理正規流程，而不是在學生列表上偷偷建出一個管理者。
     */
    private const STUDENT_ACCOUNT_ROLE = 'student_rep';

    /** 帳號選單一次最多列出幾筆，超過請用搜尋縮小範圍。 */
    private const ACCOUNT_PICKER_LIMIT = 50;

    public function startCreateAccount(Student $student): void
    {
        $this->showCreateForm = false;
        $this->cancelEdit();
        $this->cancelMarkAsLeft();
        $this->cancelRestore();

        $this->creatingAccountStudentId = $student->id;
        // 學號當預設帳號名稱：全校唯一、學生自己記得住，而且之後「帳號
        // 對得上哪位學生」一眼就看得出來，不必再靠姓名去猜。
        $this->newAccountUsername = $student->student_number;
        $this->newAccountPassword = '';
    }

    public function cancelCreateAccount(): void
    {
        $this->reset(['creatingAccountStudentId', 'newAccountUsername', 'newAccountPassword']);
    }

    public function createAccountForStudent(): void
    {
        $student = Student::with('user')->findOrFail($this->creatingAccountStudentId);

        // 這個方法是 wire:click 的目標，可以被直接呼叫；畫面上按鈕只在
        // 未連結時才顯示，但伺服器端仍要自己確認一次。
        if ($student->user_id !== null) {
            session()->flash('error', "學生「{$student->displayName()}」已經連結了帳號。");
            $this->cancelCreateAccount();

            return;
        }

        $this->validate([
            'newAccountUsername' => ['required', 'string', 'max:255', 'unique:users,username'],
            'newAccountPassword' => ['required', 'string', 'min:8'],
        ], [
            'newAccountUsername.unique' => '這個帳號名稱已經有人使用了。',
            'newAccountPassword.min' => '密碼至少要 8 個字元。',
        ]);

        $user = User::create([
            // 姓名直接沿用學生資料，不另外要求輸入——這正是
            // HasLinkableAccountName 想解決的重複輸入問題，只是方向相反。
            'name' => $student->displayName(),
            'username' => $this->newAccountUsername,
            'password' => Hash::make($this->newAccountPassword),
            'is_active' => true,
        ]);

        // 管理者代打的初始密碼，本人首次登入要換掉（同 UserManager）。
        $user->forceFill(['must_change_password' => true])->save();
        $user->assignRole(self::STUDENT_ACCOUNT_ROLE);

        $student->update(['user_id' => $user->id]);

        AuditLog::admin('建立帳號', [
            'user_id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'role' => self::STUDENT_ACCOUNT_ROLE,
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'via' => '學生管理的快速建立帳號',
        ], $user);

        $username = $user->username;
        $this->cancelCreateAccount();

        session()->flash('status', "已為學生「{$student->displayName()}」建立帳號 {$username} 並完成連結，請另外告知這組密碼；該帳號首次登入會被要求自行設定新密碼。");
    }

    /**
     * 一鍵連結「姓名完全相同」的既有帳號，用來清掉先前已經建好、但還
     * 沒連結上的那批孤兒帳號。
     *
     * 三個伺服器端檢查缺一不可——這個方法是 wire:click 的目標，參數
     * 完全由客戶端決定，不能因為畫面上只在同名時才顯示按鈕就信任它：
     * 帳號必須真的還沒被連結、學生必須還沒有帳號、而且兩邊姓名必須
     * 真的一樣（這個功能的定義就是「同名配對」，不是任意連結；要任意
     * 指定請走編輯表單）。
     */
    public function linkSuggestedAccount(Student $student, int $userId): void
    {
        if ($student->user_id !== null) {
            session()->flash('error', "學生「{$student->displayName()}」已經連結了帳號。");

            return;
        }

        $account = User::availableForLinking()->find($userId);

        if (! $account || $account->name !== $student->displayName()) {
            session()->flash('error', '這個帳號已經被連結，或姓名跟這位學生不一致，請改用「編輯」手動指定。');

            return;
        }

        $student->update(['user_id' => $account->id]);

        AuditLog::admin('連結學生帳號', [
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'student_name' => $student->displayName(),
            'user_id' => $account->id,
            'username' => $account->username,
            'via' => '同名建議',
        ], $student);

        session()->flash('status', "學生「{$student->displayName()}」已連結帳號 {$account->username}。");
    }

    /**
     * 有連結帳號時姓名不是必填——會直接沿用帳號的姓名（見
     * Student::resolveName()，跟 TeacherManager 同一套處理方式），這裡
     * 的 name 值根本不會被用到。只有沒連結帳號的學生才需要真的驗證有
     * 沒有打姓名。
     */
    protected function rules(): array
    {
        return [
            'studentNumber' => [
                'required', 'string', 'max:255',
                // 全校唯一，不是「這個班級裡面」唯一——同一個真實學生從入學
                // 到畢業自始至終只有一筆 students 資料。
                Rule::unique('students', 'student_number')->ignore($this->editingStudentId),
            ],
            'name' => [$this->userId ? 'nullable' : 'required', 'string', 'max:255'],
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
            'studentNumber.unique' => '這個學號已經有學生使用了。',
        ];
    }

    /**
     * 新增表單跟編輯表單共用同一組欄位屬性（$studentNumber/$name/
     * $gender/$userId）——如果兩個表單同時顯示，畫面上會看起來像互相
     * 同步（其實就是同一個屬性），送出新增時甚至可能帶著正在編輯那筆
     * 學生的學號一起送出去，撞到全校唯一限制直接噴 500。這裡確保兩者
     * 互斥：開新增表單前一定先把編輯狀態清乾淨。
     */
    public function toggleCreateForm(): void
    {
        if ($this->showCreateForm) {
            $this->showCreateForm = false;

            return;
        }

        $this->cancelEdit();
        $this->cancelMarkAsLeft();
        $this->cancelRestore();
        $this->cancelCreateAccount();
        $this->showCreateForm = true;
    }

    public function createStudent(): void
    {
        $this->validate();

        $student = Student::create([
            'student_number' => $this->studentNumber,
            'name' => Student::resolveName($this->userId, $this->name),
            'gender' => $this->gender,
            'user_id' => $this->userId,
        ]);

        AuditLog::admin('建立學生', [
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'student_name' => $student->name,
            'gender' => $student->gender,
            'linked_user_id' => $student->user_id,
        ], $student);

        $this->reset(['studentNumber', 'name', 'gender', 'userId', 'showCreateForm']);

        session()->flash('status', "學生「{$student->name}」建立成功，接下來到「班級管理」把他加進班級。");
    }

    public function startEdit(Student $student): void
    {
        // 理由跟 toggleCreateForm() 一樣：新增表單如果還開著，會跟編輯
        // 表單同時顯示、共用同一組欄位屬性。
        $this->showCreateForm = false;
        $this->cancelMarkAsLeft();
        $this->cancelRestore();
        $this->cancelCreateAccount();

        $this->editingStudentId = $student->id;
        $this->studentNumber = $student->student_number;
        $this->name = $student->name;
        $this->gender = $student->gender;
        $this->userId = $student->user_id;
    }

    public function updateStudent(): void
    {
        $this->validate();

        $student = Student::findOrFail($this->editingStudentId);
        $student->update([
            'student_number' => $this->studentNumber,
            'name' => Student::resolveName($this->userId, $this->name),
            'gender' => $this->gender,
            'user_id' => $this->userId,
        ]);

        AuditLog::admin('更新學生', [
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'student_name' => $student->name,
            'gender' => $student->gender,
            'linked_user_id' => $student->user_id,
        ], $student);

        $this->cancelEdit();

        session()->flash('status', "學生「{$student->name}」已更新。");
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingStudentId', 'studentNumber', 'name', 'gender', 'userId']);
    }

    /**
     * 開啟「標記已轉出」的日期輸入面板——不直接標記，因為轉出日期
     * 通常是過去某一天（admin 是事後補標記），不該一律等於「現在按下
     * 這個按鈕的當下」。預設帶入今天，符合最常見的「今天知道、今天
     * 標記」情境，但可以改成任何日期。
     */
    public function startMarkAsLeft(Student $student): void
    {
        $this->showCreateForm = false;
        $this->cancelEdit();
        $this->cancelRestore();
        $this->cancelCreateAccount();

        $this->markingLeftStudentId = $student->id;
        $this->leftDate = now()->toDateString();
    }

    /**
     * 如果這個學生目前已經有一筆還沒結束的轉出期間（正常操作流程不該
     * 發生，因為畫面上這時候按鈕會是「恢復在讀」），直接更新那筆的
     * 轉出日期，而不是再開一筆新的、留下兩筆同時「開放」的期間。
     */
    public function confirmMarkAsLeft(): void
    {
        $this->validate(['leftDate' => ['required', 'date']]);

        $student = Student::findOrFail($this->markingLeftStudentId);

        $openDeparture = $student->departures()->whereNull('returned_at')->first();

        if ($openDeparture) {
            $openDeparture->update(['left_at' => $this->leftDate]);
        } else {
            $student->departures()->create(['left_at' => $this->leftDate]);
        }

        AuditLog::admin('標記學生轉出', [
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'student_name' => $student->displayName(),
            'left_at' => $this->leftDate,
        ], $student);

        $this->cancelMarkAsLeft();

        session()->flash('status', "學生「{$student->displayName()}」已標記為 {$this->leftDate} 轉出。");
    }

    public function cancelMarkAsLeft(): void
    {
        $this->reset(['markingLeftStudentId', 'leftDate']);
    }

    /**
     * 開啟「恢復在讀」的日期輸入面板——理由跟 startMarkAsLeft() 對稱：
     * 轉入日同樣常常是過去某一天，不是操作當下，這個日期會決定
     * Student::isEnrolledOn() 判斷「這段轉出期間到哪一天為止」。
     */
    public function startRestore(Student $student): void
    {
        $this->showCreateForm = false;
        $this->cancelEdit();
        $this->cancelMarkAsLeft();
        $this->cancelCreateAccount();

        $this->restoringStudentId = $student->id;
        $this->returnedDate = now()->toDateString();
    }

    public function confirmRestore(): void
    {
        $student = Student::findOrFail($this->restoringStudentId);

        $openDeparture = $student->departures()->whereNull('returned_at')->first();

        // 正常操作流程不該發生（沒有開放期間時畫面上根本不會顯示「恢復
        // 在讀」按鈕），但直接呼叫這個方法時還是要擋住，不要在沒有轉出
        // 期間可以結束的情況下繼續往下驗證日期。
        if (! $openDeparture) {
            $this->cancelRestore();

            return;
        }

        $this->validate([
            'returnedDate' => ['required', 'date', 'after_or_equal:'.$openDeparture->left_at->toDateString()],
        ], [
            'returnedDate.after_or_equal' => '轉入日期不能早於轉出日期。',
        ]);

        $openDeparture->update(['returned_at' => $this->returnedDate]);

        AuditLog::admin('學生恢復在讀', [
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'student_name' => $student->displayName(),
            'left_at' => $openDeparture->left_at->toDateString(),
            'returned_at' => $this->returnedDate,
        ], $student);

        $this->cancelRestore();

        session()->flash('status', "學生「{$student->displayName()}」已標記為 {$this->returnedDate} 恢復在讀。");
    }

    public function cancelRestore(): void
    {
        $this->reset(['restoringStudentId', 'returnedDate']);
    }

    /**
     * 只有從來沒有點名紀錄的學生才能真的刪除——真的轉學的學生幾乎一定
     * 已經有點名紀錄，這種情況請用上面的 startMarkAsLeft() 標記已轉出，
     * 不是刪除。理由見 Student::hasAttendanceHistory()。
     */
    public function deleteStudent(Student $student): void
    {
        if ($student->hasAttendanceHistory()) {
            session()->flash('error', "學生「{$student->displayName()}」已經有點名紀錄，為保留歷史紀錄無法刪除，請改用「標記已轉出」。");

            return;
        }

        AuditLog::admin('刪除學生', [
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'student_name' => $student->displayName(),
        ]);

        $student->delete();

        session()->flash('status', "學生「{$student->displayName()}」已刪除。");
    }

    public function render()
    {
        return view('livewire.admin.student-manager', [
            // 「目前班級」欄只是給畫面上下文用的顯示，篩選到目前選取的
            // 學年度／學期——一個學生跨學年可能連到好幾筆 SchoolClass，
            // 這裡不需要全部列出來，只需要「現在」在哪個班。currentDeparture
            // 同理 eager load，狀態欄要用它判斷「在讀」/「已轉出」。
            // withCount 而不是每一列各自呼叫 hasAttendanceHistory()：後者
            // 對每個學生都會多發一次查詢，這裡一次查完。
            'students' => $this->filteredStudents()->orderBy('student_number')->paginate(15),
            'availableUsers' => $this->accountsForPicker(),
            'accountSuggestions' => $this->accountSuggestions(),
            // 班級篩選的選項只列出目前選取學年度／學期的班級——「目前班級」
            // 欄本來就只顯示這個範圍（見上面的 eager load），選單列出範圍外
            // 的班級只會篩出空結果。
            'filterableClasses' => SchoolClass::query()
                ->where('academic_year', $this->selectedAcademicYear)
                ->where('semester', $this->selectedSemester)
                ->orderByClassCode()
                ->get(),
        ]);
    }

    /**
     * 「連結登入帳號」下拉選單的內容。
     *
     * 帳號數量會持續累積，一次列出全部到後來根本挑不到人，所以支援關鍵字
     * 過濾並限制筆數。但**目前已選中的那個帳號一定要在清單裡**——不然
     * 使用者一開始搜尋，正在編輯的那筆連結就會從 select 掉出去，看起來
     * 像被清空了，一送出就真的被清掉。
     *
     * @return Collection<int, User>
     */
    protected function accountsForPicker(): Collection
    {
        $accounts = User::availableForLinking(exceptStudentId: $this->editingStudentId)
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

    /**
     * 「姓名完全相同」的建議連結：未連結的學生 => 唯一一個同名的未連結帳號。
     *
     * **只在恰好有一個同名候選時才提供建議。** 三百多人的學校幾乎必然
     * 有同名同姓，多個候選時自動挑一個就會出現「A 的帳號連到 B 身上」
     * 這種很難察覺又很難查的錯——那種情況留給編輯表單手動指定。
     *
     * @return Collection<string, User>
     */
    protected function accountSuggestions(): Collection
    {
        return User::availableForLinking()
            ->orderBy('name')
            ->get()
            ->groupBy('name')
            ->filter(fn ($sameName) => $sameName->count() === 1)
            ->map(fn ($sameName) => $sameName->first());
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Student>
     */
    protected function filteredStudents(): \Illuminate\Database\Eloquent\Builder
    {
        return Student::with(['user', 'currentDeparture'])
            ->with(['schoolClasses' => fn ($query) => $query
                ->where('academic_year', $this->selectedAcademicYear)
                ->where('semester', $this->selectedSemester)
                ->orderByClassCode()])
            ->withCount('attendanceRecords')
            ->when($this->search !== '', function (Builder $query) {
                // 括號包住整組 OR，否則會跟後面的性別／班級／狀態條件
                // 攤平成同一層，篩選等於失效。
                $term = '%'.$this->search.'%';

                $query->where(fn (Builder $inner) => $inner
                    ->where('student_number', 'like', $term)
                    ->orWhere('name', 'like', $term)
                    // 姓名欄顯示的是 displayName()，有連結帳號時優先用
                    // 帳號的姓名，所以連 users.name 一起找；username 是
                    // 使用者明確要求的搜尋條件之一。
                    ->orWhereHas('user', fn (Builder $user) => $user
                        ->where('name', 'like', $term)
                        ->orWhere('username', 'like', $term)));
            })
            ->when(
                in_array($this->genderFilter, ['男', '女'], true),
                fn (Builder $query) => $query->where('gender', $this->genderFilter),
            )
            ->when($this->classFilter === 'none', fn (Builder $query) => $query
                ->whereDoesntHave('schoolClasses', fn (Builder $class) => $class
                    ->where('academic_year', $this->selectedAcademicYear)
                    ->where('semester', $this->selectedSemester)))
            ->when(ctype_digit($this->classFilter), fn (Builder $query) => $query
                ->whereHas('schoolClasses', fn (Builder $class) => $class
                    ->where('school_classes.id', (int) $this->classFilter)))
            // 「已轉出」＝目前有一段還沒結束的轉出期間，跟
            // Student::currentDeparture() 用的是同一個條件。
            ->when($this->statusFilter === 'enrolled', fn (Builder $query) => $query
                ->whereDoesntHave('departures', fn (Builder $departure) => $departure->whereNull('returned_at')))
            ->when($this->statusFilter === 'left', fn (Builder $query) => $query
                ->whereHas('departures', fn (Builder $departure) => $departure->whereNull('returned_at')));
    }
}
