<?php

namespace App\Models;

use Database\Factories\TeacherFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'teacher_name'])]
class Teacher extends Model
{
    /** @use HasFactory<TeacherFactory> */
    use HasFactory;

    /**
     * 登入帳號，非必填——只有需要登入的老師（導師、身兼管理者）才會有。
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 建立/編輯老師時，如果有連結帳號，姓名一律沿用該帳號的 users.name，
     * 不再讓管理者手動再打一次——帳號本身就有姓名了，兩邊各自輸入很容易
     * 打成不一樣的字，之後畫面上（帳號管理 vs. 班級列表的導師欄）看到的
     * 名字對不起來也搞不清楚哪個才對。只有完全不連結帳號的老師（不需要
     * 登入，見 CLAUDE.md 業務身份說明）才需要手動輸入姓名。
     */
    public static function resolveName(?int $userId, ?string $manualName): string
    {
        if ($userId) {
            return User::findOrFail($userId)->name;
        }

        return (string) $manualName;
    }

    /**
     * 顯示用的名字：有連結帳號一律以帳號目前的姓名為準，不是這筆資料
     * 建立當下存進 teacher_name 的舊快照——萬一之後系統長出「帳號改名」
     * 的功能，這裡不用另外補資料同步就能自動反映最新的名字。沒有連結
     * 帳號的老師沒有這層資料來源，直接用 teacher_name。
     */
    public function displayName(): string
    {
        return $this->user?->name ?? $this->teacher_name;
    }

    /**
     * 這位老師擔任導師的班級（可能橫跨多個學年度）。
     */
    public function homeroomClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class, 'homeroom_teacher_id');
    }
}
