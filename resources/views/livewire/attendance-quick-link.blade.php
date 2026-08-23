{{-- Livewire 元件要求 render() 回傳的畫面一定要有單一根節點，即使
     「沒有班級可選」時什麼都不顯示，也要留著這個外層 div。 --}}
<div>
    @if ($classes->count() > 1)
        <select
            onchange="if (this.value) { window.location.href = this.value; }"
            class="field-input mt-0 w-auto py-1"
        >
            <option value="">點名（選擇班級）</option>
            @foreach ($classes as $class)
                <option value="{{ route('attendance.show', $class) }}">
                    {{ $class->shortLabel() }}
                </option>
            @endforeach
        </select>
    @elseif ($classes->count() === 1)
        <a href="{{ route('attendance.show', $classes->first()) }}" class="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">
            點名
        </a>
    @elseif (! auth()->user()->hasRole('admin'))
        {{-- 副班長/導師即使目前選取的學年度／學期沒有帶班，也維持顯示
             這個連結，走 GoToMyClassAttendanceController 給出「可以切換
             學年度」的提示，而不是整個項目直接消失讓人以為系統壞了。
             管理者沒有這種「應該有一個預設班級」的期待，沒有班級可選
             就不顯示。 --}}
        <a href="{{ route('attendance.mine') }}" class="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">
            點名
        </a>
    @endif
</div>
