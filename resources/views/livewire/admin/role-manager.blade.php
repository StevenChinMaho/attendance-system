<div class="mx-auto max-w-5xl px-4 py-10">
    <div class="flex items-center justify-between">
        <h1 class="page-title">角色管理</h1>
        <button type="button" wire:click="toggleCreateForm" class="btn-primary">
            {{ $showCreateForm ? '取消' : '新增角色' }}
        </button>
    </div>
    <p class="page-subtitle mt-1">
        新增角色後，到「帳號管理」把角色指派給帳號即可——那個角色能看到、進入哪些後台頁面，
        由這裡勾選的權限決定。
    </p>

    @if (session('status'))
        <div class="alert-success mt-4">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="alert-error mt-4">{{ session('error') }}</div>
    @endif

    @if ($showCreateForm)
        <form wire:submit="createRole" class="surface mt-6 space-y-4 p-6">
            <div>
                <label class="field-label">角色名稱</label>
                <input type="text" wire:model="name" class="field-input">
                <p class="field-hint">僅供系統內部識別，建議用英文代號（例如 exam_supervisor）。</p>
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="field-label">開放的頁面權限</span>
                <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($permissions as $permission)
                        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                            <input
                                type="checkbox"
                                wire:model="selectedPermissions"
                                value="{{ $permission->name }}"
                                class="rounded border-slate-300 dark:border-slate-600"
                            >
                            {{ $permissionLabels[$permission->name] ?? $permission->name }}
                        </label>
                    @endforeach
                </div>
            </div>

            <button type="submit" class="btn-primary">建立角色</button>
        </form>
    @endif

    <div class="table-wrap mt-6">
        <table class="data-table">
            <colgroup>
                <col style="width: 14%">
                @foreach ($permissions as $permission)
                    <col>
                @endforeach
                <col style="width: 8%">
            </colgroup>
            <thead>
                <tr>
                    <th>角色</th>
                    @foreach ($permissions as $permission)
                        <th class="text-center">{{ $permissionLabels[$permission->name] ?? $permission->name }}</th>
                    @endforeach
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($roles as $role)
                    @php $isProtected = in_array($role->name, $protectedRoleNames, true); @endphp
                    <tr>
                        <td>
                            {{ $role->name }}
                            @if ($isProtected)
                                <span class="badge-neutral">系統內建</span>
                            @endif
                        </td>
                        @foreach ($permissions as $permission)
                            <td class="text-center">
                                <input
                                    type="checkbox"
                                    @checked($role->permissions->contains('name', $permission->name))
                                    @if ($isProtected)
                                        disabled
                                        title="系統內建角色的權限無法調整"
                                    @else
                                        wire:click="togglePermission({{ $role->id }}, '{{ $permission->name }}')"
                                    @endif
                                    class="rounded border-slate-300 dark:border-slate-600"
                                >
                            </td>
                        @endforeach
                        <td>
                            @unless ($isProtected)
                                <div class="action-group">
                                    <button
                                        type="button"
                                        wire:click="deleteRole({{ $role->id }})"
                                        wire:confirm="確定要刪除角色「{{ $role->name }}」嗎？"
                                        class="btn-danger-ghost btn-xs"
                                    >
                                        刪除
                                    </button>
                                </div>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
