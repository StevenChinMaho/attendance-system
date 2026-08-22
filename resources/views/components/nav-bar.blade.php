@php
    $linkClass = fn (bool $active) => $active
        ? 'text-sm font-medium text-slate-900'
        : 'text-sm text-slate-500 hover:text-slate-900';
@endphp

<nav class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-4xl items-center justify-between px-4 py-3">
        <div class="flex items-center gap-6">
            <a href="{{ route('dashboard') }}" class="text-sm font-semibold text-slate-900">
                國中點名系統
            </a>

            @hasanyrole('student_rep|homeroom_teacher')
                @php $ownClasses = auth()->user()->ownSchoolClasses(); @endphp
                @if ($ownClasses->count() > 1)
                    {{-- 帳號名下有不只一個班（同時或跨學期帶過不只一班）——見
                         App\Models\User::ownSchoolClasses()，改成下拉選單讓
                         使用者自己選要管理哪一班，不能再固定顯示某一班。 --}}
                    <select
                        onchange="if (this.value) { window.location.href = this.value; }"
                        class="rounded-md border border-slate-300 bg-white px-2 py-1 text-sm text-slate-700"
                    >
                        <option value="">點名（選擇班級）</option>
                        @foreach ($ownClasses as $ownClass)
                            <option value="{{ route('attendance.show', $ownClass) }}">
                                {{ $ownClass->label() }}
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

        <div class="flex items-center gap-4">
            {{-- 學年度／學期是全站最高層級的篩選（見 system_structure.md
                 學年制度），只有看得到「隨學年度變動的內容」（班級管理、
                 即時看板）的角色才需要切換它。 --}}
            @hasanyrole('homeroom_teacher|admin')
                <livewire:academic-period-switcher />
            @endhasanyrole

            <span class="text-sm text-slate-500">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-sm text-slate-500 underline hover:text-slate-900">
                    登出
                </button>
            </form>
        </div>
    </div>
</nav>
