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

// left_at 故意不放進 Fillable：轉出狀態只應該由 StudentManager 的
// markAsLeft()/markAsActive() 用 forceFill() 明確設定（跟 User::
// must_change_password 一樣的處理方式），不開放透過一般的
// create()/update() 大量賦值意外帶到別的值。
#[Fillable(['school_class_id', 'user_id', 'student_number', 'seat_number', 'name', 'gender'])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory, HasLinkableAccountName, HasNaturalStringSort;

    protected function casts(): array
    {
        return [
            'left_at' => 'datetime',
        ];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * 轉學／畢業離校，不是刪除——attendance_records.student_id 是
     * cascadeOnDelete，刪掉學生會連坐刪光他過去所有的點名紀錄。標記
     * 已轉出的學生：歷史紀錄完整保留、學生管理列表上還看得到，但不會
     * 再出現在 Recorder 每天要點名的名冊、StatusBoard 的應到人數裡
     * （見 scopeActive()）。
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('left_at');
    }

    /**
     * 有沒有留下任何點名紀錄——StudentManager::deleteStudent() 用這個
     * 判斷能不能真的刪除，理由跟 User::hasAttendanceHistory() 一樣：
     * attendance_records.student_id 是 cascadeOnDelete，硬刪會連坐刪掉
     * 點名歷史，語意上也不該讓「這個學生哪幾天出席/缺席」的紀錄憑空
     * 消失。真的轉學的學生幾乎一定有點名紀錄，這也是為什麼「轉出」跟
     * 「刪除」必須是兩個獨立的功能，不能只做刪除。
     */
    public function hasAttendanceHistory(): bool
    {
        return $this->attendanceRecords()->exists();
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
