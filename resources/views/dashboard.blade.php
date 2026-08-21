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

        @role('admin')
            <a href="{{ route('admin.users') }}" class="mt-4 inline-block text-sm text-slate-700 underline hover:text-slate-900">
                帳號管理 →
            </a>
        @endrole
    </div>
</x-layouts.app>
