<div class="mx-auto max-w-4xl px-4 py-10">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold text-slate-900">班級管理</h1>
            <p class="mt-1 text-xs text-slate-500">
                顯示範圍：{{ \App\Support\AcademicPeriod::label($selectedAcademicYear, $selectedSemester) }}，要看別的學年度請用上方導覽列的切換選單。
            </p>
        </div>
        <button
            type="button"
            wire:click="$toggle('showCreateForm')"
            class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700"
        >
            {{ $showCreateForm ? '取消' : '新增班級' }}
        </button>
    </div>

    @if (session('status'))
        <div class="mt-4 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    @if ($showCreateForm)
        <form wire:submit="createClass" class="mt-6 grid grid-cols-2 gap-4 rounded-lg border border-slate-200 bg-white p-6">
            <div>
                <label class="block text-sm font-medium text-slate-700">學年度（民國年）</label>
                {{-- 新增班級的學年度／學期鎖定為目前選取的範圍，不接受自由
                     輸入（沒有 wire:model，client 端也就沒有管道竄改）——
                     要建到別的學年度，先用上方導覽列切換再新增。 --}}
                <input type="text" value="{{ $selectedAcademicYear }}" disabled class="mt-1 block w-full rounded-md border border-slate-200 bg-slate-100 px-3 py-2 text-sm text-slate-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700">學期</label>
                <input type="text" value="{{ \App\Support\AcademicPeriod::semesterOptions()[$selectedSemester] ?? '' }}" disabled class="mt-1 block w-full rounded-md border border-slate-200 bg-slate-100 px-3 py-2 text-sm text-slate-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700">年級</label>
                <select wire:model="grade" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="">請選擇</option>
                    <option value="1">一年級</option>
                    <option value="2">二年級</option>
                    <option value="3">三年級</option>
                </select>
                @error('grade') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700">班級代號</label>
                <input type="text" wire:model="classNumber" placeholder="例如：1" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                @error('classNumber') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="col-span-2">
                <label class="block text-sm font-medium text-slate-700">導師（選填，之後可再指派）</label>
                <select wire:model="homeroomTeacherId" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="">尚未指派</option>
                    @foreach ($teachers as $teacher)
                        <option value="{{ $teacher->id }}">{{ $teacher->teacher_name }}</option>
                    @endforeach
                </select>
                @error('homeroomTeacherId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
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
                <th class="px-4 py-2">班級</th>
                <th class="px-4 py-2">導師</th>
                <th class="px-4 py-2">學生人數</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($classes as $class)
                <tr>
                    @if ($editingClassId === $class->id)
                        <td class="px-4 py-2" colspan="4">
                            <form wire:submit="updateClass" class="flex flex-wrap items-center gap-2">
                                <input type="number" wire:model="academicYear" class="w-20 rounded-md border border-slate-300 px-2 py-1 text-sm">
                                <select wire:model="semester" class="rounded-md border border-slate-300 px-2 py-1 text-sm">
                                    <option value="1">上學期</option>
                                    <option value="2">下學期</option>
                                </select>
                                <select wire:model="grade" class="rounded-md border border-slate-300 px-2 py-1 text-sm">
                                    <option value="1">一年級</option>
                                    <option value="2">二年級</option>
                                    <option value="3">三年級</option>
                                </select>
                                <input type="text" wire:model="classNumber" class="w-20 rounded-md border border-slate-300 px-2 py-1 text-sm">
                                <select wire:model="homeroomTeacherId" class="rounded-md border border-slate-300 px-2 py-1 text-sm">
                                    <option value="">尚未指派</option>
                                    @foreach ($teachers as $teacher)
                                        <option value="{{ $teacher->id }}">{{ $teacher->teacher_name }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="text-xs text-emerald-700 underline">儲存</button>
                                <button type="button" wire:click="cancelEdit" class="text-xs text-slate-500 underline">取消</button>
                            </form>
                            @error('classNumber') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </td>
                    @else
                        <td class="px-4 py-2">{{ $class->shortLabel() }}</td>
                        <td class="px-4 py-2">{{ $class->homeroomTeacher?->teacher_name ?? '尚未指派' }}</td>
                        <td class="px-4 py-2">{{ $class->students->count() }}</td>
                        <td class="px-4 py-2 text-right space-x-3">
                            <a href="{{ route('admin.classes.students', $class) }}" class="text-xs text-slate-600 underline hover:text-slate-900">
                                管理學生
                            </a>
                            <a href="{{ route('attendance.show', $class) }}" class="text-xs text-slate-600 underline hover:text-slate-900">
                                點名
                            </a>
                            <button type="button" wire:click="startEdit({{ $class->id }})" class="text-xs text-slate-600 underline hover:text-slate-900">
                                編輯
                            </button>
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="mt-4">
        {{ $classes->links() }}
    </div>
</div>
