<div class="flex items-center gap-1.5">
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

    {{-- 選取範圍通常只在寒暑假才會變、切換後容易放著忘記，導致看著別的
         學期資料卻誤以為是本學期——見 App\Support\AcademicPeriod::
         isSelectedCurrent()。這個提示故意放在選單正右邊、不是掛在
         hover 才看得到的 title，切換到非本學期的那一刻就要顯眼。 --}}
    @unless (\App\Support\AcademicPeriod::isSelectedCurrent())
        <span class="badge-warning whitespace-nowrap" title="現在實際上是 {{ \App\Support\AcademicPeriod::label(\App\Support\AcademicPeriod::currentYear(), \App\Support\AcademicPeriod::currentSemester()) }}">
            ⚠ 非本學期
        </span>
    @endunless
</div>
