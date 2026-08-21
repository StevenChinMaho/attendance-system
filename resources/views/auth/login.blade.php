<x-layouts.app title="登入 - 國中點名系統">
    <div class="flex min-h-screen items-center justify-center px-4">
        <div class="w-full max-w-sm rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
            <h1 class="text-lg font-semibold text-slate-900">國中點名系統</h1>
            <p class="mt-1 text-sm text-slate-500">請使用學校配發的帳號登入。</p>

            @if ($errors->any())
                <div class="mt-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ url('/login') }}" class="mt-6 space-y-4">
                @csrf

                <div>
                    <label for="username" class="block text-sm font-medium text-slate-700">帳號</label>
                    <input
                        id="username"
                        type="text"
                        name="username"
                        value="{{ old('username') }}"
                        required
                        autofocus
                        autocomplete="username"
                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                    >
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700">密碼</label>
                    <input
                        id="password"
                        type="password"
                        name="password"
                        required
                        autocomplete="current-password"
                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                    >
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="remember" class="rounded border-slate-300">
                    記住我
                </label>

                <button
                    type="submit"
                    class="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
                >
                    登入
                </button>
            </form>
        </div>
    </div>
</x-layouts.app>
