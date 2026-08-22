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
                <a href="{{ route('attendance.mine') }}" class="{{ $linkClass(request()->routeIs('attendance.*')) }}">
                    點名
                </a>
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
