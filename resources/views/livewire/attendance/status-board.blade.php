<div wire:poll.15s class="mx-auto max-w-5xl px-4 py-10">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="page-title">即時點名看板</h1>
            <p class="page-subtitle mt-1">
                顯示範圍：{{ \App\Support\AcademicPeriod::label($selectedAcademicYear, $selectedSemester) }}
            </p>
        </div>

        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">日期</label>
                <input type="date" wire:model.live="date" class="field-input mt-1 py-1.5">
            </div>

            <div class="flex gap-2">
                @foreach (\App\Livewire\Concerns\AttendancePeriods::PERIODS as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('period', '{{ $value }}')"
                        @class([
                            'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                            'bg-indigo-600 text-white hover:bg-indigo-500' => $period === $value,
                            'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700' => $period !== $value,
                        ])
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <p class="mt-2 text-xs text-slate-400 dark:text-slate-500">每 15 秒自動更新一次。</p>

    <div class="table-wrap mt-4">
        <table class="data-table">
            {{--
                固定欄寬比例，不受「已點名/尚未點名」文字長短或未點名時
                整列 colspan 併成一格影響——沒有這個，table-fixed 只會讓
                所有欄位平分寬度（班級名稱那欄反而會太窄），所以還是要
                自己給每欄一個合理比例。狀態欄位數會隨 AttendanceStatus
                增減，寬度用剩餘空間除以欄位數算，不寫死。
            --}}
            @php $statusColumnWidth = 36 / max(count($statusOptions), 1); @endphp
            <colgroup>
                <col style="width: 16%">
                <col style="width: 12%">
                <col style="width: 8%">
                @foreach ($statusOptions as $option)
                    <col style="width: {{ $statusColumnWidth }}%">
                @endforeach
                <col style="width: 28%">
            </colgroup>
            <thead>
                <tr>
                    <th>班級</th>
                    <th>點名狀態</th>
                    <th>應到</th>
                    @foreach ($statusOptions as $option)
                        <th>{{ $option->label() }}</th>
                    @endforeach
                    <th>需留意學生</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($summaries as $summary)
                    <tr>
                        <td class="align-top font-medium text-slate-900 dark:text-slate-100">{{ $summary['class']->shortLabel() }}</td>
                        <td class="align-top">
                            @if ($summary['submitted'])
                                <span class="badge-success">已點名</span>
                            @else
                                <span class="badge-warning">尚未點名</span>
                            @endif
                        </td>
                        <td class="align-top">{{ $summary['total'] }}</td>

                        @if ($summary['submitted'])
                            @foreach ($statusOptions as $option)
                                <td class="align-top">{{ $summary['counts'][$option->value] ?? 0 }}</td>
                            @endforeach
                            <td class="align-top">
                                @forelse ($summary['exceptions'] as $exception)
                                    <div class="text-xs text-slate-600 dark:text-slate-400">{{ $exception['name'] }}（{{ $exception['status'] }}）</div>
                                @empty
                                    <span class="text-xs text-slate-400 dark:text-slate-500">—</span>
                                @endforelse
                            </td>
                        @else
                            <td colspan="{{ count($statusOptions) + 1 }}" class="align-top text-xs text-slate-400 dark:text-slate-500">
                                —
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($statusOptions) + 3 }}" class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                            目前還沒有任何班級資料。
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
