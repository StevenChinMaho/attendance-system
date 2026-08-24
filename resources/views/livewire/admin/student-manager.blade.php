<div class="mx-auto max-w-4xl px-4 py-10">
    <a href="{{ route('admin.classes') }}" class="text-xs text-slate-500 underline hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">← 回班級列表</a>

    <div class="mt-2 flex items-center justify-between">
        <h1 class="page-title">{{ $schoolClass->shortLabel() }} 學生管理</h1>
        <button type="button" wire:click="toggleCreateForm" class="btn-primary">
            {{ $showCreateForm ? '取消' : '新增學生' }}
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

    @if ($showCreateForm)
        <form wire:submit="createStudent" class="surface mt-6 grid grid-cols-2 gap-4 p-6">
            <div>
                <label class="field-label">學號</label>
                <input type="text" wire:model="studentNumber" class="field-input">
                @error('studentNumber') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label">座號</label>
                <input type="text" wire:model="seatNumber" class="field-input">
                @error('seatNumber') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label">姓名</label>
                {{-- 連結帳號時姓名一律沿用該帳號的姓名，不用再手動打
                     一次——見 App\Models\Concerns\HasLinkableAccountName
                     的說明，跟教師管理同一套處理方式。 --}}
                @if ($userId)
                    <p class="field-input bg-slate-100 text-slate-500 dark:bg-slate-900 dark:text-slate-500">
                        {{ $availableUsers->firstWhere('id', $userId)?->name }}
                    </p>
                    <p class="field-hint">已連結帳號，姓名直接沿用該帳號登記的姓名。</p>
                @else
                    <input type="text" wire:model="name" class="field-input">
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                @endif
            </div>

            <div>
                <label class="field-label">性別</label>
                <select wire:model="gender" class="field-input">
                    <option value="">請選擇</option>
                    <option value="男">男</option>
                    <option value="女">女</option>
                </select>
                @error('gender') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="col-span-2">
                <label class="field-label">連結登入帳號（選填，副班長才需要）</label>
                <select wire:model.live="userId" class="field-input">
                    <option value="">不連結帳號</option>
                    @foreach ($availableUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}（{{ $user->username }}）</option>
                    @endforeach
                </select>
            </div>

            <div class="col-span-2">
                <button type="submit" class="btn-primary">
                    建立
                </button>
            </div>
        </form>
    @endif

    <div class="table-wrap mt-6">
        <table class="data-table">
            <colgroup>
                <col style="width: 8%">
                <col style="width: 13%">
                <col style="width: 17%">
                <col style="width: 7%">
                <col style="width: 12%">
                <col style="width: 17%">
                <col style="width: 26%">
            </colgroup>
            <thead>
                <tr>
                    <th>座號</th>
                    <th>學號</th>
                    <th>姓名</th>
                    <th>性別</th>
                    <th>狀態</th>
                    <th>登入帳號</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($students as $student)
                    <tr>
                        @if ($editingStudentId === $student->id)
                            <td colspan="7">
                                <form wire:submit="updateStudent" class="flex flex-wrap items-center gap-2">
                                    <input type="text" wire:model="seatNumber" class="field-input mt-0 w-16 py-1">
                                    <input type="text" wire:model="studentNumber" class="field-input mt-0 w-24 py-1">
                                    @if ($userId)
                                        <span class="field-input mt-0 w-auto bg-slate-100 py-1 text-slate-500 dark:bg-slate-900 dark:text-slate-500">
                                            {{ $availableUsers->firstWhere('id', $userId)?->name }}
                                        </span>
                                    @else
                                        <input type="text" wire:model="name" class="field-input mt-0 py-1">
                                    @endif
                                    <select wire:model="gender" class="field-input mt-0 py-1">
                                        <option value="男">男</option>
                                        <option value="女">女</option>
                                    </select>
                                    <select wire:model.live="userId" class="field-input mt-0 py-1">
                                        <option value="">不連結帳號</option>
                                        @foreach ($availableUsers as $user)
                                            <option value="{{ $user->id }}">{{ $user->name }}（{{ $user->username }}）</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn-primary btn-xs">儲存</button>
                                    <button type="button" wire:click="cancelEdit" class="btn-secondary btn-xs">取消</button>
                                </form>
                                @error('studentNumber') <p class="field-error">{{ $message }}</p> @enderror
                                @error('seatNumber') <p class="field-error">{{ $message }}</p> @enderror
                            </td>
                        @elseif ($markingLeftStudentId === $student->id)
                            <td colspan="7">
                                <form wire:submit="confirmMarkAsLeft" class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm text-slate-600 dark:text-slate-300">轉出日期：</span>
                                    <input type="date" wire:model="leftDate" class="field-input mt-0 py-1">
                                    <button type="submit" class="btn-primary btn-xs">確認標記已轉出</button>
                                    <button type="button" wire:click="cancelMarkAsLeft" class="btn-secondary btn-xs">取消</button>
                                </form>
                                <p class="field-hint">預設是今天，如果實際轉出是過去某一天，改成那一天即可——這會影響他在那之後的日子是否還會出現在點名名冊裡。</p>
                                @error('leftDate') <p class="field-error">{{ $message }}</p> @enderror
                            </td>
                        @else
                            <td>{{ $student->seat_number }}</td>
                            <td>{{ $student->student_number }}</td>
                            <td>{{ $student->displayName() }}</td>
                            <td>{{ $student->gender }}</td>
                            <td>
                                @if ($student->left_at)
                                    <span class="badge-neutral" title="{{ $student->left_at->format('Y-m-d') }} 標記已轉出">
                                        已轉出
                                    </span>
                                @else
                                    <span class="badge-success">在讀</span>
                                @endif
                            </td>
                            <td>{{ $student->user?->username ?? '—' }}</td>
                            <td>
                                <div class="action-group">
                                    <button type="button" wire:click="startEdit({{ $student->id }})" class="btn-secondary btn-xs">
                                        編輯
                                    </button>
                                    @if ($student->left_at)
                                        <button
                                            type="button"
                                            wire:click="restoreStudent({{ $student->id }})"
                                            wire:confirm="確定要把「{{ $student->displayName() }}」恢復為在讀嗎？"
                                            class="btn-secondary btn-xs"
                                        >
                                            恢復在讀
                                        </button>
                                    @else
                                        <button type="button" wire:click="startMarkAsLeft({{ $student->id }})" class="btn-secondary btn-xs">
                                            標記已轉出
                                        </button>
                                    @endif
                                    @if ($student->attendance_records_count === 0)
                                        <button
                                            type="button"
                                            wire:click="deleteStudent({{ $student->id }})"
                                            wire:confirm="確定要刪除學生「{{ $student->displayName() }}」嗎？此操作無法復原。"
                                            class="btn-danger-ghost btn-xs"
                                        >
                                            刪除
                                        </button>
                                    @endif
                                </div>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
