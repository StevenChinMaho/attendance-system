{{--
    專案自己的分頁列，覆寫 Livewire 內建的 livewire::tailwind。

    為什麼是「覆寫這個檔名」而不是在元件裡宣告 paginationView()：
    Livewire 的 SupportPagination 解析順序是「元件有沒有 paginationView()
    方法 → 否則 livewire::{theme}」，而 livewire::* 這個命名空間是用
    loadViewsFrom() 註冊的，Laravel 對這種命名空間一律優先採用
    resources/views/vendor/{namespace}/ 底下的同名檔案。放在這裡的好處是
    「所有分頁一定長一樣」——以後新增的分頁元件不必記得多 use 一個 trait
    或多宣告一個方法，忘了就會冒出一個風格不同的分頁列。

    跟內建版本的差別（都是實際回報過的問題）：
      * 內建用 gray-* 色階，本專案一律用 slate-*，兩者放在一起色調明顯
        對不上，看起來像別的系統的元件。這裡改用 app.css 定義的
        .pagination-* 元件類別，跟其他頁面共用同一套設計。
      * 內建整個包在 @if ($paginator->hasPages()) 裡，只有一頁時什麼都
        不畫——連「總共幾筆」都看不到。這裡把筆數摘要拉到條件外面，
        永遠顯示。
      * 內建沒有任何「現在第幾頁／共幾頁」的資訊，只有一排頁碼按鈕；
        資料一多（全校八百多名學生）頁碼就會擠成一長串。改成「第 N / M 頁」
        加上首頁/上一頁/下一頁/末頁四顆按鈕，翻到底時按鈕會變成明確的
        停用樣式（見 app.css 的 .pagination-btn:disabled）。
--}}
@php
    // 這個變數是 Livewire 呼叫 links() 時可以傳進來的（links(data: ['scrollTo' => ...])），
    // 沿用內建版本的行為：預設翻頁後把 body 捲回頂端。
    if (! isset($scrollTo)) {
        $scrollTo = 'body';
    }

    $scrollIntoViewJsSnippet = ($scrollTo !== false)
        ? "(\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()"
        : '';

    $pageName = $paginator->getPageName();
    $currentPage = $paginator->currentPage();
    $lastPage = $paginator->lastPage();
@endphp

<nav role="navigation" aria-label="分頁導覽" class="pagination">
    <p class="pagination-summary">
        @if ($paginator->total() === 0)
            沒有符合的資料
        @else
            第 {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} 筆，共 {{ $paginator->total() }} 筆
        @endif
    </p>

    {{-- 只有一頁時不需要翻頁按鈕，但上面的筆數摘要仍然會顯示。 --}}
    @if ($paginator->hasPages())
        <div class="pagination-controls">
            <button
                type="button"
                wire:click="gotoPage(1, '{{ $pageName }}')"
                x-on:click="{{ $scrollIntoViewJsSnippet }}"
                wire:loading.attr="disabled"
                @disabled($paginator->onFirstPage())
                class="pagination-btn"
                aria-label="第一頁"
                title="第一頁"
            >&laquo;</button>

            <button
                type="button"
                wire:click="previousPage('{{ $pageName }}')"
                x-on:click="{{ $scrollIntoViewJsSnippet }}"
                wire:loading.attr="disabled"
                @disabled($paginator->onFirstPage())
                class="pagination-btn"
                aria-label="上一頁"
            >&lsaquo; 上一頁</button>

            <span class="pagination-status" aria-live="polite">
                第 {{ $currentPage }} / {{ $lastPage }} 頁
            </span>

            <button
                type="button"
                wire:click="nextPage('{{ $pageName }}')"
                x-on:click="{{ $scrollIntoViewJsSnippet }}"
                wire:loading.attr="disabled"
                @disabled(! $paginator->hasMorePages())
                class="pagination-btn"
                aria-label="下一頁"
            >下一頁 &rsaquo;</button>

            <button
                type="button"
                wire:click="gotoPage({{ $lastPage }}, '{{ $pageName }}')"
                x-on:click="{{ $scrollIntoViewJsSnippet }}"
                wire:loading.attr="disabled"
                @disabled(! $paginator->hasMorePages())
                class="pagination-btn"
                aria-label="最後一頁"
                title="最後一頁"
            >&raquo;</button>
        </div>
    @endif
</nav>
