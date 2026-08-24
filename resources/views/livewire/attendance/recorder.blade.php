<div class="mx-auto max-w-3xl px-4 py-10">
    <h1 class="page-title">{{ $schoolClass->shortLabel() }} 點名</h1>

    @if (session('status'))
        <div class="alert-success mt-4">
            {{ session('status') }}
        </div>
    @endif

    <div class="surface mt-6 flex flex-wrap items-end gap-4 p-4">
        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">日期</label>
            <div class="mt-1 flex items-center gap-1.5">
                <input type="date" wire:model.live="date" class="field-input mt-0 py-1.5">
                {{-- 補登/更正過去的點名紀錄是合理的操作，但切換日期後容易
                     忘記自己不在點今天的名，尤其送出後畫面看起來跟平常
                     點名沒有兩樣——跟學年度／學期的「非本學期」提示同一個
                     理由，見 App\Support\AcademicPeriod::isSelectedCurrent()。 --}}
                @unless ($date === now()->toDateString())
                    <span class="badge-warning whitespace-nowrap" title="今天是 {{ now()->format('Y-m-d') }}">
                        ⚠ 非本日
                    </span>
                @endunless
            </div>
        </div>

        <div class="flex gap-2">
            {{-- 除了「目前選的是哪一個」以外，還用顏色標出這一天哪些時段
                 已經點過名了，不用切過去才知道——見 Recorder::submittedPeriods()。 --}}
            @foreach (\App\Livewire\Concerns\AttendancePeriods::PERIODS as $value => $label)
                @php $isSubmitted = in_array($value, $submittedPeriods, true); @endphp
                <button
                    type="button"
                    wire:click="$set('period', '{{ $value }}')"
                    @class([
                        'relative rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                        'bg-indigo-600 text-white hover:bg-indigo-500' => $period === $value,
                        'border border-emerald-300 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400 dark:hover:bg-emerald-500/20' => $period !== $value && $isSubmitted,
                        'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700' => $period !== $value && ! $isSubmitted,
                    ])
                >
                    {{ $label }}
                    @if ($isSubmitted)
                        <span class="{{ $period === $value ? 'text-emerald-300' : 'text-emerald-600 dark:text-emerald-400' }}">✓</span>
                    @endif
                </button>
            @endforeach
        </div>

        <span class="text-xs text-slate-500 dark:text-slate-400">
            {{ $currentSessionId ? '本時段已點過名，可以修改後重新送出。' : '本時段尚未點名。' }}
        </span>
    </div>

    <div class="table-wrap mt-4">
        <table class="data-table">
            <colgroup>
                <col style="width: 10%">
                <col style="width: 18%">
                <col style="width: 22%">
                <col style="width: 50%">
            </colgroup>
            <thead>
                <tr>
                    <th>座號</th>
                    <th>姓名</th>
                    <th>出席狀況</th>
                    <th>處理情形</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($students as $student)
                    @php $record = $sessionRecords->get($student->id); @endphp
                    <tr>
                        <td class="align-top">{{ $student->pivot->seat_number }}</td>
                        <td class="align-top">{{ $student->displayName() }}</td>
                        <td class="align-top">
                            <select wire:model="statuses.{{ $student->id }}" class="field-input mt-0 py-1">
                                @foreach ($statusOptions as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="align-top">
                            {{-- 現在狀態是出席也要保留：狀態可能是被導師事後修正回來的
                                 （例如「查證後其實有到」），但之前留的處理情形歷史
                                 不該因此從畫面上消失，只是不再是「例外狀態」而已。 --}}
                            @if ($record && ($record->status !== \App\Enums\AttendanceStatus::Present || $record->followUps->isNotEmpty()))
                                @can('manageFollowUp', $record)
                                    {{-- key 帶狀態值，不能只用 record id：Livewire 巢狀元件是用
                                         key 判斷「這是不是同一個已掛載的子元件」，key 不變的話
                                         父層重新渲染不會強制子元件重新掛載讀取新資料——如果只用
                                         id，導師把狀態從缺席改回出席重新送出後，這個區塊雖然
                                         條件仍成立，畫面卻不會反映最新狀態，帶上狀態值讓每次
                                         狀態改變都視為新元件重新掛載。 --}}
                                    <livewire:attendance.follow-up-manager
                                        :record="$record"
                                        :key="'follow-up-'.$record->id.'-'.$record->status->value"
                                    />
                                @endcan
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($students->isEmpty())
        <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">這個班級目前還沒有學生資料。</p>
    @else
        <button type="button" wire:click="submit" class="btn-primary mt-4 px-4 py-2">
            送出點名單
        </button>
    @endif
</div>
