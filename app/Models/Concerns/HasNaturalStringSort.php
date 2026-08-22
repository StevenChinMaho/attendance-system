<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * class_number（SchoolClass）、seat_number（Student）都是字串欄位
 * （保留容納非數字代號的彈性，見各自 migration 的註解），但直接
 * orderBy 會讓 "10" 排到 "2" 前面。兩個 model 原本各自重複實作一樣的
 * 「先按長度排、再按字典序」手法，收斂在這裡避免以後只改到一邊。
 */
trait HasNaturalStringSort
{
    protected function scopeNaturalSortBy(Builder $query, string $column): Builder
    {
        return $query->orderByRaw("LENGTH({$column}) asc")->orderBy($column);
    }
}
