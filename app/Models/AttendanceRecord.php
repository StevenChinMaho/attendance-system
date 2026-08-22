<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Policies\AttendanceRecordPolicy;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['attendance_session_id', 'student_id', 'status', 'updated_by'])]
#[UsePolicy(AttendanceRecordPolicy::class)]
class AttendanceRecord extends Model
{
    /** @use HasFactory<AttendanceRecordFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
        ];
    }

    public function attendanceSession(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * 最後修改這筆紀錄狀態的人（可能是副班長送出時、也可能是導師事後修正）。
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(AttendanceFollowUp::class)->latest();
    }
}
