<?php

namespace App\Livewire\Concerns;

use Closure;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * 表格欄位排序（點標題切換升冪／降冪，見 <x-sort-header>）。
 *
 * 必須跟 Livewire\WithPagination 一起使用——換排序時要回到第一頁，
 * 不然使用者會停在「新排序下的第 7 頁」，看起來像資料不見了。
 *
 * **$sortColumn／$sortDirection 是 public 屬性，也就是每一次 Livewire
 * 更新請求都可以被客戶端任意改寫**（同 CLAUDE.md 記載的 Recorder::$statuses
 * 問題）。所以這兩個值絕對不能直接串進 SQL：排序目標一律透過
 * sortableColumns() 這張白名單查表換成真正的欄位，查不到就退回預設欄位；
 * 方向也只認 'desc'，其餘一律當成 'asc'。少了這層，一個 orderBy 就是
 * SQL injection。
 */
trait SortsColumns
{
    public string $sortColumn = '';

    public string $sortDirection = 'asc';

    /**
     * 可以排序的欄位白名單：畫面上用的 key => 實際排序方式。
     *
     * 值可以是欄位名稱字串，也可以是 Closure(Builder $query, string $direction)
     * ——後者給「顯示值不是單一欄位」的情況用（例如姓名要先看有沒有
     * 連結帳號、身分要透過 spatie 的樞紐表）。
     *
     * @return array<string, string|Closure>
     */
    abstract protected function sortableColumns(): array;

    /**
     * 使用者還沒點過任何標題時，預設用哪個 key 排序。
     */
    abstract protected function defaultSortColumn(): string;

    /**
     * 目前實際生效的排序欄位。$sortColumn 為空字串（初始狀態）或被塞了
     * 白名單以外的值時，一律退回預設欄位——畫面上的三角形跟實際查詢
     * 都讀這個方法，兩者不會不同步。
     */
    public function activeSortColumn(): string
    {
        return array_key_exists($this->sortColumn, $this->sortableColumns())
            ? $this->sortColumn
            : $this->defaultSortColumn();
    }

    public function activeSortDirection(): string
    {
        return $this->sortDirection === 'desc' ? 'desc' : 'asc';
    }

    /**
     * 點同一個欄位標題就在升冪／降冪之間切換，點別的欄位則換過去並從
     * 升冪開始。白名單外的 key 直接忽略（不是丟例外）——這個方法是
     * wire:click 的目標，可以被直接呼叫任意參數，安靜地不動作就好。
     */
    public function sortBy(string $column): void
    {
        if (! array_key_exists($column, $this->sortableColumns())) {
            return;
        }

        if ($this->activeSortColumn() === $column) {
            $this->sortDirection = $this->activeSortDirection() === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    protected function applySort(Builder $query): Builder
    {
        $target = $this->sortableColumns()[$this->activeSortColumn()];
        $direction = $this->activeSortDirection();

        return $target instanceof Closure
            ? $target($query, $direction)
            : $query->orderBy($target, $direction);
    }
}
