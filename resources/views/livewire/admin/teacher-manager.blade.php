<div class="mx-auto max-w-4xl px-4 py-10">
    <div class="flex items-center justify-between">
        <h1 class="page-title">教師管理</h1>
        <button type="button" wire:click="toggleCreateForm" class="btn-primary">
            {{ $showCreateForm ? '取消' : '新增老師' }}
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
        <form wire:submit="createTeacher" class="surface mt-6 space-y-4 p-6">
            <div>
                <label class="field-label">連結登入帳號（選填）</label>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="accountSearch"
                    placeholder="輸入姓名或帳號過濾…"
                    class="field-input"
                >
                <select wire:model.live="userId" class="field-input">
                    <option value="">不連結帳號</option>
                    @foreach ($availableUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}（{{ $user->username }}）</option>
                    @endforeach
                </select>
                <p class="field-hint">
                    只有需要登入系統的導師才需要連結帳號，帳號要先在「帳號管理」建立好。
                    最多列出 50 筆，找不到請用上面的欄位過濾。
                </p>
            </div>

            <div>
                <label class="field-label">姓名</label>
                {{-- 連結帳號時姓名一律沿用該帳號的姓名，不用再手動打一次
                     ——見 App\Models\Teacher::resolveName() 的說明：帳號
                     本身就有姓名了，分開輸入容易兩邊打成不一樣的字。 --}}
                @if ($userId)
                    <p class="field-input bg-slate-100 text-slate-500 dark:bg-slate-900 dark:text-slate-500">
                        {{ $availableUsers->firstWhere('id', $userId)?->name }}
                    </p>
                    <p class="field-hint">已連結帳號，姓名直接沿用該帳號登記的姓名。</p>
                @else
                    <input type="text" wire:model="teacherName" class="field-input">
                    @error('teacherName') <p class="field-error">{{ $message }}</p> @enderror
                @endif
            </div>

            <button type="submit" class="btn-primary">
                建立
            </button>
        </form>
    @endif

    <div class="filter-bar mt-6">
        <div class="filter-field grow">
            <label class="field-label">搜尋姓名或登入帳號</label>
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="輸入老師姓名或登入帳號…"
                class="field-input"
            >
        </div>

        @if ($this->hasActiveFilters())
            <button type="button" wire:click="clearFilters" class="btn-secondary btn-xs">
                清除條件
            </button>
        @endif
    </div>

    <div class="table-wrap mt-4">
        <table class="data-table">
            <colgroup>
                <col style="width: 35%">
                <col style="width: 35%">
                <col style="width: 30%">
            </colgroup>
            <thead>
                @php
                    $sortColumn = $this->activeSortColumn();
                    $sortDirection = $this->activeSortDirection();
                @endphp
                <tr>
                    <x-sort-header column="name" :active="$sortColumn" :direction="$sortDirection">姓名</x-sort-header>
                    <x-sort-header column="username" :active="$sortColumn" :direction="$sortDirection">登入帳號</x-sort-header>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @if ($teachers->isEmpty())
                    <tr>
                        <td colspan="3" class="text-center text-slate-500 dark:text-slate-400">
                            找不到符合條件的老師。
                        </td>
                    </tr>
                @endif
                @foreach ($teachers as $teacher)
                    <tr>
                        @if ($editingTeacherId === $teacher->id)
                            <td colspan="3">
                                <form wire:submit="updateTeacher" class="flex flex-wrap items-center gap-2">
                                    <input
                                        type="search"
                                        wire:model.live.debounce.300ms="accountSearch"
                                        placeholder="過濾帳號…"
                                        class="field-input mt-0 w-32 py-1"
                                    >
                                    <select wire:model.live="userId" class="field-input mt-0 py-1">
                                        <option value="">不連結帳號</option>
                                        @foreach ($availableUsers as $user)
                                            <option value="{{ $user->id }}">{{ $user->name }}（{{ $user->username }}）</option>
                                        @endforeach
                                    </select>
                                    @if ($userId)
                                        <span class="field-input mt-0 w-auto bg-slate-100 py-1 text-slate-500 dark:bg-slate-900 dark:text-slate-500">
                                            {{ $availableUsers->firstWhere('id', $userId)?->name }}
                                        </span>
                                    @else
                                        <input type="text" wire:model="teacherName" class="field-input mt-0 py-1">
                                    @endif
                                    <button type="submit" class="btn-primary btn-xs">儲存</button>
                                    <button type="button" wire:click="cancelEdit" class="btn-secondary btn-xs">取消</button>
                                </form>
                            </td>
                        @else
                            <td>{{ $teacher->displayName() }}</td>
                            <td>{{ $teacher->user?->username ?? '—' }}</td>
                            <td>
                                <div class="action-group">
                                    <button type="button" wire:click="startEdit({{ $teacher->id }})" class="btn-secondary btn-xs">
                                        編輯
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="deleteTeacher({{ $teacher->id }})"
                                        wire:confirm="確定要刪除老師「{{ $teacher->displayName() }}」嗎？此操作無法復原。"
                                        class="btn-danger-ghost btn-xs"
                                    >
                                        刪除
                                    </button>
                                </div>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $teachers->links() }}
    </div>
</div>
