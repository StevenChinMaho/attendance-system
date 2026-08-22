<x-layouts.app title="首頁 - 國中點名系統">
    <div class="mx-auto max-w-2xl px-4 py-10">
        <h1 class="page-title">登入成功</h1>
        <p class="page-subtitle mt-1">{{ auth()->user()->name }}，歡迎回來。</p>

        @unless ($errors->isEmpty())
            <div class="alert-error mt-4">
                {{ $errors->first() }}
            </div>
        @endunless

        <p class="alert-info mt-6">
            上方導覽列可以直接切換功能。
        </p>
    </div>
</x-layouts.app>
