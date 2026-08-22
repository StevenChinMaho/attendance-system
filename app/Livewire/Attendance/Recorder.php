<?php

namespace App\Livewire\Attendance;

use App\Enums\AttendanceStatus;
use App\Livewire\Concerns\AttendancePeriods;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Recorder extends Component
{
    public SchoolClass $schoolClass;

    public string $date = '';

    public string $period = '';

    /** @var array<int, string> student_id => AttendanceStatus value */
    public array $statuses = [];

    /**
     * 目前日期/時段對應的 attendance_sessions.id，null 代表這個時段
     * 還沒點過名。「處理情形」功能需要真正的 attendance_record_id
     * 才能掛上去，所以這裡保留完整的 id，不只是一個布林值。
     */
    public ?int $currentSessionId = null;

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
        $this->period = AttendancePeriods::current();

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
            'period' => ['required', Rule::in(array_keys(AttendancePeriods::PERIODS))],
            'statuses.*' => [Rule::enum(AttendanceStatus::class)],
        ]);

        // 整段包進 transaction：upsert 跟稽核紀錄要嘛一起成功要嘛一起
        // 失敗，不能發生「狀態改了但稽核紀錄寫失敗」這種半套結果，也
        // 避免兩個人（例如副班長跟導師）幾乎同時送出同一個 session 時，
        // 各自根據送出前一刻的舊狀態算出不一致的「變動前」稽核值。
        // lockForUpdate() 讓後到的請求排隊等前一個 transaction 真正
        // commit 完，讀到的才是「送出當下」真正的最新狀態。
        DB::transaction(function () {
            // firstOrCreate 而非每次都建立新 session：同一天同一時段重新
            // 進來點名（例如遲到學生後來到了要更新狀態）要編輯同一筆，
            // 不能一直生出新的 session。
            $session = $this->schoolClass->attendanceSessions()->firstOrCreate(
                ['date' => $this->date, 'period' => $this->period],
                ['recorded_by' => auth()->id()],
            );

            // 稽核用：寫入前先查出目前的狀態，upsert 完再跟新值比對。不能
            // 靠 AttendanceRecord 的 LogsActivity 模型事件自動記錄，因為
            // upsert() 是批次寫入，不會觸發 Eloquent 的 saving/saved 事件。
            $previousStatuses = $session->records()->lockForUpdate()->get()->keyBy('student_id')
                ->map(fn (AttendanceRecord $record) => $record->status);

            $now = now();

            // 從伺服器端查出來的班級名單（$this->students()）出發，而不是
            // 直接信任 $this->statuses 的 key——$statuses 是 wire:model
            // 綁定的 public 屬性，client 端的更新請求可以附加任意 key，
            // 如果直接 foreach 它，惡意請求就能塞一個不屬於這個班級的
            // student_id 進來，繞過 SchoolClassPolicy 想擋的「只能動自己
            // 班」範圍限制。
            $rows = $this->students()->map(fn ($student) => [
                'attendance_session_id' => $session->id,
                'student_id' => $student->id,
                'status' => $this->statuses[$student->id] ?? AttendanceStatus::Present->value,
                'updated_by' => auth()->id(),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            // upsert 一次寫完全班，而不是每個學生各自 SELECT+INSERT/UPDATE
            // 一次——一個班 30 人的話差異是 1 次查詢 vs 最多 60 次。衝突鍵
            // 是 migration 裡的 (attendance_session_id, student_id) 唯一
            // 索引，只更新 status/updated_by/updated_at，created_at 維持
            // 原值不動。
            AttendanceRecord::upsert(
                $rows,
                ['attendance_session_id', 'student_id'],
                ['status', 'updated_by', 'updated_at'],
            );

            $this->logStatusChanges($session, $previousStatuses);

            $this->currentSessionId = $session->id;
        });

        session()->flash('status', '點名單已送出。');
    }

    /**
     * 只記錄「有意義」的變動：真的變了才記，全班第一次點名預設的
     * 「出席」不記（那是例行狀態，不是需要留意的例外），但只要是
     * 非出席狀態被記下、或任何狀態被改動，都留一筆稽核紀錄。
     */
    protected function logStatusChanges(AttendanceSession $session, SupportCollection $previousStatuses): void
    {
        foreach ($this->students() as $student) {
            $newStatus = AttendanceStatus::from($this->statuses[$student->id] ?? AttendanceStatus::Present->value);
            $oldStatus = $previousStatuses->get($student->id);

            if ($oldStatus?->value === $newStatus->value) {
                continue;
            }

            if ($oldStatus === null && $newStatus === AttendanceStatus::Present) {
                continue;
            }

            activity('attendance_record')
                ->causedBy(auth()->user())
                ->withProperties([
                    'school_class_id' => $this->schoolClass->id,
                    'attendance_session_id' => $session->id,
                    'student_id' => $student->id,
                    'old' => $oldStatus?->value,
                    'new' => $newStatus->value,
                ])
                ->log($oldStatus === null ? '出席狀態建立' : '出席狀態變更');
        }
    }

    protected function loadSession(): void
    {
        $session = $this->schoolClass->attendanceSessions()
            ->where('date', $this->date)
            ->where('period', $this->period)
            ->with('records')
            ->first();

        $this->currentSessionId = $session?->id;

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

    /**
     * 目前這個時段各學生對應的 AttendanceRecord，鍵是 student_id——
     * 「處理情形」元件需要真正的 record id 才能掛上去，$statuses 裡
     * 存的只是還沒送出的即時值，不能拿來用。
     */
    protected function currentSessionRecords(): Collection
    {
        if (! $this->currentSessionId) {
            return new Collection;
        }

        // 預先讀取 attendanceSession（AttendanceRecordPolicy::manageFollowUp
        // 每一筆非管理者的請求都要查）跟 followUps（畫面上要判斷「這筆有
        // 沒有歷史處理情形」），避免非管理者（例如導師）每一列都各自
        // lazy-load 一次。
        return AttendanceRecord::where('attendance_session_id', $this->currentSessionId)
            ->with(['attendanceSession', 'followUps'])
            ->get()
            ->keyBy('student_id');
    }

    public function render()
    {
        return view('livewire.attendance.recorder', [
            'students' => $this->students(),
            'statusOptions' => AttendanceStatus::cases(),
            'sessionRecords' => $this->currentSessionRecords(),
        ]);
    }
}
