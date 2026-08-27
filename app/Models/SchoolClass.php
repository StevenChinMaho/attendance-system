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

    /**
     * 班級的自然排列順序：1年1班、1年2班、…、2年1班、…、3年4班。
     *
     * **年級一定要排在班級編號前面**，因為一個班級的身分是「年級＋班級
     * 編號」這個組合（學校的三碼班級代號就是這樣編的，見 App\Support\ClassCode）。
     * 只排 class_number 的話，1年1班／2年1班／3年1班 會全部擠在最前面，
     * 接著才是所有的 2 號班——這正是這個 scope 原本的行為（它以前叫
     * orderByClassNumber，名副其實地只排了 class_number），而畫面上看起來
     * 正常的那幾頁，是各自在呼叫端多寫了一句 orderBy('grade') 補起來的。
     * 漏寫的地方就會排錯（學生管理的班級篩選選單、導覽列的班級選單都
     * 中過），所以年級改成放進 scope 裡，呼叫端不必也不應該再自己補。
     *
     * class_number 是整數欄位，排序天生正確，不需要 HasNaturalStringSort
     * 那個「先按長度、再按字典序」的技巧（那是給字串欄位用的，見
     * Student::scopeOrderBySeatNumber()，座號還是字串）。
     */
    public function scopeOrderByClassCode(Builder $query): Builder
    {
        return $query->orderBy('grade')->orderBy('class_number');
    }
}
