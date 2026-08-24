<div class="mx-auto max-w-5xl px-4 py-10">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="page-title">學生管理</h1>
            <p class="page-subtitle mt-1">
                「目前班級」欄顯示範圍：
                <span @unless (\App\Support\AcademicPeriod::isSelectedCurrent()) class="font-medium text-amber-600 dark:text-amber-400" @endunless>
                    {{ \App\Support\AcademicPeriod::label($selectedAcademicYear, $selectedSemester) }}
                    @unless (\App\Support\AcademicPeriod::isSelectedCurrent())
                        （非本學期）
                    @endunless
                </span>
                ，要把學生加入／移出某個班級，請到「班級管理」個別班級的「管理學生」。
            </p>
        </div>
        <div class="action-group">
            <a href="{{ route('admin.students.import') }}" class="btn-secondary">
                批量匯入
            </a>
            <button type="button" wire:click="toggleCreateForm" class="btn-primary">
                {{ $showCreateForm ? '取消' : '新增學生' }}
            </button>
        </div>
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
                <label class="field-label">性別</label>
                <select wire:model="gender" class="field-input">
                    <option value="">請選擇</option>
                    <option value="男">男</option>
                    <option value="女">女</option>
                </select>
                @error('gender') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="col-span-2">
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
                <p class="field-hint">建立後這個學生還不屬於任何班級，接下來請到「班級管理」個別班級的「管理學生」把他加進去。</p>
                <button type="submit" class="btn-primary mt-2">
                    建立
                </button>
            </div>
        </form>
    @endif

    <div class="table-wrap mt-6">
        <table class="data-table">
            <colgroup>
                <col style="width: 13%">
                <col style="width: 17%">
                <col style="width: 7%">
                <col style="width: 15%">
                <col style="width: 12%">
                <col style="width: 14%">
                <col style="width: 22%">
            </colgroup>
            <thead>
                <tr>
                    <th>學號</th>
                    <th>姓名</th>
                    <th>性別</th>
                    <th>目前班級</th>
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
                        @elseif ($restoringStudentId === $student->id)
                            <td colspan="7">
                                <form wire:submit="confirmRestore" class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm text-slate-600 dark:text-slate-300">轉入日期：</span>
                                    <input type="date" wire:model="returnedDate" class="field-input mt-0 py-1">
                                    <button type="submit" class="btn-primary btn-xs">確認恢復在讀</button>
                                    <button type="button" wire:click="cancelRestore" class="btn-secondary btn-xs">取消</button>
                                </form>
                                <p class="field-hint">預設是今天，如果實際轉入是過去某一天，改成那一天即可——同一個學生之後如果又轉出，這段期間會完整保留，不會互相覆蓋。</p>
                                @error('returnedDate') <p class="field-error">{{ $message }}</p> @enderror
                            </td>
                        @else
                            <td>{{ $student->student_number }}</td>
                            <td>{{ $student->displayName() }}</td>
                            <td>{{ $student->gender }}</td>
                            <td>
                                @forelse ($student->schoolClasses as $schoolClass)
                                    <span class="badge-neutral">{{ $schoolClass->shortLabel() }}</span>
                                @empty
                                    <span class="text-slate-400 dark:text-slate-500">未加入班級</span>
                                @endforelse
                            </td>
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
                                    <button type="button" wire:click="startEdit({{ $student->id }})" class="btn-secondary btn-xs">
                                        編輯
                                    </button>
                                    @if ($student->currentDeparture)
                                        <button type="button" wire:click="startRestore({{ $student->id }})" class="btn-secondary btn-xs">
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

    <div class="mt-4">
        {{ $students->links() }}
    </div>
</div>
