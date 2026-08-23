<div wire:poll.15s class="mx-auto max-w-5xl px-4 py-10">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="page-title">即時點名看板</h1>
            <p class="page-subtitle mt-1">
                顯示範圍：{{ \App\Support\AcademicPeriod::label($selectedAcademicYear, $selectedSemester) }}
            </p>
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">日期</label>
            <input type="date" wire:model.live="date" class="field-input mt-1 py-1.5">
        </div>
    </div>

    <p class="mt-2 text-xs text-slate-400 dark:text-slate-500">每 15 秒自動更新一次。早/中/下午三個時段一次顯示，遲到算「出席」、早退算「缺席」。</p>

    <div class="table-wrap mt-4">
        <table class="data-table">
            {{--
                班級／應到／需留意學生各自 rowspan 兩列，時段名稱橫跨
                出席/缺席兩個子欄，欄寬用 colgroup 固定比例，不受「已點名
                /尚未點名」文字或未點名時 colspan 併格影響。
            --}}
            <colgroup>
                <col style="width: 14%">
                <col style="width: 8%">
                @foreach ($periods as $periodValue => $periodLabel)
                    <col style="width: 7%">
                    <col style="width: 7%">
                @endforeach
                <col style="width: 36%">
            </colgroup>
            <thead>
                <tr>
                    <th rowspan="2">班級</th>
                    <th rowspan="2">應到</th>
                    @foreach ($periods as $periodValue => $periodLabel)
                        <th colspan="2">{{ $periodLabel }}</th>
                    @endforeach
                    <th rowspan="2">需留意學生</th>
                </tr>
                <tr>
                    {{--
                        divide-x（.data-table thead tr）只在同一列的相鄰
                        儲存格之間畫線，但這一列最後一格「缺席」右邊緊接
                        的是上一列的 rowspan="2"「需留意學生」——兩者不在
                        同一個 <tr> 裡，divide-x 的 sibling 選擇器完全不會
                        套用到它們之間，導致這條理應延續兩列的直向格線在
                        第二列憑空斷掉。這裡手動幫最後一個「缺席」補上同
                        色的右邊框，接上第一列在「需留意學生」左邊那條線。
                    --}}
                    @foreach ($periods as $periodValue => $periodLabel)
                        <th>出席</th>
                        <th @if ($loop->last) class="border-r border-slate-200 dark:border-slate-800" @endif>缺席</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($summaries as $summary)
                    <tr>
                        <td class="align-top font-medium text-slate-900 dark:text-slate-100">{{ $summary['class']->shortLabel() }}</td>
                        <td class="align-top">{{ $summary['total'] }}</td>

                        @foreach ($periods as $periodValue => $periodLabel)
                            @php $period = $summary['periods'][$periodValue]; @endphp
                            @if ($period['submitted'])
                                <td class="align-top">{{ $period['present'] }}</td>
                                <td class="align-top">{{ $period['absent'] }}</td>
                            @else
                                <td colspan="2" class="align-top text-xs text-slate-400 dark:text-slate-500">未點名</td>
                            @endif
                        @endforeach

                        <td class="align-top">
                            @forelse ($summary['exceptions'] as $exception)
                                <div
                                    class="mb-0.5 inline-block"
                                    x-data="{ open: false, style: '' }"
                                    x-on:mouseenter="
                                        const rect = $el.getBoundingClientRect();
                                        style = 'left:' + rect.left + 'px; top:' + (rect.bottom + 4) + 'px;';
                                        open = true;
                                    "
                                    x-on:mouseleave="open = false"
                                >
                                    <span class="cursor-help text-xs text-slate-600 underline decoration-dotted decoration-slate-400 dark:text-slate-400">
                                        {{ $exception['name'] }}（{{ $exception['period'] }}{{ $exception['status'] }}）
                                    </span>

                                    <div
                                        x-show="open"
                                        x-cloak
                                        x-transition
                                        :style="style"
                                        class="surface fixed z-50 w-64 p-3 text-xs text-slate-600 shadow-lg dark:text-slate-300"
                                    >
                                        @if ($exception['followUps']->isEmpty())
                                            <p class="text-slate-400 dark:text-slate-500">尚無處理情形記錄。</p>
                                        @else
                                            <ul class="space-y-1">
                                                @foreach ($exception['followUps'] as $followUp)
                                                    <li>
                                                        <span class="text-slate-400 dark:text-slate-500">{{ $followUp->created_at->format('H:i') }}</span>
                                                        {{ $followUp->content }}
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>
                                </div>
                                <br>
                            @empty
                                <span class="text-xs text-slate-400 dark:text-slate-500">—</span>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ 3 + count($periods) * 2 }}" class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                            目前還沒有任何班級資料。
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
