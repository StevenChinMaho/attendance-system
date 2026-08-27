{{--
    可排序的表格欄位標題。搭配 App\Livewire\Concerns\SortsColumns 使用：

        <x-sort-header column="name" :active="$activeSortColumn" :direction="$activeSortDirection">
            姓名
        </x-sort-header>

    上下兩個三角形永遠都畫，只有作用中的那一個是強調色——理由見
    app.css 的 .sort-indicator：使用者不必先把游標移過去，才知道哪些
    欄位可以點。
--}}
@props(['column', 'active', 'direction'])

@php
    $isActive = $active === $column;
@endphp

<th {{ $attributes }} @if ($isActive) aria-sort="{{ $direction === 'asc' ? 'ascending' : 'descending' }}" @endif>
    <button
        type="button"
        wire:click="sortBy('{{ $column }}')"
        class="sort-header"
        title="點擊依此欄位排序"
    >
        <span>{{ $slot }}</span>
        <span class="sort-indicator" aria-hidden="true">
            <span @class(['sort-indicator-active' => $isActive && $direction === 'asc'])>&#9650;</span>
            <span @class(['sort-indicator-active' => $isActive && $direction === 'desc'])>&#9660;</span>
        </span>
    </button>
</th>
