<?php

namespace App\Livewire\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Recorder extends Component
{
    /**
     * 目前只開放這三個時段，對應甲方「一天三次」的實際需求。故意不做成
     * 資料庫層 enum，之後若要擴充成逐節點名（1~8節）只需要改這裡跟
     * migration 註解說的驗證規則，不需要動資料表結構。
     */
    public const PERIODS = [
        'MORNING' => '早上',
        'NOON' => '中午',
        'AFTERNOON' => '下午',
    ];

    public SchoolClass $schoolClass;

    public string $date = '';

    public string $period = '';

    /** @var array<int, string> student_id => AttendanceStatus value */
    public array $statuses = [];

    public bool $hasExistingSession = false;

    /**
     * 同一個請求生命週期內重複用到的班級學生名單快取，避免 loadSession()
     * 跟 render() 各自查一次一模一樣的資料。非 public，不會被 Livewire
     * 同步／序列化，純粹是單次請求內的記憶。
     */
    protected ?Collection $studentsCache = null;

    /**
     * boot() 在 Livewire 的每一次請求（初次 mount 跟之後每一次 wire:click
     * 等互動的 hydrate）都會重跑，不像路由的 can: middleware 只在整頁
     * 載入那一刻檢查一次。這裡刻意不只依賴路由層的
     * can:recordAttendance,schoolClass——那個檢查透過 Livewire 內部一份
     * 寫死的 middleware allowlist 延續到後續互動請求，屬於未公開文件化
     * 的實作細節，也完全不會被 Livewire::test() 測試到（Livewire 自己在
     * PersistentMiddleware 的原始碼裡明講「不對測試套用」）。boot() 這裡
     * 給的是明確、每次都重查、也真的測得到的第二層保障。
     *
     * isset 檢查是必要的：初次 mount 時 boot() 會在 mount(SchoolClass) 把
     * $schoolClass 賦值「之前」先執行，此時屬性還沒初始化，直接檢查會
     * 對 typed property 拋例外；那個當下的授權已經由路由 middleware
     * 負責，不需要這裡重複做。
     */
    public function boot(): void
    {
        if (! isset($this->schoolClass)) {
            return;
        }

        $this->authorize('recordAttendance', $this->schoolClass);
    }

    public function mount(SchoolClass $schoolClass): void
    {
        $this->schoolClass = $schoolClass;
        $this->date = now()->toDateString();
        $this->period = $this->defaultPeriod();

        $this->loadSession();
    }

    public function updatedDate(): void
    {
        $this->loadSession();
    }

    public function updatedPeriod(): void
    {
        $this->loadSession();
    }

    /**
     * 「一鍵全到」：把目前畫面上每個學生都設成出席。這不會直接寫資料庫，
     * 使用者還是要按「送出點名單」才算完成，跟系統設計要點裡「未確實
     * 送出」的防呆精神一致。
     */
    public function markAllPresent(): void
    {
        $this->statuses = array_fill_keys(array_keys($this->statuses), AttendanceStatus::Present->value);
    }

    public function submit(): void
    {
        $this->validate([
            'date' => ['required', 'date'],
            'period' => ['required', Rule::in(array_keys(self::PERIODS))],
            'statuses.*' => [Rule::enum(AttendanceStatus::class)],
        ]);

        // firstOrCreate 而非每次都建立新 session：同一天同一時段重新
        // 進來點名（例如遲到學生後來到了要更新狀態）要編輯同一筆，
        // 不能一直生出新的 session。
        $session = $this->schoolClass->attendanceSessions()->firstOrCreate(
            ['date' => $this->date, 'period' => $this->period],
            ['recorded_by' => auth()->id()],
        );

        $now = now();

        // 從伺服器端查出來的班級名單（$this->students()）出發，而不是直接
        // 信任 $this->statuses 的 key——$statuses 是 wire:model 綁定的
        // public 屬性，client 端的更新請求可以附加任意 key，如果直接
        // foreach 它，惡意請求就能塞一個不屬於這個班級的 student_id 進來，
        // 繞過 SchoolClassPolicy 想擋的「只能動自己班」範圍限制。
        $rows = $this->students()->map(fn ($student) => [
            'attendance_session_id' => $session->id,
            'student_id' => $student->id,
            'status' => $this->statuses[$student->id] ?? AttendanceStatus::Present->value,
            'updated_by' => auth()->id(),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        // upsert 一次寫完全班，而不是每個學生各自 SELECT+INSERT/UPDATE
        // 一次——一個班 30 人的話差異是 1 次查詢 vs 最多 60 次。衝突鍵是
        // migration 裡的 (attendance_session_id, student_id) 唯一索引，
        // 只更新 status/updated_by/updated_at，created_at 維持原值不動。
        AttendanceRecord::upsert(
            $rows,
            ['attendance_session_id', 'student_id'],
            ['status', 'updated_by', 'updated_at'],
        );

        $this->hasExistingSession = true;

        session()->flash('status', '點名單已送出。');
    }

    protected function defaultPeriod(): string
    {
        return match (true) {
            now()->hour < 11 => 'MORNING',
            now()->hour < 15 => 'NOON',
            default => 'AFTERNOON',
        };
    }

    protected function loadSession(): void
    {
        $session = $this->schoolClass->attendanceSessions()
            ->where('date', $this->date)
            ->where('period', $this->period)
            ->with('records')
            ->first();

        $this->hasExistingSession = $session !== null;

        $existingStatuses = $session
            ? $session->records->mapWithKeys(fn ($record) => [$record->student_id => $record->status->value])
            : collect();

        $this->statuses = $this->students()
            ->mapWithKeys(fn ($student) => [
                $student->id => $existingStatuses->get($student->id, AttendanceStatus::Present->value),
            ])
            ->all();
    }

    protected function students(): Collection
    {
        return $this->studentsCache ??= $this->schoolClass->students()->orderBySeatNumber()->get();
    }

    public function render()
    {
        return view('livewire.attendance.recorder', [
            'students' => $this->students(),
            'statusOptions' => AttendanceStatus::cases(),
        ]);
    }
}
