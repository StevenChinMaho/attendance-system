<div class="mx-auto max-w-4xl px-4 py-10">
    <a href="{{ route('admin.classes') }}" class="text-xs text-slate-500 underline hover:text-slate-700">← 回班級列表</a>

    <div class="mt-2 flex items-center justify-between">
        <h1 class="text-lg font-semibold text-slate-900">{{ $schoolClass->shortLabel() }} 學生管理</h1>
        <button
            type="button"
            wire:click="$toggle('showCreateForm')"
            class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700"
        >
            {{ $showCreateForm ? '取消' : '新增學生' }}
        </button>
    </div>

    @if (session('status'))
        <div class="mt-4 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    @if ($showCreateForm)
        <form wire:submit="createStudent" class="mt-6 grid grid-cols-2 gap-4 rounded-lg border border-slate-200 bg-white p-6">
            <div>
                <label class="block text-sm font-medium text-slate-700">學號</label>
                <input type="text" wire:model="studentNumber" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                @error('studentNumber') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700">座號</label>
                <input type="text" wire:model="seatNumber" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                @error('seatNumber') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700">姓名</label>
                <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700">性別</label>
                <select wire:model="gender" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="">請選擇</option>
                    <option value="男">男</option>
                    <option value="女">女</option>
                </select>
                @error('gender') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="col-span-2">
                <label class="block text-sm font-medium text-slate-700">連結登入帳號（選填，副班長才需要）</label>
                <select wire:model="userId" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="">不連結帳號</option>
                    @foreach ($availableUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}（{{ $user->username }}）</option>
                    @endforeach
                </select>
            </div>

            <div class="col-span-2">
                <button type="submit" class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">
                    建立
                </button>
            </div>
        </form>
    @endif

    <table class="mt-6 w-full overflow-hidden rounded-lg border border-slate-200 bg-white text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
            <tr>
                <th class="px-4 py-2">座號</th>
                <th class="px-4 py-2">學號</th>
                <th class="px-4 py-2">姓名</th>
                <th class="px-4 py-2">性別</th>
                <th class="px-4 py-2">登入帳號</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($students as $student)
                <tr>
                    @if ($editingStudentId === $student->id)
                        <td class="px-4 py-2" colspan="6">
                            <form wire:submit="updateStudent" class="flex flex-wrap items-center gap-2">
                                <input type="text" wire:model="seatNumber" class="w-16 rounded-md border border-slate-300 px-2 py-1 text-sm">
                                <input type="text" wire:model="studentNumber" class="w-24 rounded-md border border-slate-300 px-2 py-1 text-sm">
                                <input type="text" wire:model="name" class="rounded-md border border-slate-300 px-2 py-1 text-sm">
                                <select wire:model="gender" class="rounded-md border border-slate-300 px-2 py-1 text-sm">
                                    <option value="男">男</option>
                                    <option value="女">女</option>
                                </select>
                                <select wire:model="userId" class="rounded-md border border-slate-300 px-2 py-1 text-sm">
                                    <option value="">不連結帳號</option>
                                    @foreach ($availableUsers as $user)
                                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="text-xs text-emerald-700 underline">儲存</button>
                                <button type="button" wire:click="cancelEdit" class="text-xs text-slate-500 underline">取消</button>
                            </form>
                            @error('studentNumber') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            @error('seatNumber') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </td>
                    @else
                        <td class="px-4 py-2">{{ $student->seat_number }}</td>
                        <td class="px-4 py-2">{{ $student->student_number }}</td>
                        <td class="px-4 py-2">{{ $student->name }}</td>
                        <td class="px-4 py-2">{{ $student->gender }}</td>
                        <td class="px-4 py-2">{{ $student->user?->username ?? '—' }}</td>
                        <td class="px-4 py-2 text-right">
                            <button type="button" wire:click="startEdit({{ $student->id }})" class="text-xs text-slate-600 underline hover:text-slate-900">
                                編輯
                            </button>
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
