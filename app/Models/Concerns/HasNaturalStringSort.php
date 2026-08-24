<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * seat_number（Student）是字串欄位（保留容納非數字代號的彈性，見
 * migration 的註解），但直接 orderBy 會讓 "10" 排到 "2" 前面，需要
 * 「先按長度排、再按字典序」的自然排序手法。
 *
 * class_number（SchoolClass）原本也用這個 trait，但已經改成整數欄位
 * （見 2026_08_24_234134_change_class_number_to_integer_on_school_classes_table
 * migration），整數天生排序正確，SchoolClass 已經不再需要這個 trait。
 */
trait HasNaturalStringSort
{
    protected function scopeNaturalSortBy(Builder $query, string $column): Builder
    {
        return $query->orderByRaw("LENGTH({$column}) asc")->orderBy($column);
    }
}
