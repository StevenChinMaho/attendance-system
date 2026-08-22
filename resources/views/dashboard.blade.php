<x-layouts.app title="首頁 - 國中點名系統">
    <div class="mx-auto max-w-2xl px-4 py-10">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-lg font-semibold text-slate-900">登入成功</h1>
                <p class="mt-1 text-sm text-slate-500">{{ auth()->user()->name }}，歡迎回來。</p>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100">
                    登出
                </button>
            </form>
        </div>

        <p class="mt-6 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
            這是暫時的登入後首頁，之後會依角色（副班長／導師／管理者）換成即時點名看板。
        </p>

        @unless ($errors->isEmpty())
            <div class="mt-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ $errors->first() }}
            </div>
        @endunless

        @hasanyrole('student_rep|homeroom_teacher')
            <a href="{{ route('attendance.mine') }}" class="mt-4 inline-block text-sm text-slate-700 underline hover:text-slate-900">
                去點名 →
            </a>
        @endhasanyrole

        @role('admin')
            <div class="mt-4 flex gap-4 text-sm">
                <a href="{{ route('admin.users') }}" class="text-slate-700 underline hover:text-slate-900">帳號管理 →</a>
                <a href="{{ route('admin.teachers') }}" class="text-slate-700 underline hover:text-slate-900">教師管理 →</a>
                <a href="{{ route('admin.classes') }}" class="text-slate-700 underline hover:text-slate-900">班級管理 →</a>
            </div>
        @endrole
    </div>
</x-layouts.app>
