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
                 App\Livewire\AttendanceQuickLink。點名相關的每個連結
                 都改成看實際的頁面級 permission（見 database/seeders/
                 RolePermissionSeeder.php、App\Livewire\Admin\RoleManager），
                 不是寫死的角色名稱，這樣管理者透過 /admin/roles 新增的
                 自訂角色，開了哪個權限，nav bar 才會對應出現哪個連結
                 ——只改路由的 middleware 而不改這裡，會出現「網址打得
                 進去但完全沒有連結能點進去」的情形。 --}}
            @can('attendance.record')
                <livewire:attendance-quick-link />
            @endcan

            @can('users.manage')
                <a href="{{ route('admin.users') }}" class="{{ $linkClass(request()->routeIs('admin.users')) }}">
                    帳號管理
                </a>
            @endcan
            @can('teachers.manage')
                <a href="{{ route('admin.teachers') }}" class="{{ $linkClass(request()->routeIs('admin.teachers')) }}">
                    教師管理
                </a>
            @endcan
            @can('classes.manage')
                <a href="{{ route('admin.classes') }}" class="{{ $linkClass(request()->routeIs('admin.classes*')) }}">
                    班級管理
                </a>
            @endcan
            @can('roles.manage')
                <a href="{{ route('admin.roles') }}" class="{{ $linkClass(request()->routeIs('admin.roles')) }}">
                    角色管理
                </a>
            @endcan
        </div>

        <div class="flex items-center gap-3">
            {{-- 學年度／學期是全站最高層級的篩選（見 system_structure.md
                 學年制度），只有看得到「隨學年度變動的內容」（班級管理、
                 即時看板）的角色才需要切換它——同樣改成看 permission，
                 理由同上。 --}}
            @canany(['classes.manage', 'attendance.dashboard.view'])
                <livewire:academic-period-switcher />
            @endcanany

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
