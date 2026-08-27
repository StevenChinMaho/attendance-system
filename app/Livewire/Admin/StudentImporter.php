<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RequiresPermission;
use App\Livewire\Concerns\ScopesToSelectedAcademicPeriod;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\AuditLog;
use App\Support\ClassCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithFileUploads;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * 從學校匯出的全校 Excel 批量建立學生並排進班級。
 *
 * 兩階段（上傳 → 預覽 → 確認）而不是「選了檔案就直接寫進資料庫」：一次
 * 匯入可能新增數百筆學生、動到全校每個班級的名冊，殺傷力比單筆操作大
 * 得多，跟「標記已轉出」要先輸入日期再確認是同樣的取捨——先讓人看清楚
 * 每一列會發生什麼事，確認無誤才真的寫入。
 *
 * 兩個關鍵行為決定（使用者確認過的）：
 * 1. 學號已存在的列，一律不覆蓋既有學生的姓名/性別——只在他還沒加入
 *    目標班級時補上那筆班級連結。用舊檔案或打錯欄位時，最糟只是白做工，
 *    不會不知不覺把正確的既有資料洗掉。
 * 2. 只要有任何一列驗證失敗，整批都不寫入（不允許部分成功）——保證
 *    「確認匯入」的結果跟預覽表看到的完全一致，不會出現「我以為都過了
 *    其實只過一半」。
 */
class StudentImporter extends Component
{
    use RequiresPermission, ScopesToSelectedAcademicPeriod, WithFileUploads;

    protected string $requiredPermission = 'students.manage';

    public $file;

    /**
     * 解析＋驗證的結果快取。protected 而不是 public：public 屬性每次
     * 更新請求都是 client 可寫的（見 CLAUDE.md 對 Recorder::$statuses
     * 的說明），匯入的寫入集合絕對不能來自 client 送回來的陣列，一律
     * 每個請求從上傳的檔案重新解析。這個快取只是避免同一個請求裡
     * render() 跟 import() 各解析一次。
     *
     * @var Collection<int, array<string, mixed>>|null
     */
    protected ?Collection $rowsCache = null;

    protected function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ];
    }

    protected function messages(): array
    {
        return [
            'file.mimes' => '只接受 Excel（.xlsx／.xls）或 CSV 檔案。',
            'file.max' => '檔案大小不能超過 10MB。',
        ];
    }

    public function updatedFile(): void
    {
        $this->validate();

        // 換檔案時，上一份檔案的解析結果必須跟著失效。
        $this->rowsCache = null;
    }

    public function clearFile(): void
    {
        $this->reset('file');
        $this->rowsCache = null;
    }

    /**
     * 解析檔案並逐列判斷「這一列會發生什麼事」。回傳的每一列都帶著
     * outcome（create／attach／skip／error），預覽表跟實際寫入用的是
     * 完全同一份結果，不會有兩套判斷邏輯各自演化到不一致。
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function rows(): Collection
    {
        if ($this->rowsCache !== null) {
            return $this->rowsCache;
        }

        if (! $this->file) {
            return $this->rowsCache = collect();
        }

        // 直接用 PhpSpreadsheet 讀，而不是包一層 maatwebsite/excel——這裡
        // 只需要「把試算表讀成一列一列的陣列」，套件那套 import 生命週期
        // （ToModel／WithValidation／分塊佇列）全都用不到，因為畫面需要
        // 「先預覽整批結果、確認後才寫入」，跟它「讀到一列就寫一列」的
        // 流程對不起來。IOFactory::load() 也會自動辨識 xlsx／xls／csv。
        $sheet = IOFactory::load($this->file->getRealPath())->getActiveSheet();

        $parsed = collect($sheet->toArray(null, true, false, false))
            ->map(fn ($row, $index) => $this->normalizeRow((array) $row, $index))
            ->filter()
            ->values();

        // 目標範圍是 nav bar 目前選取的學年度／學期——跟新增班級鎖定成
        // 目前選取範圍是同一個理由（見 SchoolClassManager::createRules()），
        // 避免瀏覽某個學年度時手滑把整批學生匯到別的學年度去。
        $classes = SchoolClass::where('academic_year', $this->selectedAcademicYear)
            ->where('semester', $this->selectedSemester)
            ->get()
            ->keyBy(fn (SchoolClass $class) => "{$class->grade}-{$class->class_number}");

        $existingStudents = Student::whereIn('student_number', $parsed->pluck('student_number'))
            ->get()
            ->keyBy('student_number');

        // 這些班級目前已經被佔用的座號，以及每個學生已經連到哪些班級
        // ——一次查完，不要每一列各自查一次（全校檔案可能好幾百列）。
        $takenSeats = DB::table('school_class_student')
            ->whereIn('school_class_id', $classes->pluck('id'))
            ->get()
            ->groupBy('school_class_id');

        $seenStudentNumbers = [];
        $seenSeats = [];

        // 一般 closure + use (&...) 而不是箭頭函式：箭頭函式一律「以值」
        // 捕捉外部變數，$seenStudentNumbers/$seenSeats 的累積結果會在每一
        // 列之間被丟掉，「同一份檔案裡重複的學號/座號」就完全驗不出來
        // （寫過一次，被測試抓到）。
        return $this->rowsCache = $parsed->map(function (array $row) use (
            $classes,
            $existingStudents,
            $takenSeats,
            &$seenStudentNumbers,
            &$seenSeats
        ) {
            return $this->evaluateRow($row, $classes, $existingStudents, $takenSeats, $seenStudentNumbers, $seenSeats);
        });
    }

    /**
     * 把一列原始儲存格轉成有名字的欄位。學校匯出的格式欄位順序固定
     * （學號, 班級, 座號, 姓名, 性別, 身分證, 生日），身分證跟生日這個
     * 系統用不到，直接不解析。
     *
     * @param  array<int, mixed>  $row
     * @return array<string, mixed>|null null 代表這一列要整列跳過（標題列／空白列）
     */
    protected function normalizeRow(array $row, int $index): ?array
    {
        $cell = fn (int $i) => trim((string) ($row[$i] ?? ''));

        $studentNumber = $cell(0);

        // 標題列：直接認第一格的欄位名稱，不是靠「第一列一定是標題」猜
        // ——萬一某次匯出真的沒有標題列，第一位學生不該被無聲跳過。
        if (str_contains($studentNumber, '學號')) {
            return null;
        }

        // 完全空白的列（Excel 檔案結尾常有）不是錯誤，安靜跳過就好。
        if ($studentNumber === '' && $cell(1) === '' && $cell(3) === '') {
            return null;
        }

        return [
            // 給人看的行號：陣列從 0 開始，Excel 從 1 開始。
            'line' => $index + 1,
            'student_number' => $studentNumber,
            'class_code' => $cell(1),
            'seat_number' => $cell(2),
            'name' => $cell(3),
            'gender' => $cell(4),
        ];
    }

    /**
     * 判斷單一列的結果。錯誤一律回報「第一個」發現的問題就好，不用把
     * 一列所有毛病都列出來——使用者是拿去對照 Excel 逐列修，一次一個
     * 明確的原因比一串更好讀。
     *
     * @param  array<string, mixed>  $row
     * @param  Collection<string, SchoolClass>  $classes
     * @param  Collection<string, Student>  $existingStudents
     * @param  Collection<int, mixed>  $takenSeats
     * @param  array<string, int>  $seenStudentNumbers
     * @param  array<string, int>  $seenSeats
     * @return array<string, mixed>
     */
    protected function evaluateRow(
        array $row,
        Collection $classes,
        Collection $existingStudents,
        Collection $takenSeats,
        array &$seenStudentNumbers,
        array &$seenSeats,
    ): array {
        $fail = fn (string $reason) => $row + ['outcome' => 'error', 'reason' => $reason, 'class' => null, 'note' => null];

        foreach (['student_number' => '學號', 'class_code' => '班級', 'seat_number' => '座號', 'name' => '姓名'] as $field => $label) {
            if ($row[$field] === '') {
                return $fail("{$label}是空的。");
            }
        }

        if (! in_array($row['gender'], ['男', '女'], true)) {
            return $fail('性別必須是「男」或「女」。');
        }

        // 同一份檔案裡出現兩次同樣的學號，幾乎一定是複製貼上打錯，不該
        // 讓後面那筆靜靜地覆蓋/追加到前面那筆的判斷結果上。
        if (isset($seenStudentNumbers[$row['student_number']])) {
            return $fail("學號在這份檔案裡重複出現（第 {$seenStudentNumbers[$row['student_number']]} 列已經有一次）。");
        }
        $seenStudentNumbers[$row['student_number']] = $row['line'];

        $parsed = ClassCode::parse($row['class_code']);

        if (! $parsed) {
            return $fail('班級代號格式不對，應該是三碼數字（例如 101 代表 1年1班、211 代表 2年11班）。');
        }

        $class = $classes->get("{$parsed['grade']}-{$parsed['class_number']}");

        if (! $class) {
            return $fail("這個學年度／學期底下找不到 {$parsed['grade']}年{$parsed['class_number']}班，請先在班級管理建立這個班級。");
        }

        $seatKey = "{$class->id}-{$row['seat_number']}";

        if (isset($seenSeats[$seatKey])) {
            return $fail("同一個班級的座號在這份檔案裡重複出現（第 {$seenSeats[$seatKey]} 列已經有一次）。");
        }
        $seenSeats[$seatKey] = $row['line'];

        $student = $existingStudents->get($row['student_number']);

        $existingLink = $takenSeats->get($class->id, collect())
            ->firstWhere('student_id', $student?->id);

        // 這個班級已經有別的學生佔用這個座號了（不是這一列的學生自己）。
        $seatTakenByOther = $takenSeats->get($class->id, collect())
            ->first(fn ($link) => (string) $link->seat_number === $row['seat_number'] && $link->student_id !== $student?->id);

        if ($seatTakenByOther) {
            return $fail('這個班級裡已經有別的學生使用這個座號了。');
        }

        if (! $student) {
            return $row + ['outcome' => 'create', 'reason' => null, 'class' => $class, 'note' => null];
        }

        // 學號已存在——一律不覆蓋既有學生的姓名/性別（見類別開頭的說明），
        // 但如果檔案裡的姓名跟系統裡的不一樣，還是要講出來，不然使用者會
        // 以為匯入之後名字就會照檔案更新。
        $note = $student->displayName() !== $row['name']
            ? "系統目前登記的姓名是「{$student->displayName()}」，匯入不會覆蓋。"
            : null;

        if ($existingLink) {
            return $row + [
                'outcome' => 'skip',
                'reason' => null,
                'class' => $class,
                'note' => (string) $existingLink->seat_number !== $row['seat_number']
                    ? "已經在這個班級了（目前座號 {$existingLink->seat_number}），匯入不會改座號。"
                    : $note,
            ];
        }

        return $row + ['outcome' => 'attach', 'reason' => null, 'class' => $class, 'note' => $note];
    }

    /**
     * 只要有任何一列有錯就整批不寫入——不允許部分成功，見類別開頭的
     * 說明。這個檢查在 import() 裡會再做一次（不是只靠畫面把按鈕藏
     * 起來），因為 wire:click 的請求本來就可以被直接送出來。
     */
    public function import(): void
    {
        $rows = $this->rows();

        if ($rows->isEmpty()) {
            session()->flash('error', '這個檔案裡沒有可以匯入的資料。');

            return;
        }

        if ($rows->contains(fn (array $row) => $row['outcome'] === 'error')) {
            session()->flash('error', '還有列有問題，請先修正 Excel 檔案再重新上傳。');

            return;
        }

        $created = 0;
        $attached = 0;

        DB::transaction(function () use ($rows, &$created, &$attached) {
            foreach ($rows as $row) {
                if ($row['outcome'] === 'skip') {
                    continue;
                }

                if ($row['outcome'] === 'create') {
                    $student = Student::create([
                        'student_number' => $row['student_number'],
                        'name' => $row['name'],
                        'gender' => $row['gender'],
                    ]);

                    $created++;
                } else {
                    $student = Student::where('student_number', $row['student_number'])->firstOrFail();

                    $attached++;
                }

                $row['class']->students()->attach($student->id, ['seat_number' => $row['seat_number']]);
            }
        });

        // 匯入是一次影響全校資料的操作，逐列記錄只會把稽核紀錄淹掉，
        // 所以記一筆總結：檔名、各類別的筆數、涉及哪些班級。要追個別
        // 學生的話，班級名冊的加入紀錄本來就查得到。
        AuditLog::admin('批量匯入學生', [
            'file_name' => $this->file?->getClientOriginalName(),
            'created' => $created,
            'attached' => $attached,
            'skipped' => $rows->where('outcome', 'skip')->count(),
            'total_rows' => $rows->count(),
            'classes' => $rows->pluck('class')->filter()->unique('id')
                ->map(fn ($class) => $class->shortLabel())->values()->all(),
        ]);

        $this->clearFile();

        session()->flash('status', "匯入完成：新增 {$created} 位學生，另外把 {$attached} 位既有學生排進班級。");
    }

    public function render()
    {
        $rows = $this->rows();

        return view('livewire.admin.student-importer', [
            'rows' => $rows,
            'counts' => [
                'create' => $rows->where('outcome', 'create')->count(),
                'attach' => $rows->where('outcome', 'attach')->count(),
                'skip' => $rows->where('outcome', 'skip')->count(),
                'error' => $rows->where('outcome', 'error')->count(),
            ],
        ]);
    }
}
