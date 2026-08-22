<?php

namespace App\Models;

use App\Models\Concerns\HasNaturalStringSort;
use App\Policies\SchoolClassPolicy;
use Database\Factories\SchoolClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['academic_year', 'semester', 'grade', 'class_number', 'homeroom_teacher_id'])]
#[UsePolicy(SchoolClassPolicy::class)]
class SchoolClass extends Model
{
    /** @use HasFactory<SchoolClassFactory> */
    use HasFactory, HasNaturalStringSort;

    protected function casts(): array
    {
        return [
            'academic_year' => 'integer',
            'semester' => 'integer',
            'grade' => 'integer',
        ];
    }

    /**
     * 每學年度的班級都是獨立紀錄，升學年時直接改學生的 school_class_id
     * 指向新班級，不會沿用/覆蓋這一筆——見 system_structure.md 學年制度。
     */
    public function homeroomTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'homeroom_teacher_id');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class);
    }

    public function label(): string
    {
        return "{$this->academic_year}學年度 {$this->grade}年{$this->class_number}班";
    }

    /**
     * 不含學年度的班級名稱，給已經有其他方式（nav bar 的
     * AcademicPeriodSwitcher、頁面標題的「顯示範圍：...」）交代學年度
     * 上下文的畫面用——這種情境下每一列都重複印一次學年度只是雜訊，
     * 學年度切換也不頻繁（通常只有寒暑假才會變），不需要每個顯示班級
     * 名稱的地方都強調一次。label() 保留給沒有這種上下文、需要單獨
     * 完整辨識一個班級的地方用（例如同時列出跨學年度班級的場合）。
     */
    public function shortLabel(): string
    {
        return "{$this->grade}年{$this->class_number}班";
    }

    public function scopeOrderByClassNumber(Builder $query): Builder
    {
        return $this->scopeNaturalSortBy($query, 'class_number');
    }
}
