<div class="mx-auto max-w-4xl px-4 py-10">
    <div class="flex items-center justify-between">
        <h1 class="page-title">教師管理</h1>
        <button type="button" wire:click="$toggle('showCreateForm')" class="btn-primary">
            {{ $showCreateForm ? '取消' : '新增老師' }}
        </button>
    </div>

    @if (session('status'))
        <div class="alert-success mt-4">
            {{ session('status') }}
        </div>
    @endif

    @if ($showCreateForm)
        <form wire:submit="createTeacher" class="surface mt-6 space-y-4 p-6">
            <div>
                <label class="field-label">姓名</label>
                <input type="text" wire:model="teacherName" class="field-input">
                @error('teacherName') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label">連結登入帳號（選填）</label>
                <select wire:model="userId" class="field-input">
                    <option value="">不連結帳號</option>
                    @foreach ($availableUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}（{{ $user->username }}）</option>
                    @endforeach
                </select>
                <p class="field-hint">只有需要登入系統的導師才需要連結帳號，帳號要先在「帳號管理」建立好。</p>
            </div>

            <button type="submit" class="btn-primary">
                建立
            </button>
        </form>
    @endif

    <div class="table-wrap mt-6">
        <table class="data-table">
            <thead>
                <tr>
                    <th>姓名</th>
                    <th>登入帳號</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($teachers as $teacher)
                    <tr>
                        @if ($editingTeacherId === $teacher->id)
                            <td colspan="3">
                                <form wire:submit="updateTeacher" class="flex flex-wrap items-center gap-2">
                                    <input type="text" wire:model="teacherName" class="field-input mt-0 py-1">
                                    <select wire:model="userId" class="field-input mt-0 py-1">
                                        <option value="">不連結帳號</option>
                                        @foreach ($availableUsers as $user)
                                            <option value="{{ $user->id }}">{{ $user->name }}（{{ $user->username }}）</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn-primary btn-xs">儲存</button>
                                    <button type="button" wire:click="cancelEdit" class="btn-secondary btn-xs">取消</button>
                                </form>
                            </td>
                        @else
                            <td>{{ $teacher->teacher_name }}</td>
                            <td>{{ $teacher->user?->username ?? '—' }}</td>
                            <td>
                                <div class="action-group">
                                    <button type="button" wire:click="startEdit({{ $teacher->id }})" class="btn-secondary btn-xs">
                                        編輯
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
