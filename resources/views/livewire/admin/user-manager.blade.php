<div class="mx-auto max-w-4xl px-4 py-10">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold text-slate-900">帳號管理</h1>
        <button
            type="button"
            wire:click="$toggle('showCreateForm')"
            class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700"
        >
            {{ $showCreateForm ? '取消' : '新增帳號' }}
        </button>
    </div>

    @if (session('status'))
        <div class="mt-4 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    @if ($showCreateForm)
        <form wire:submit="createUser" class="mt-6 space-y-4 rounded-lg border border-slate-200 bg-white p-6">
            <div>
                <label class="block text-sm font-medium text-slate-700">姓名</label>
                <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700">帳號</label>
                <input type="text" wire:model="username" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                @error('username') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700">初始密碼</label>
                <input type="text" wire:model="password" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                <p class="mt-1 text-xs text-slate-500">請另外告知使用者這組密碼，系統不會寄送任何通知。</p>
                @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700">角色</label>
                <select wire:model="role" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="">請選擇</option>
                    @foreach ($roles as $roleName)
                        <option value="{{ $roleName }}">{{ $roleName }}</option>
                    @endforeach
                </select>
                @error('role') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">
                建立帳號
            </button>
        </form>
    @endif

    <table class="mt-6 w-full overflow-hidden rounded-lg border border-slate-200 bg-white text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
            <tr>
                <th class="px-4 py-2">姓名</th>
                <th class="px-4 py-2">帳號</th>
                <th class="px-4 py-2">角色</th>
                <th class="px-4 py-2">狀態</th>
                <th class="px-4 py-2">最後登入</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($users as $user)
                <tr>
                    <td class="px-4 py-2">{{ $user->name }}</td>
                    <td class="px-4 py-2">{{ $user->username }}</td>
                    <td class="px-4 py-2">{{ $user->roles->pluck('name')->join('、') ?: '—' }}</td>
                    <td class="px-4 py-2">
                        <span class="rounded-full px-2 py-0.5 text-xs {{ $user->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-500' }}">
                            {{ $user->is_active ? '啟用中' : '已停用' }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-slate-500">
                        {{ $user->last_login_at?->format('Y-m-d H:i') ?? '尚未登入' }}
                    </td>
                    <td class="px-4 py-2 text-right">
                        @unless ($user->is(auth()->user()))
                            <button
                                type="button"
                                wire:click="toggleActive({{ $user->id }})"
                                class="text-xs text-slate-600 underline hover:text-slate-900"
                            >
                                {{ $user->is_active ? '停用' : '啟用' }}
                            </button>
                        @endunless
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
</div>
