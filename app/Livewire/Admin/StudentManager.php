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

    public function mount(SchoolClass $schoolClass): void
    {
        $this->schoolClass = $schoolClass;
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
        $this->cancelMarkAsLeft();
        $this->cancelRestore();
        $this->showCreateForm = true;
    }

    public function createStudent(): void
    {
        $this->validate();

        $student = $this->schoolClass->students()->create([
            'student_number' => $this->studentNumber,
            'seat_number' => $this->seatNumber,
            'name' => Student::resolveName($this->userId, $this->name),
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
        $this->cancelMarkAsLeft();
        $this->cancelRestore();

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
            'name' => Student::resolveName($this->userId, $this->name),
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

        $this->markingLeftStudentId = $student->id;
        $this->leftDate = now()->toDateString();
    }

    /**
     * 用 $this->schoolClass->students()->findOrFail() 而不是直接信任
     * 傳進來的 $student，理由跟 updateStudent() 一樣：即使 Livewire
     * 的隱含 model binding 解析出來的是真實存在的學生，也要再次確認
     * 這筆資料確實屬於目前這個班級，不是別班的學生 ID。
     *
     * 如果這個學生目前已經有一筆還沒結束的轉出期間（正常操作流程不該
     * 發生，因為畫面上這時候按鈕會是「恢復在讀」），直接更新那筆的
     * 轉出日期，而不是再開一筆新的、留下兩筆同時「開放」的期間。
     */
    public function confirmMarkAsLeft(): void
    {
        $this->validate(['leftDate' => ['required', 'date']]);

        $student = $this->schoolClass->students()->findOrFail($this->markingLeftStudentId);

        $openDeparture = $student->departures()->whereNull('returned_at')->first();

        if ($openDeparture) {
            $openDeparture->update(['left_at' => $this->leftDate]);
        } else {
            $student->departures()->create(['left_at' => $this->leftDate]);
        }

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

        $this->restoringStudentId = $student->id;
        $this->returnedDate = now()->toDateString();
    }

    public function confirmRestore(): void
    {
        $student = $this->schoolClass->students()->findOrFail($this->restoringStudentId);

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
        $student = $this->schoolClass->students()->findOrFail($student->id);

        if ($student->hasAttendanceHistory()) {
            session()->flash('error', "學生「{$student->displayName()}」已經有點名紀錄，為保留歷史紀錄無法刪除，請改用「標記已轉出」。");

            return;
        }

        $student->delete();

        session()->flash('status', "學生「{$student->displayName()}」已刪除。");
    }

    public function render()
    {
        return view('livewire.admin.student-manager', [
            // withCount 而不是每一列各自呼叫 hasAttendanceHistory()：
            // 後者對這個班級的每個學生都會多發一次查詢，一個班二三十個
            // 學生就是二三十次額外查詢，withCount 一次查完。currentDeparture
            // 同理 eager load，狀態欄要用它判斷「在讀」/「已轉出」。
            'students' => $this->schoolClass->students()
                ->with(['user', 'currentDeparture'])
                ->withCount('attendanceRecords')
                ->orderBySeatNumber()
                ->get(),
            'availableUsers' => User::availableForLinking(exceptStudentId: $this->editingStudentId)
                ->orderBy('name')
                ->get(),
        ]);
    }
}
