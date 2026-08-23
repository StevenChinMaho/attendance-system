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

            {{-- 副班長、導師、管理者共用同一套「點名」快捷入口，管理者
                 不再需要另外從「班級管理」列表點進去——見
                 App\Livewire\AttendanceQuickLink。 --}}
            @hasanyrole('student_rep|homeroom_teacher|admin')
                <livewire:attendance-quick-link />
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
