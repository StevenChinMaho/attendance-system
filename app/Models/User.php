<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'username', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The linked student profile, if this account belongs to a class representative (副班長).
     */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    /**
     * The linked teacher profile, if this account belongs to a teacher.
     */
    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    /**
     * 還沒被連結成任何學生或老師身份的帳號——一個帳號同時間只能代表一個
     * 真實身份，不能又是學生又是老師。$exceptTeacherId/$exceptStudentId
     * 用於編輯畫面：讓「這筆記錄本來就連結的那個帳號」不會被自己排除掉。
     */
    public function scopeAvailableForLinking(
        Builder $query,
        ?int $exceptTeacherId = null,
        ?int $exceptStudentId = null,
    ): Builder {
        return $query
            ->whereDoesntHave('teacher', fn ($q) => $exceptTeacherId ? $q->whereKeyNot($exceptTeacherId) : $q)
            ->whereDoesntHave('student', fn ($q) => $exceptStudentId ? $q->whereKeyNot($exceptStudentId) : $q);
    }

    /**
     * 這個帳號名下所有的班級：副班長最多一筆（一個帳號只能連結一個
     * 學生身份），導師則可能同時或跨學年帶過不只一個班（`teachers.id`
     * 對 `school_classes.homeroom_teacher_id` 是一對多），其他角色
     * （例如 admin）沒有固定班級、回傳空集合。
     *
     * 「使用者名下有哪些班級」只在這裡實作一次——nav bar 的班級選單、
     * GoToMyClassAttendanceController、SchoolClassPolicy/AttendanceRecordPolicy
     * 的範圍檢查都用它，避免各處各自重寫一份同樣的邏輯又兩邊不同步。
     *
     * @return Collection<int, SchoolClass>
     */
    public function ownSchoolClasses(): Collection
    {
        if ($this->student) {
            return $this->student->schoolClass ? collect([$this->student->schoolClass]) : collect();
        }

        if ($this->teacher) {
            // 一位導師可能帶過好幾個學年度的班級（每學年的班級都是獨立
            // 紀錄，見 system_structure.md 學年制度），排序把「現在」帶的
            // 班放最前面，給只需要單一班級的呼叫點（ownSchoolClass()）用。
            return $this->teacher->homeroomClasses()
                ->orderByDesc('academic_year')
                ->orderByDesc('semester')
                ->orderByClassNumber()
                ->get();
        }

        return collect();
    }

    /**
     * 這個帳號「目前最新」所屬的單一班級，給只在乎「導去哪一頁」而不是
     * 「列出所有班級讓使用者選」的呼叫點用（例如 GoToMyClassAttendanceController
     * 的預設導向）。真正決定「這個帳號能不能操作某個班」的地方（Policy）
     * 不該用這個方法比對相等——導師可能同時或跨學年帶過不只一個班，
     * 應該用 ownSchoolClasses() 檢查「這個班在不在名下清單裡」。
     */
    public function ownSchoolClass(): ?SchoolClass
    {
        return $this->ownSchoolClasses()->first();
    }

    /**
     * 立刻讓這個帳號現有的登入 session 全部失效——縱深防禦用：即使
     * EnsureAccountIsActive middleware 未來被改壞或漏掉，session 記錄
     * 本身已經被刪除，一樣擋得住。
     *
     * 只有在 SESSION_DRIVER=database（目前的設定）時，這張表才是真正在
     * 用的 session 儲存位置；如果之後改用 file/redis 等其他 driver，
     * 這個操作會直接變成 0 筆受影響、安全地變成無作用，不會噴錯，但也
     * 不會再提供這層縱深防禦，屆時要換成對應 driver 的失效方式。
     */
    public function invalidateSessions(): void
    {
        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $this->id)
            ->delete();
    }
}
