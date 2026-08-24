<div class="mx-auto max-w-5xl px-4 py-10">
    <a href="{{ route('admin.students') }}" class="text-xs text-slate-500 underline hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">← 回學生管理</a>

    <div class="mt-2">
        <h1 class="page-title">批量匯入學生</h1>
        <p class="page-subtitle mt-1">
            匯入範圍：
            <span @unless (\App\Support\AcademicPeriod::isSelectedCurrent()) class="font-medium text-amber-600 dark:text-amber-400" @endunless>
                {{ \App\Support\AcademicPeriod::label($selectedAcademicYear, $selectedSemester) }}
                @unless (\App\Support\AcademicPeriod::isSelectedCurrent())
                    （非本學期）
                @endunless
            </span>
            ，檔案裡的班級代號會對應到這個學年度／學期底下的班級。要匯到別的學年度請先用上方導覽列切換。
        </p>
    </div>

    @if (session('status'))
        <div class="alert-success mt-4">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="alert-error mt-4">
            {{ session('error') }}
        </div>
    @endif

    <div class="surface mt-6 space-y-4 p-6">
        <div>
            <label class="field-label">Excel 檔案</label>
            <input type="file" wire:model="file" accept=".xlsx,.xls,.csv" class="field-input">
            @error('file') <p class="field-error">{{ $message }}</p> @enderror
            <p class="field-hint">
                直接使用學校匯出的格式即可，欄位順序：學號、班級、座號、姓名、性別（後面的身分證、生日欄位不會被讀取）。
                班級代號是三碼數字，例如 101 代表 1年1班、211 代表 2年11班。
            </p>
        </div>

        <div wire:loading wire:target="file" class="text-sm text-slate-500 dark:text-slate-400">
            檔案讀取中⋯
        </div>
    </div>

    @if ($file && $rows->isNotEmpty())
        <div class="mt-6 flex flex-wrap items-center gap-3">
            <span class="badge-success">將新增 {{ $counts['create'] }} 位</span>
            <span class="badge-neutral">既有學生排進班級 {{ $counts['attach'] }} 位</span>
            <span class="badge-neutral">已在班級、不需變動 {{ $counts['skip'] }} 位</span>
            @if ($counts['error'] > 0)
                <span class="badge-danger">有問題 {{ $counts['error'] }} 列</span>
            @endif
        </div>

        @if ($counts['error'] > 0)
            {{-- 只要有任何一列有問題就整批不匯入，不允許部分成功——這樣
                 「確認匯入」的結果一定跟這張預覽表看到的完全一致。 --}}
            <div class="alert-error mt-4">
                有 {{ $counts['error'] }} 列有問題，為了避免匯入結果跟預覽不一致，整批都不會匯入。請修正 Excel 檔案後重新上傳。
            </div>
        @endif

        <div class="table-wrap mt-4">
            <table class="data-table">
                <colgroup>
                    <col style="width: 7%">
                    <col style="width: 13%">
                    <col style="width: 11%">
                    <col style="width: 8%">
                    <col style="width: 15%">
                    <col style="width: 7%">
                    <col style="width: 39%">
                </colgroup>
                <thead>
                    <tr>
                        <th>列</th>
                        <th>學號</th>
                        <th>班級</th>
                        <th>座號</th>
                        <th>姓名</th>
                        <th>性別</th>
                        <th>結果</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row['line'] }}</td>
                            <td>{{ $row['student_number'] }}</td>
                            <td>{{ $row['class']?->shortLabel() ?? $row['class_code'] }}</td>
                            <td>{{ $row['seat_number'] }}</td>
                            <td>{{ $row['name'] }}</td>
                            <td>{{ $row['gender'] }}</td>
                            <td>
                                @if ($row['outcome'] === 'error')
                                    <span class="badge-danger">有問題</span>
                                    <span class="ml-1 text-slate-600 dark:text-slate-300">{{ $row['reason'] }}</span>
                                @elseif ($row['outcome'] === 'create')
                                    <span class="badge-success">新增學生並加入班級</span>
                                @elseif ($row['outcome'] === 'attach')
                                    <span class="badge-neutral">既有學生，加入班級</span>
                                @else
                                    <span class="badge-neutral">已在班級，不需變動</span>
                                @endif

                                @if ($row['note'])
                                    <p class="field-hint">{{ $row['note'] }}</p>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="action-group mt-6">
            @if ($counts['error'] === 0)
                <button type="button" wire:click="import" class="btn-primary">
                    確認匯入
                </button>
            @endif
            <button type="button" wire:click="clearFile" class="btn-secondary">
                取消
            </button>
        </div>
    @elseif ($file)
        <div class="alert-error mt-6">
            這個檔案裡沒有可以匯入的資料列。
        </div>
    @endif
</div>
