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
}
