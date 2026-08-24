<?php

namespace App\Models;

use Database\Factories\StudentDepartureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 一筆「從某天轉出，到某天轉回來」的期間——一個學生可以有好幾筆（轉出
 * 又轉入好幾次），每筆各自獨立，不會互相覆蓋，見 CLAUDE.md 對
 * Student::isEnrolledOn() 的說明。returned_at 是 null 代表這筆還沒
 * 結束，也就是這個學生目前正處於轉出狀態。
 */
#[Fillable(['student_id', 'left_at', 'returned_at'])]
class StudentDeparture extends Model
{
    /** @use HasFactory<StudentDepartureFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'left_at' => 'date',
            'returned_at' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
