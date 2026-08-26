{{-- Livewire 元件要求 render() 回傳的畫面一定要有單一根節點，即使
     「沒有班級可選」時什麼都不顯示，也要留著這個外層 div。 --}}
<div>
    @if ($classes->count() > 1)
        {{-- 按鈕本身跟只有一個班級時的「點名」連結共用同一組樣式，看
             起來要一模一樣，多出來的只是點下去會展開班級清單，而不是
             原本那個看起來像表單欄位的 <select>——那個樣式跟旁邊其他
             純文字的 nav bar 項目（帳號管理、班級管理…）視覺上很不
             搭。x-on:click.outside 跟 Escape 都會收合選單，比較貼近
             一般下拉選單的操作習慣。 --}}
        <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
            <button
                type="button"
                x-on:click="open = !open"
                x-on:click.outside="open = false"
                class="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white"
            >
                點名
            </button>

            <div
                x-show="open"
                x-cloak
                x-transition
                x-on:click="open = false"
                class="surface absolute left-0 z-20 mt-2 w-40 space-y-0.5 p-1 shadow-lg"
            >
                @foreach ($classes as $class)
                    <a
                        href="{{ route('attendance.show', $class) }}"
                        class="block rounded-md px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
                    >
                        {{ $class->shortLabel() }}
                    </a>
                @endforeach
            </div>
        </div>
    @elseif ($classes->count() === 1)
        <a href="{{ route('attendance.show', $classes->first()) }}" class="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">
            點名
        </a>
    @elseif (! auth()->user()->can('classes.manage'))
        {{-- 學生/導師即使目前選取的學年度／學期沒有帶班，也維持顯示
             這個連結，走 GoToMyClassAttendanceController 給出「可以切換
             學年度」的提示，而不是整個項目直接消失讓人以為系統壞了。
             can('classes.manage') 的帳號（admin 或被賦予這個權限的自訂
             身分）沒有這種「應該有一個預設班級」的期待，沒有班級可選
             就不顯示——理由跟上面 render() 用同一個判斷依據一致。 --}}
        <a href="{{ route('attendance.mine') }}" class="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">
            點名
        </a>
    @endif
</div>
