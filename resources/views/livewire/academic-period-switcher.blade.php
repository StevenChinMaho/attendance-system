<div class="flex items-center gap-1">
    <select wire:model.live="year" class="field-input mt-0 w-auto py-1">
        @foreach ($yearOptions as $option)
            <option value="{{ $option }}">{{ $option }}學年度</option>
        @endforeach
    </select>
    <select wire:model.live="semester" class="field-input mt-0 w-auto py-1">
        @foreach ($semesterOptions as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
    </select>
</div>
