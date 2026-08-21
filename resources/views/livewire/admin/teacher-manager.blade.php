<div class="mx-auto max-w-4xl px-4 py-10">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold text-slate-900">教師管理</h1>
        <button
            type="button"
            wire:click="$toggle('showCreateForm')"
            class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700"
        >
            {{ $showCreateForm ? '取消' : '新增老師' }}
        </button>
    </div>

    @if (session('status'))
        <div class="mt-4 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    @if ($showCreateForm)
        <form wire:submit="createTeacher" class="mt-6 space-y-4 rounded-lg border border-slate-200 bg-white p-6">
            <div>
                <label class="block text-sm font-medium text-slate-700">姓名</label>
                <input type="text" wire:model="teacherName" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                @error('teacherName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700">連結登入帳號（選填）</label>
                <select wire:model="userId" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="">不連結帳號</option>
                    @foreach ($availableUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}（{{ $user->username }}）</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-slate-500">只有需要登入系統的導師才需要連結帳號，帳號要先在「帳號管理」建立好。</p>
            </div>

            <button type="submit" class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">
                建立
            </button>
        </form>
    @endif

    <table class="mt-6 w-full overflow-hidden rounded-lg border border-slate-200 bg-white text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
            <tr>
                <th class="px-4 py-2">姓名</th>
                <th class="px-4 py-2">登入帳號</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($teachers as $teacher)
                <tr>
                    @if ($editingTeacherId === $teacher->id)
                        <td class="px-4 py-2" colspan="3">
                            <form wire:submit="updateTeacher" class="flex flex-wrap items-center gap-2">
                                <input type="text" wire:model="teacherName" class="rounded-md border border-slate-300 px-2 py-1 text-sm">
                                <select wire:model="userId" class="rounded-md border border-slate-300 px-2 py-1 text-sm">
                                    <option value="">不連結帳號</option>
                                    @foreach ($availableUsers as $user)
                                        <option value="{{ $user->id }}">{{ $user->name }}（{{ $user->username }}）</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="text-xs text-emerald-700 underline">儲存</button>
                                <button type="button" wire:click="cancelEdit" class="text-xs text-slate-500 underline">取消</button>
                            </form>
                        </td>
                    @else
                        <td class="px-4 py-2">{{ $teacher->teacher_name }}</td>
                        <td class="px-4 py-2">{{ $teacher->user?->username ?? '—' }}</td>
                        <td class="px-4 py-2 text-right">
                            <button type="button" wire:click="startEdit({{ $teacher->id }})" class="text-xs text-slate-600 underline hover:text-slate-900">
                                編輯
                            </button>
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="mt-4">
        {{ $teachers->links() }}
    </div>
</div>
