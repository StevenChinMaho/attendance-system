<div class="mx-auto max-w-4xl px-4 py-10">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="page-title">班級管理</h1>
            <p class="page-subtitle mt-1">
                顯示範圍：{{ \App\Support\AcademicPeriod::label($selectedAcademicYear, $selectedSemester) }}，要看別的學年度請用上方導覽列的切換選單。
            </p>
        </div>
        <button type="button" wire:click="toggleCreateForm" class="btn-primary">
            {{ $showCreateForm ? '取消' : '新增班級' }}
        </button>
    </div>

    @if (session('status'))
        <div class="alert-success mt-4">
            {{ session('status') }}
        </div>
    @endif

    @if ($showCreateForm)
        <form wire:submit="createClass" class="surface mt-6 grid grid-cols-2 gap-4 p-6">
            <div>
                <label class="field-label">學年度（民國年）</label>
                {{-- 新增班級的學年度／學期鎖定為目前選取的範圍，不接受自由
                     輸入（沒有 wire:model，client 端也就沒有管道竄改）——
                     要建到別的學年度，先用上方導覽列切換再新增。 --}}
                <input type="text" value="{{ $selectedAcademicYear }}" disabled class="field-input bg-slate-100 text-slate-500 dark:bg-slate-900 dark:text-slate-500">
            </div>

            <div>
                <label class="field-label">學期</label>
                <input type="text" value="{{ \App\Support\AcademicPeriod::semesterOptions()[$selectedSemester] ?? '' }}" disabled class="field-input bg-slate-100 text-slate-500 dark:bg-slate-900 dark:text-slate-500">
            </div>

            <div>
                <label class="field-label">年級</label>
                <select wire:model="grade" class="field-input">
                    <option value="">請選擇</option>
                    <option value="1">一年級</option>
                    <option value="2">二年級</option>
                    <option value="3">三年級</option>
                </select>
                @error('grade') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label">班級代號</label>
                <input type="text" wire:model="classNumber" placeholder="例如：1" class="field-input">
                @error('classNumber') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="col-span-2">
                <label class="field-label">導師（選填，之後可再指派）</label>
                <select wire:model="homeroomTeacherId" class="field-input">
                    <option value="">尚未指派</option>
                    @foreach ($teachers as $teacher)
                        <option value="{{ $teacher->id }}">{{ $teacher->teacher_name }}</option>
                    @endforeach
                </select>
                @error('homeroomTeacherId') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="col-span-2">
                <button type="submit" class="btn-primary">
                    建立
                </button>
            </div>
        </form>
    @endif

    <div class="table-wrap mt-6">
        <table class="data-table">
            <colgroup>
                <col style="width: 25%">
                <col style="width: 25%">
                <col style="width: 15%">
                <col style="width: 35%">
            </colgroup>
            <thead>
                <tr>
                    <th>班級</th>
                    <th>導師</th>
                    <th>學生人數</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($classes as $class)
                    <tr>
                        @if ($editingClassId === $class->id)
                            <td colspan="4">
                                <form wire:submit="updateClass" class="flex flex-wrap items-center gap-2">
                                    <input type="number" wire:model="academicYear" class="field-input mt-0 w-20 py-1">
                                    <select wire:model="semester" class="field-input mt-0 py-1">
                                        <option value="1">上學期</option>
                                        <option value="2">下學期</option>
                                    </select>
                                    <select wire:model="grade" class="field-input mt-0 py-1">
                                        <option value="1">一年級</option>
                                        <option value="2">二年級</option>
                                        <option value="3">三年級</option>
                                    </select>
                                    <input type="text" wire:model="classNumber" class="field-input mt-0 w-20 py-1">
                                    <select wire:model="homeroomTeacherId" class="field-input mt-0 py-1">
                                        <option value="">尚未指派</option>
                                        @foreach ($teachers as $teacher)
                                            <option value="{{ $teacher->id }}">{{ $teacher->teacher_name }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn-primary btn-xs">儲存</button>
                                    <button type="button" wire:click="cancelEdit" class="btn-secondary btn-xs">取消</button>
                                </form>
                                @error('classNumber') <p class="field-error">{{ $message }}</p> @enderror
                            </td>
                        @else
                            <td>{{ $class->shortLabel() }}</td>
                            <td>{{ $class->homeroomTeacher?->teacher_name ?? '尚未指派' }}</td>
                            <td>{{ $class->students->count() }}</td>
                            <td>
                                <div class="action-group">
                                    <a href="{{ route('admin.classes.students', $class) }}" class="btn-secondary btn-xs">
                                        管理學生
                                    </a>
                                    <button type="button" wire:click="startEdit({{ $class->id }})" class="btn-secondary btn-xs">
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
        {{ $classes->links() }}
    </div>
</div>
