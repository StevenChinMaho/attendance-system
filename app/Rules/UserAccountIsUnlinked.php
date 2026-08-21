<?php

namespace App\Rules;

use App\Models\Student;
use App\Models\Teacher;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 一個帳號同時間只能代表一個真實身份（一位學生或一位老師），不能兩邊都連。
 * 前端下拉選單雖然已經用 whereDoesntHave 濾掉不能選的帳號，但那只是 UX
 * 層面的防呆——Livewire 的 public property 是直接從網路封包 hydrate 的，
 * 繞過畫面直接送出偽造的 userId 一樣要在伺服器端擋下來。
 */
class UserAccountIsUnlinked implements ValidationRule
{
    public function __construct(
        private readonly ?int $ignoreTeacherId = null,
        private readonly ?int $ignoreStudentId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $linkedToTeacher = Teacher::where('user_id', $value)
            ->when($this->ignoreTeacherId, fn ($query, $id) => $query->whereKeyNot($id))
            ->exists();

        $linkedToStudent = Student::where('user_id', $value)
            ->when($this->ignoreStudentId, fn ($query, $id) => $query->whereKeyNot($id))
            ->exists();

        if ($linkedToTeacher || $linkedToStudent) {
            $fail('這個帳號已經連結到其他學生或老師了。');
        }
    }
}
