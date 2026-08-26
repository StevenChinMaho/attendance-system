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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'student_number', 'name', 'gender'])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory, HasLinkableAccountName, HasNaturalStringSort;

    /**
     * 見 SchoolClass::students() 的說明——多對多，座號放在中間表的
     * pivot 上（$student->schoolClasses()->find($id)->pivot->seat_number）。
     */
    public function schoolClasses(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class)->withPivot('seat_number')->withTimestamps();
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * 轉學／畢業離校，不是刪除——attendance_records.student_id 是
     * cascadeOnDelete，刪掉學生會連坐刪光他過去所有的點名紀錄。轉出/
     * 轉入可能不只發生一次，每一段都各自是一筆 StudentDeparture，不是
     * 一個會被覆蓋的單一欄位——見 App\Models\StudentDeparture 的說明。
     */
    public function departures(): HasMany
    {
        return $this->hasMany(StudentDeparture::class);
    }

    /**
     * 目前尚未結束的轉出期間（returned_at 還是 null）——不是 null 就
     * 代表這個學生現在算「已轉出」。用 hasOne（限定 whereNull）而不是
     * 從 departures() 全部撈出來再篩選，讓 StudentManager 的列表可以
     * 直接 eager load 這一筆，不用每一列各自查一次、也不用把全部歷史
     * 期間都load 進來。
     */
    public function currentDeparture(): HasOne
    {
        return $this->hasOne(StudentDeparture::class)->whereNull('returned_at');
    }

    /**
     * 這個學生在指定日期算不算在讀——因為轉出/轉入可能發生好幾次，不能
     * 只看「現在」是不是已轉出，要看這個日期有沒有落在任何一段轉出期間
     * 裡面。呼叫方要自己 eager load departures（Recorder/StatusBoard
     * 都是整個班級一次 eager load，不是每個學生各自觸發一次查詢）。
     *
     * 轉出當天、轉入當天本身都算在讀（當天可能上午還在校才辦手續）：
     * 「不在讀」的範圍是嚴格晚於 left_at、且早於 returned_at（如果還
     * 沒 returned_at，代表這段期間還沒結束，範圍不設上限）。
     */
    public function isEnrolledOn(string $date): bool
    {
        return ! $this->departures->contains(
            fn (StudentDeparture $departure) => $departure->left_at->toDateString() < $date
                && (is_null($departure->returned_at) || $departure->returned_at->toDateString() > $date)
        );
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
     * 登入帳號，非必填——只有需要自己登入系統的學生（例如負責填點名單
     * 的那一位）才會有帳號連到自己的學生資料，大部分學生沒有。
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * resolveName()/displayName() 的行為說明見
     * App\Models\Concerns\HasLinkableAccountName——學生連結帳號後，
     * 姓名一律沿用該帳號的 users.name，不用在學生管理再手動打一次，跟
     * Teacher 的處理方式一致。
     */
    protected static function manualNameColumn(): string
    {
        return 'name';
    }

    /**
     * 只能透過已經 join 過中間表的查詢呼叫（例如
     * $schoolClass->students()->orderBySeatNumber()）——座號在
     * school_class_student 這張中間表上，不是 students 自己的欄位，
     * 沒有 join 的話這個欄位不存在，查詢會直接失敗。
     */
    public function scopeOrderBySeatNumber(Builder $query): Builder
    {
        return $this->scopeNaturalSortBy($query, 'school_class_student.seat_number');
    }
}
