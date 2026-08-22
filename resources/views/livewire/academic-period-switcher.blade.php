<div class="flex items-center gap-1">
    <select wire:model.live="year" class="rounded-md border border-slate-300 bg-white px-2 py-1 text-sm text-slate-700">
        @foreach ($yearOptions as $option)
            <option value="{{ $option }}">{{ $option }}學年度</option>
        @endforeach
    </select>
    <select wire:model.live="semester" class="rounded-md border border-slate-300 bg-white px-2 py-1 text-sm text-slate-700">
        @foreach ($semesterOptions as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
    </select>
</div>
