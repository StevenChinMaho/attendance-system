@php
    $linkClass = fn (bool $active) => $active
        ? 'text-sm font-medium text-slate-900 dark:text-white'
        : 'text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white';
@endphp

<nav class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <div class="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-3 px-4 py-3">
        <div class="flex flex-wrap items-center gap-6">
            <a href="{{ route('dashboard') }}" class="text-sm font-semibold text-slate-900 dark:text-white">
                國中點名系統
            </a>

            @hasanyrole('student_rep|homeroom_teacher')
                @php
                    // 只列出「目前選取」學年度／學期裡的班級——這個選單是
                    // 「我現在要點哪一班」的快捷方式，不是這個帳號歷史上
                    // 管過的所有班級（那些仍可透過網址直接存取，見
                    // SchoolClassPolicy），所以要跟著 nav bar 自己的學年度
                    // 篩選走，不然選單裡會混進已經凍結的舊學期班級。
                    $ownClasses = auth()->user()->ownSchoolClasses()
                        ->where('academic_year', \App\Support\AcademicPeriod::selectedYear())
                        ->where('semester', \App\Support\AcademicPeriod::selectedSemester());
                @endphp
                @if ($ownClasses->count() > 1)
                    <select
                        onchange="if (this.value) { window.location.href = this.value; }"
                        class="field-input mt-0 w-auto py-1"
                    >
                        <option value="">點名（選擇班級）</option>
                        @foreach ($ownClasses as $ownClass)
                            <option value="{{ route('attendance.show', $ownClass) }}">
                                {{ $ownClass->shortLabel() }}
                            </option>
                        @endforeach
                    </select>
                @else
                    <a href="{{ route('attendance.mine') }}" class="{{ $linkClass(request()->routeIs('attendance.*')) }}">
                        點名
                    </a>
                @endif
            @endhasanyrole

            @role('admin')
                <a href="{{ route('admin.users') }}" class="{{ $linkClass(request()->routeIs('admin.users')) }}">
                    帳號管理
                </a>
                <a href="{{ route('admin.teachers') }}" class="{{ $linkClass(request()->routeIs('admin.teachers')) }}">
                    教師管理
                </a>
                <a href="{{ route('admin.classes') }}" class="{{ $linkClass(request()->routeIs('admin.classes*')) }}">
                    班級管理
                </a>
            @endrole
        </div>

        <div class="flex items-center gap-3">
            {{-- 學年度／學期是全站最高層級的篩選（見 system_structure.md
                 學年制度），只有看得到「隨學年度變動的內容」（班級管理、
                 即時看板）的角色才需要切換它。 --}}
            @hasanyrole('homeroom_teacher|admin')
                <livewire:academic-period-switcher />
            @endhasanyrole

            <x-theme-toggle />

            <span class="text-sm text-slate-500 dark:text-slate-400">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-sm text-slate-500 underline hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">
                    登出
                </button>
            </form>
        </div>
    </div>
</nav>
