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

// must_change_password 故意不放進 Fillable：這是安全敏感的旗標，只
// 應該由 UserManager 的建立/重設密碼流程用 forceFill() 明確設定（跟
// last_login_at 一樣的處理方式），不開放透過一般的 create()/update()
// 大量賦值意外帶到別的值。
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
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The linked student profile, if this account belongs to a student (學生).
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
     * 這個帳號的點名／處理情形範圍是不是「全校所有班級」，而不是
     * ownSchoolClasses() 那份名單。
     *
     * 兩種權限都算數，而且是刻意分開的兩件事：
     *
     * - classes.manage：「能新增/修改任何班級」的管理層級，範圍延伸到
     *   「能操作任何班級的點名」是一致的（歷史上這裡曾經寫死
     *   hasRole('admin')，見下面那段）。
     * - attendance.record.all：純粹的點名範圍權限，不附帶任何管理能力。
     *   學務處人員要能幫任何一班補點名、但不該能改班級設定，就是給這個。
     *
     * **一律檢查權限，不要寫死 hasRole('admin')。** 這裡踩過一次實際的
     * bug：一個從 /admin/roles 建出來、被賦予跟 admin 完全相同權限組合的
     * 自訂身分，因為沒有連結任何 Teacher/Student 業務身份，
     * ownSchoolClasses() 對它必定是空集合，於是它一個班都點不了名——
     * 即使它「應該」擁有等同 admin 的存取範圍。
     *
     * 判斷寫在這裡而不是各自散在 Policy／元件裡，是因為它有四個呼叫點
     * （SchoolClassPolicy、AttendanceRecordPolicy、AttendanceQuickLink
     * 以及它的 Blade），四份複製品遲早會有一份忘了跟著改。
     */
    public function hasAllClassAccess(): bool
    {
        return $this->can('classes.manage') || $this->can('attendance.record.all');
    }

    /**
     * 這個帳號名下所有的班級：學生跟導師現在是同一種形狀——一個帳號
     * 只能連結一個學生身份（見 UserAccountIsUnlinked），但那個學生本身
     * 在校期間會歷經好幾個班級（`Student::schoolClasses()` 是多對多，
     * 見 SchoolClass::students() 的說明），導師則可能同時或跨學年帶過
     * 不只一個班（`teachers.id` 對 `school_classes.homeroom_teacher_id`
     * 是一對多）。其他角色（例如 admin）沒有固定班級、回傳空集合。
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
            // 排序邏輯跟下面的導師分支一致：「現在」所屬的班放最前面，
            // 給只需要單一班級的呼叫點（ownSchoolClass()）用。
            return $this->student->schoolClasses()
                ->orderByDesc('academic_year')
                ->orderByDesc('semester')
                ->orderByClassCode()
                ->get();
        }

        if ($this->teacher) {
            // 一位導師可能帶過好幾個學年度的班級（每學年的班級都是獨立
            // 紀錄，見 system_structure.md 學年制度），排序把「現在」帶的
            // 班放最前面，給只需要單一班級的呼叫點（ownSchoolClass()）用。
            return $this->teacher->homeroomClasses()
                ->orderByDesc('academic_year')
                ->orderByDesc('semester')
                ->orderByClassCode()
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

    /**
     * 這個帳號有沒有留下任何點名／處理情形的操作紀錄
     * （attendance_sessions.recorded_by／attendance_records.updated_by／
     * attendance_follow_ups.created_by）。三個外鍵在 migration 裡都沒有
     * 設定 onDelete，資料庫層級預設是 RESTRICT，直接刪除會噴出未經
     * 處理的例外——但就算資料庫沒有這層限制，語意上「這個帳號做過的
     * 事」本來就不該因為帳號被刪除就憑空消失，破壞稽核歷程。
     * UserManager::deleteUser() 用這個方法在刪除前先擋下來，給出清楚
     * 的錯誤訊息，引導改用「停用」。
     */
    public function hasAttendanceHistory(): bool
    {
        return AttendanceSession::where('recorded_by', $this->id)->exists()
            || AttendanceRecord::where('updated_by', $this->id)->exists()
            || AttendanceFollowUp::where('created_by', $this->id)->exists();
    }
}
