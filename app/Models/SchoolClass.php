<?php

namespace App\Models;

use App\Policies\SchoolClassPolicy;
use Database\Factories\SchoolClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['academic_year', 'semester', 'grade', 'class_number', 'homeroom_teacher_id'])]
#[UsePolicy(SchoolClassPolicy::class)]
class SchoolClass extends Model
{
    /** @use HasFactory<SchoolClassFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'academic_year' => 'integer',
            'semester' => 'integer',
            'grade' => 'integer',
            'class_number' => 'integer',
        ];
    }

    /**
     * 每學年度的班級都是獨立紀錄——見 system_structure.md 學年制度。
     */
    public function homeroomTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'homeroom_teacher_id');
    }

    /**
     * 學生跟班級是多對多，不是單一 school_class_id——同一個真實學生
     * 從入學到畢業自始至終只有一筆 students 資料，但在校期間會歷經
     * 好幾個班級（每學期一個）。不需要額外記錄日期區間：每一筆
     * school_classes 本身就已經綁定特定學年度／學期，時間資訊已經包含
     * 在那筆紀錄裡了，「升學年」就是多連一筆到新學期的班級，完全不用
     * 動舊班級那筆連結，舊班級的名單也就永遠原封不動留著。
     *
     * 座號（seat_number）放在中間表 school_class_student 上，不是
     * students 表——座號是「這個學生在這個班的座號」，同一個學生在不同
     * 班級座號本來就可能不一樣。
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class)->withPivot('seat_number')->withTimestamps();
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
        // class_number 現在是整數，排序天生正確，不再需要 HasNaturalStringSort
        // 那個「先按長度、再按字典序」的技巧（那是給字串欄位用的，見
        // Student::scopeOrderBySeatNumber()，座號還是字串）。
        return $query->orderBy('class_number');
    }
}
