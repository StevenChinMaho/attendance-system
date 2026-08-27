<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RequiresPermission;
use App\Models\User;
use App\Support\AuditLogPresenter;
use Illuminate\Contracts\Database\Query\Builder;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

/**
 * 稽核紀錄查閱（/admin/audit）。
 *
 * 唯讀，沒有任何寫入動作——稽核紀錄一旦寫下就不該被畫面改動或刪除，
 * 否則它作為證據的價值就沒了。要清理舊資料請走 spatie 自己的
 * `activitylog:clean` 指令，那是有意識的維運動作，不是一個按鈕。
 *
 * 範圍是全校、不分班級：權限（audit.view）本身就是門檻，拿得到這個
 * 權限的人就看得到全部。刻意不做「導師只看得到自己班」那一層——那需要
 * 從 properties 的 JSON 裡反推班級再比對 ownSchoolClasses()，成本與
 * 誤判風險都不低，而實際需求是「特定幾個人要能查」，用權限開關就夠了。
 */
class AuditLogViewer extends Component
{
    use RequiresPermission, WithPagination;

    protected string $requiredPermission = 'audit.view';

    /** 對應 properties 內容與描述的關鍵字搜尋。 */
    public string $search = '';

    /** activity_log.log_name，空字串代表全部。 */
    public string $categoryFilter = '';

    /** causer 的 users.id，空字串代表全部。 */
    public string $causerFilter = '';

    public string $fromDate = '';

    public string $toDate = '';

    /** 目前展開明細的那一列（一次只展開一列，避免整頁變成一大片 JSON）。 */
    public ?int $expandedId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCauserFilter(): void
    {
        $this->resetPage();
    }

    public function updatedFromDate(): void
    {
        $this->resetPage();
    }

    public function updatedToDate(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'categoryFilter', 'causerFilter', 'fromDate', 'toDate', 'expandedId']);
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || $this->categoryFilter !== ''
            || $this->causerFilter !== ''
            || $this->fromDate !== ''
            || $this->toDate !== '';
    }

    public function toggleDetails(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function render()
    {
        $activities = Activity::with('causer')
            ->when(
                array_key_exists($this->categoryFilter, AuditLogPresenter::CATEGORIES),
                fn (Builder $query) => $query->where('log_name', $this->categoryFilter),
            )
            ->when(
                ctype_digit($this->causerFilter),
                fn (Builder $query) => $query->where('causer_id', (int) $this->causerFilter)
                    ->where('causer_type', (new User)->getMorphClass()),
            )
            // whereDate 而不是 >=／<=：使用者輸入的是日期，不含時間，
            // 直接拿去比 timestamp 的話「到 8/27」會把 8/27 當天全部
            // 排除掉（因為 '2026-08-27' 會被當成 00:00:00）。
            ->when($this->fromDate !== '', fn (Builder $query) => $query->whereDate('created_at', '>=', $this->fromDate))
            ->when($this->toDate !== '', fn (Builder $query) => $query->whereDate('created_at', '<=', $this->toDate))
            ->when($this->search !== '', function (Builder $query) {
                // 括號包住整組 OR，否則會跟上面的篩選攤平成同一層。
                $term = '%'.$this->search.'%';

                $query->where(fn (Builder $inner) => $inner
                    ->where('description', 'like', $term)
                    // properties 一定要用 JSON_SEARCH，不能直接 LIKE。
                    //
                    // 資料庫裡存的是 Laravel json_encode 的結果，中文會被
                    // 逃逸成 \uXXXX——實測「3年1班」存進去是
                    // {"school_class":"3年1班"}，所以
                    // `LIKE '%3年1班%'` 一筆都比對不到，而且是安靜地回傳
                    // 空結果，看起來就像「沒有這筆紀錄」。這是這個系統最
                    // 常見的搜尋內容（班級、姓名），等於整個搜尋功能對中文
                    // 失效。JSON_SEARCH 是對解析後的 JSON 值比對，中文與
                    // ASCII 都正確（已實測，含「不該中的」反向案例）。
                    //
                    // 代價：沒有索引可用，資料量大時是全表掃描。實務上這個
                    // 搜尋會搭配日期範圍一起用（先縮到某幾天再找人名），
                    // 可以接受；真的變慢時的方向是把常查的欄位拉成正規
                    // 欄位，而不是想辦法替 JSON 加索引。
                    ->orWhereRaw('JSON_SEARCH(properties, ?, ?) IS NOT NULL', ['one', $term]));
            })
            // 最新的在最上面：查稽核紀錄幾乎都是從「剛剛發生了什麼」開始。
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.admin.audit-log-viewer', [
            'activities' => $activities,
            'categories' => AuditLogPresenter::CATEGORIES,
            // 只列出實際留下過紀錄的操作者，而不是全部帳號——三百多個
            // 帳號裡絕大多數不會出現在稽核紀錄裡，全列出來反而難挑。
            'causers' => User::query()
                ->whereIn('id', Activity::query()
                    ->whereNotNull('causer_id')
                    ->where('causer_type', (new User)->getMorphClass())
                    ->distinct()
                    ->pluck('causer_id'))
                ->orderBy('name')
                ->get(),
        ]);
    }
}
