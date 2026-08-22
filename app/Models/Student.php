<?php

namespace App\Models;

use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['school_class_id', 'user_id', 'student_number', 'seat_number', 'name', 'gender'])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * 登入帳號，非必填——全校僅副班長才會有帳號連到自己的學生資料。
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * seat_number 是字串欄位（見 migration），直接 orderBy 會讓 "10" 排到
     * "2" 前面。跟 SchoolClassManager 排 class_number 用一樣的自然排序
     * 手法，這裡收斂成共用 scope，避免每個要秀學生名單的地方各自重寫
     * 一次、或忘記套用而漏掉這個問題。
     */
    public function scopeOrderBySeatNumber(Builder $query): Builder
    {
        return $query->orderByRaw('LENGTH(seat_number) asc')->orderBy('seat_number');
    }
}
