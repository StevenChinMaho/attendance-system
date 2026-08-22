<?php

namespace App\Models;

use App\Models\Concerns\HasNaturalStringSort;
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
    use HasFactory, HasNaturalStringSort;

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

    public function scopeOrderBySeatNumber(Builder $query): Builder
    {
        return $this->scopeNaturalSortBy($query, 'seat_number');
    }
}
