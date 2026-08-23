<div class="mx-auto max-w-4xl px-4 py-10">
    <div class="flex items-center justify-between">
        <h1 class="page-title">帳號管理</h1>
        <button type="button" wire:click="$toggle('showCreateForm')" class="btn-primary">
            {{ $showCreateForm ? '取消' : '新增帳號' }}
        </button>
    </div>

    @if (session('status'))
        <div class="alert-success mt-4">
            {{ session('status') }}
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
                <p class="field-hint">請另外告知使用者這組密碼，系統不會寄送任何通知。</p>
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

    <div class="table-wrap mt-6">
        <table class="data-table">
            <colgroup>
                <col style="width: 18%">
                <col style="width: 16%">
                <col style="width: 18%">
                <col style="width: 12%">
                <col style="width: 18%">
                <col style="width: 18%">
            </colgroup>
            <thead>
                <tr>
                    <th>姓名</th>
                    <th>帳號</th>
                    <th>身分</th>
                    <th>狀態</th>
                    <th>最後登入</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->username }}</td>
                        <td>{{ $user->roles->pluck('name')->map(fn ($name) => \App\Support\RoleLabel::forName($name))->join('、') ?: '—' }}</td>
                        <td>
                            <span class="{{ $user->is_active ? 'badge-success' : 'badge-neutral' }}">
                                {{ $user->is_active ? '啟用中' : '已停用' }}
                            </span>
                        </td>
                        <td class="text-slate-500 dark:text-slate-400">
                            {{ $user->last_login_at?->format('Y-m-d H:i') ?? '尚未登入' }}
                        </td>
                        <td>
                            @unless ($user->is(auth()->user()))
                                <div class="action-group">
                                    <button type="button" wire:click="toggleActive({{ $user->id }})" class="btn-secondary btn-xs">
                                        {{ $user->is_active ? '停用' : '啟用' }}
                                    </button>
                                </div>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
</div>
