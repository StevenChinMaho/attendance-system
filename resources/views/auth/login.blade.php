<x-layouts.app title="登入 - 國中點名系統">
    <div class="flex min-h-screen items-center justify-center px-4">
        <div class="w-full max-w-sm surface p-8 shadow-sm">
            <h1 class="page-title">國中點名系統</h1>
            <p class="page-subtitle mt-1">請使用學校配發的帳號登入。</p>

            @if ($errors->any())
                <div class="alert-error mt-4">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ url('/login') }}" class="mt-6 space-y-4">
                @csrf

                <div>
                    <label for="username" class="field-label">帳號</label>
                    <input
                        id="username"
                        type="text"
                        name="username"
                        value="{{ old('username') }}"
                        required
                        autofocus
                        autocomplete="username"
                        class="field-input"
                    >
                </div>

                <div>
                    <label for="password" class="field-label">密碼</label>
                    <input
                        id="password"
                        type="password"
                        name="password"
                        required
                        autocomplete="current-password"
                        class="field-input"
                    >
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                    <input type="checkbox" name="remember" class="rounded border-slate-300 dark:border-slate-600">
                    記住我
                </label>

                <button type="submit" class="btn-primary w-full">
                    登入
                </button>
            </form>
        </div>
    </div>
</x-layouts.app>
