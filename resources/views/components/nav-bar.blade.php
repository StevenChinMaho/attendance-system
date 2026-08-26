@php
    $linkClass = fn (bool $active) => $active
        ? 'text-sm font-medium text-slate-900 dark:text-white'
        : 'text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white';
@endphp

{{-- print:hidden：導覽列在紙本上沒有意義（連結按不了、還占掉版面）
     ——目前會被列印的是「上午缺席詳細清單」，但這條規則對任何頁面
     被列印時都成立，所以放在共用的導覽列本身而不是那一頁。 --}}
<nav class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 print:hidden">
    <div class="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-3 px-4 py-3">
        <div class="flex flex-wrap items-center gap-6">
            <a href="{{ route('dashboard') }}" class="text-sm font-semibold text-slate-900 dark:text-white">
                國中點名系統
            </a>

            {{-- 學生、導師、管理者共用同一套「點名」快捷入口，管理者
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

            {{-- 給學務處列印的上午缺席名單，跟即時看板同一個權限（全校
                 範圍的檢視），所以擺在點名入口後面、後台連結前面。 --}}
            @can('attendance.dashboard.view')
                <a href="{{ route('attendance.morning-absences') }}" class="{{ $linkClass(request()->routeIs('attendance.morning-absences')) }}">
                    上午缺席清單
                </a>
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
            @can('students.manage')
                <a href="{{ route('admin.students') }}" class="{{ $linkClass(request()->routeIs('admin.students')) }}">
                    學生管理
                </a>
            @endcan
            @can('classes.manage')
                <a href="{{ route('admin.classes') }}" class="{{ $linkClass(request()->routeIs('admin.classes*')) }}">
                    班級管理
                </a>
            @endcan
            @can('roles.manage')
                <a href="{{ route('admin.roles') }}" class="{{ $linkClass(request()->routeIs('admin.roles')) }}">
                    身分管理
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

            {{-- 每個帳號都能改自己的密碼，不需要任何權限——不是只有被
                 強制改密碼（見 App\Http\Middleware\
                 EnsureUserHasChangedPassword）時才進得來。 --}}
            <a href="{{ route('account.password') }}" class="{{ $linkClass(request()->routeIs('account.password')) }}">
                變更密碼
            </a>

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
