<x-layouts.app title="首頁 - 國中點名系統">
    <div class="mx-auto max-w-2xl px-4 py-10">
        <h1 class="text-lg font-semibold text-slate-900">登入成功</h1>
        <p class="mt-1 text-sm text-slate-500">{{ auth()->user()->name }}，歡迎回來。</p>

        @unless ($errors->isEmpty())
            <div class="mt-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ $errors->first() }}
            </div>
        @endunless

        <p class="mt-6 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
            上方導覽列可以直接切換功能。
        </p>
    </div>
</x-layouts.app>
