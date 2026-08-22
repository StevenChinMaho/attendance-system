<div wire:poll.15s class="mx-auto max-w-5xl px-4 py-10">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-lg font-semibold text-slate-900">即時點名看板</h1>
            <p class="mt-1 text-xs text-slate-500">
                顯示範圍：{{ \App\Support\AcademicPeriod::label($selectedAcademicYear, $selectedSemester) }}
            </p>
        </div>

        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-xs font-medium text-slate-500">日期</label>
                <input type="date" wire:model.live="date" class="mt-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm">
            </div>

            <div class="flex gap-2">
                @foreach (\App\Livewire\Concerns\AttendancePeriods::PERIODS as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('period', '{{ $value }}')"
                        class="rounded-md px-3 py-1.5 text-sm font-medium {{ $period === $value ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <p class="mt-2 text-xs text-slate-400">每 15 秒自動更新一次。</p>

    <div class="mt-4 overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-2">班級</th>
                    <th class="px-4 py-2">點名狀態</th>
                    <th class="px-4 py-2">應到</th>
                    @foreach ($statusOptions as $option)
                        <th class="px-4 py-2">{{ $option->label() }}</th>
                    @endforeach
                    <th class="px-4 py-2">需留意學生</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($summaries as $summary)
                    <tr>
                        <td class="px-4 py-2 align-top font-medium text-slate-900">{{ $summary['class']->shortLabel() }}</td>
                        <td class="px-4 py-2 align-top">
                            @if ($summary['submitted'])
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">已點名</span>
                            @else
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700">尚未點名</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 align-top">{{ $summary['total'] }}</td>

                        @if ($summary['submitted'])
                            @foreach ($statusOptions as $option)
                                <td class="px-4 py-2 align-top">{{ $summary['counts'][$option->value] ?? 0 }}</td>
                            @endforeach
                            <td class="px-4 py-2 align-top">
                                @forelse ($summary['exceptions'] as $exception)
                                    <div class="text-xs text-slate-600">{{ $exception['name'] }}（{{ $exception['status'] }}）</div>
                                @empty
                                    <span class="text-xs text-slate-400">—</span>
                                @endforelse
                            </td>
                        @else
                            <td colspan="{{ count($statusOptions) + 1 }}" class="px-4 py-2 align-top text-xs text-slate-400">
                                —
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($statusOptions) + 3 }}" class="px-4 py-6 text-center text-sm text-slate-500">
                            目前還沒有任何班級資料。
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
