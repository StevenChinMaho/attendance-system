<div class="mx-auto max-w-4xl px-4 py-10">
    <div class="flex items-center justify-between">
        <h1 class="page-title">帳號管理</h1>
        <button type="button" wire:click="toggleCreateForm" class="btn-primary">
            {{ $showCreateForm ? '取消' : '新增帳號' }}
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
        <form wire:submit="createUser" class="surface mt-6 space-y-4 p-6">
            <div>
                <label class="field-label">姓名</label>
                <input type="text" wire:model="name" class="field-input">
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label">帳號</label>
                <input type="text" wire:model="username" class="field-input">
                @error('username') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label">初始密碼</label>
                <input type="text" wire:model="password" class="field-input">
                <p class="field-hint">請另外告知使用者這組密碼，系統不會寄送任何通知；該帳號下次登入會被要求另外設定新密碼。</p>
                @error('password') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label">身分</label>
                <select wire:model="role" class="field-input">
                    <option value="">請選擇</option>
                    @foreach ($roles as $roleName)
                        <option value="{{ $roleName }}">{{ \App\Support\RoleLabel::forName($roleName) }}</option>
                    @endforeach
                </select>
                @error('role') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn-primary">
                建立帳號
            </button>
        </form>
    @endif

    {{-- 搜尋／篩選列。帳號數量會多到光靠翻頁找不到人，所以放在表格正
         上方而不是收在某個展開面板裡。 --}}
    <div class="filter-bar mt-6">
        <div class="filter-field grow">
            <label class="field-label">搜尋姓名或帳號</label>
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="輸入姓名或登入帳號…"
                class="field-input"
            >
        </div>

        <div class="filter-field">
            <label class="field-label">身分</label>
            <select wire:model.live="roleFilter" class="field-input">
                <option value="">全部</option>
                @foreach ($roles as $roleName)
                    <option value="{{ $roleName }}">{{ \App\Support\RoleLabel::forName($roleName) }}</option>
                @endforeach
            </select>
        </div>

        <div class="filter-field">
            <label class="field-label">狀態</label>
            <select wire:model.live="statusFilter" class="field-input">
                <option value="">全部</option>
                <option value="active">啟用中</option>
                <option value="inactive">已停用</option>
            </select>
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
                <col style="width: 12%">
                <col style="width: 14%">
                <col style="width: 10%">
                <col style="width: 12%">
                <col style="width: 16%">
                <col style="width: 36%">
            </colgroup>
            <thead>
                @php
                    $sortColumn = $this->activeSortColumn();
                    $sortDirection = $this->activeSortDirection();
                @endphp
                <tr>
                    <x-sort-header column="name" :active="$sortColumn" :direction="$sortDirection">姓名</x-sort-header>
                    <x-sort-header column="username" :active="$sortColumn" :direction="$sortDirection">帳號</x-sort-header>
                    <x-sort-header column="role" :active="$sortColumn" :direction="$sortDirection">身分</x-sort-header>
                    <x-sort-header column="status" :active="$sortColumn" :direction="$sortDirection">狀態</x-sort-header>
                    <x-sort-header column="last_login" :active="$sortColumn" :direction="$sortDirection">最後登入</x-sort-header>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @if ($users->isEmpty())
                    <tr>
                        <td colspan="6" class="text-center text-slate-500 dark:text-slate-400">
                            找不到符合條件的帳號。
                        </td>
                    </tr>
                @endif

                @foreach ($users as $user)
                    <tr>
                        @if ($editingUserId === $user->id)
                            <td colspan="6">
                                <form wire:submit="updateUser" class="flex flex-wrap items-center gap-2">
                                    <input type="text" wire:model="name" class="field-input mt-0 py-1">
                                    <select wire:model="role" class="field-input mt-0 py-1">
                                        @foreach ($roles as $roleName)
                                            <option value="{{ $roleName }}">{{ \App\Support\RoleLabel::forName($roleName) }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn-primary btn-xs">儲存</button>
                                    <button type="button" wire:click="cancelEdit" class="btn-secondary btn-xs">取消</button>
                                </form>
                                @error('name') <p class="field-error">{{ $message }}</p> @enderror
                                @error('role') <p class="field-error">{{ $message }}</p> @enderror
                            </td>
                        @elseif ($resettingPasswordUserId === $user->id)
                            <td colspan="6">
                                <form wire:submit="resetPassword" class="flex flex-wrap items-center gap-2">
                                    <input type="text" wire:model="newPassword" placeholder="新密碼" class="field-input mt-0 py-1">
                                    <button type="submit" class="btn-primary btn-xs">確認重置</button>
                                    <button type="button" wire:click="cancelResetPassword" class="btn-secondary btn-xs">取消</button>
                                </form>
                                <p class="field-hint">請另外告知使用者這組新密碼；重置後該帳號現有的登入 session 會失效，下次登入會被要求另外設定新密碼。</p>
                                @error('newPassword') <p class="field-error">{{ $message }}</p> @enderror
                            </td>
                        @else
                            <td>{{ $user->name }}</td>
                            <td>{{ $user->username }}</td>
                            <td>{{ $user->roles->pluck('name')->map(fn ($name) => \App\Support\RoleLabel::forName($name))->join('、') ?: '—' }}</td>
                            <td>
                                <span class="{{ $user->is_active ? 'badge-success' : 'badge-neutral' }}">
                                    {{ $user->is_active ? '啟用中' : '已停用' }}
                                </span>
                                @if ($user->must_change_password)
                                    <span class="badge-warning" title="這個帳號還沒有換掉管理者代打的密碼">待改密碼</span>
                                @endif
                            </td>
                            <td class="text-slate-500 dark:text-slate-400">
                                {{ $user->last_login_at?->format('Y-m-d H:i') ?? '尚未登入' }}
                            </td>
                            <td>
                                @unless ($user->is(auth()->user()))
                                    <div class="action-group">
                                        <button type="button" wire:click="startEdit({{ $user->id }})" class="btn-secondary btn-xs">
                                            編輯
                                        </button>
                                        <button type="button" wire:click="startResetPassword({{ $user->id }})" class="btn-secondary btn-xs">
                                            重置密碼
                                        </button>
                                        <button type="button" wire:click="toggleActive({{ $user->id }})" class="btn-secondary btn-xs">
                                            {{ $user->is_active ? '停用' : '啟用' }}
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="deleteUser({{ $user->id }})"
                                            wire:confirm="確定要刪除帳號「{{ $user->username }}」嗎？此操作無法復原。"
                                            class="btn-danger-ghost btn-xs"
                                        >
                                            刪除
                                        </button>
                                    </div>
                                @endunless
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
</div>
