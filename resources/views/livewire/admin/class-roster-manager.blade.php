<div class="mx-auto max-w-4xl px-4 py-10">
    <a href="{{ route('admin.classes') }}" class="text-xs text-slate-500 underline hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">← 回班級列表</a>

    <div class="mt-2 flex items-center justify-between">
        <div>
            <h1 class="page-title">{{ $schoolClass->shortLabel() }} 學生名單</h1>
            <p class="page-subtitle mt-1">加入／移出既有學生，不是在這裡新增學生本體——新增學生請到「學生管理」。</p>
        </div>
        <button type="button" wire:click="toggleAttachForm" class="btn-primary">
            {{ $showAttachForm ? '取消' : '加入學生' }}
        </button>
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

    @if ($showAttachForm)
        <form wire:submit="attachStudent" class="surface mt-6 flex flex-wrap items-end gap-4 p-6">
            <div class="flex-1">
                <label class="field-label">學生</label>
                <select wire:model="attachingStudentId" class="field-input">
                    <option value="">請選擇</option>
                    @foreach ($availableStudents as $student)
                        <option value="{{ $student->id }}">{{ $student->student_number }}　{{ $student->displayName() }}</option>
                    @endforeach
                </select>
                @error('attachingStudentId') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label">座號</label>
                <input type="text" wire:model="seatNumber" class="field-input w-24">
                @error('seatNumber') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn-primary">
                加入
            </button>
        </form>
    @endif

    <div class="table-wrap mt-6">
        <table class="data-table">
            <colgroup>
                <col style="width: 10%">
                <col style="width: 20%">
                <col style="width: 25%">
                <col style="width: 10%">
                <col style="width: 15%">
                <col style="width: 20%">
            </colgroup>
            <thead>
                <tr>
                    <th>座號</th>
                    <th>學號</th>
                    <th>姓名</th>
                    <th>狀態</th>
                    <th>登入帳號</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($students as $student)
                    <tr>
                        @if ($editingSeatForStudentId === $student->id)
                            <td colspan="6">
                                <form wire:submit="updateSeat" class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm text-slate-600 dark:text-slate-300">新座號：</span>
                                    <input type="text" wire:model="seatNumber" class="field-input mt-0 w-24 py-1">
                                    <button type="submit" class="btn-primary btn-xs">儲存</button>
                                    <button type="button" wire:click="cancelEditSeat" class="btn-secondary btn-xs">取消</button>
                                </form>
                                @error('seatNumber') <p class="field-error">{{ $message }}</p> @enderror
                            </td>
                        @else
                            <td>{{ $student->pivot->seat_number }}</td>
                            <td>{{ $student->student_number }}</td>
                            <td>{{ $student->displayName() }}</td>
                            <td>
                                @if ($student->currentDeparture)
                                    <span class="badge-neutral" title="{{ $student->currentDeparture->left_at->format('Y-m-d') }} 起轉出">
                                        已轉出
                                    </span>
                                @else
                                    <span class="badge-success">在讀</span>
                                @endif
                            </td>
                            <td>{{ $student->user?->username ?? '—' }}</td>
                            <td>
                                <div class="action-group">
                                    <button type="button" wire:click="startEditSeat({{ $student->id }})" class="btn-secondary btn-xs">
                                        改座號
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="removeStudent({{ $student->id }})"
                                        wire:confirm="確定要將「{{ $student->displayName() }}」移出「{{ $schoolClass->shortLabel() }}」嗎？他過去在這個班的點名紀錄不會被刪除，但之後要回頭修正會需要先重新加回這個班級。"
                                        class="btn-danger-ghost btn-xs"
                                    >
                                        移出班級
                                    </button>
                                </div>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
