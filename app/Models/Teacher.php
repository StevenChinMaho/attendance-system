<?php

namespace App\Models;

use App\Models\Concerns\HasLinkableAccountName;
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
    use HasFactory, HasLinkableAccountName;

    /**
     * 登入帳號，非必填——只有需要登入的老師（導師、身兼管理者）才會有。
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * resolveName()/displayName() 的行為說明見
     * App\Models\Concerns\HasLinkableAccountName——這裡只需要告訴它
     * 「沒有連結帳號時，手動輸入的姓名存在哪個欄位」。
     */
    protected static function manualNameColumn(): string
    {
        return 'teacher_name';
    }

    /**
     * 這位老師擔任導師的班級（可能橫跨多個學年度）。
     */
    public function homeroomClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class, 'homeroom_teacher_id');
    }
}
