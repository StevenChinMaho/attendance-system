<?php

namespace App\Models;

use App\Models\Concerns\HasLinkableAccountName;
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
    use HasFactory, HasLinkableAccountName, HasNaturalStringSort;

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
     * resolveName()/displayName() 的行為說明見
     * App\Models\Concerns\HasLinkableAccountName——副班長連結帳號後，
     * 姓名一律沿用該帳號的 users.name，不用在學生管理再手動打一次，跟
     * Teacher 的處理方式一致。
     */
    protected static function manualNameColumn(): string
    {
        return 'name';
    }

    public function scopeOrderBySeatNumber(Builder $query): Builder
    {
        return $this->scopeNaturalSortBy($query, 'seat_number');
    }
}
