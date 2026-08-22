<div class="mx-auto max-w-3xl px-4 py-10">
    <h1 class="text-lg font-semibold text-slate-900">{{ $schoolClass->label() }} 點名</h1>

    @if (session('status'))
        <div class="mt-4 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    <div class="mt-6 flex flex-wrap items-end gap-4 rounded-lg border border-slate-200 bg-white p-4">
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

        <span class="text-xs text-slate-500">
            {{ $currentSessionId ? '本時段已點過名，可以修改後重新送出。' : '本時段尚未點名。' }}
        </span>
    </div>

    <div class="mt-4 flex justify-end">
        <button
            type="button"
            wire:click="markAllPresent"
            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
            一鍵全到
        </button>
    </div>

    <table class="mt-2 w-full overflow-hidden rounded-lg border border-slate-200 bg-white text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
            <tr>
                <th class="px-4 py-2">座號</th>
                <th class="px-4 py-2">姓名</th>
                <th class="px-4 py-2">出席狀況</th>
                <th class="px-4 py-2">處理情形</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($students as $student)
                @php $record = $sessionRecords->get($student->id); @endphp
                <tr>
                    <td class="px-4 py-2 align-top">{{ $student->seat_number }}</td>
                    <td class="px-4 py-2 align-top">{{ $student->name }}</td>
                    <td class="px-4 py-2 align-top">
                        <select wire:model="statuses.{{ $student->id }}" class="rounded-md border border-slate-300 px-2 py-1 text-sm">
                            @foreach ($statusOptions as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td class="px-4 py-2 align-top">
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

    @if ($students->isEmpty())
        <p class="mt-4 text-sm text-slate-500">這個班級目前還沒有學生資料。</p>
    @else
        <button
            type="button"
            wire:click="submit"
            class="mt-4 rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700"
        >
            送出點名單
        </button>
    @endif
</div>
