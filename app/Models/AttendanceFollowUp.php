<?php

namespace App\Models;

use Database\Factories\AttendanceFollowUpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * 導師（或管理者）針對缺席/遲到學生留下的聯繫記錄，例如「電聯未接」
 * 「9:19已到」——同一筆 attendance_records 可能隨時間累積好幾筆，
 * 是刻意設計成只能新增、不能編輯/刪除的時間序列，本身就是一份紀錄，
 * 不需要另外用 activitylog 追蹤「這筆處理情形被改成什麼」。
 */
#[Fillable(['attendance_record_id', 'created_by', 'content'])]
class AttendanceFollowUp extends Model
{
    /** @use HasFactory<AttendanceFollowUpFactory> */
    use HasFactory, LogsActivity;

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['content'])
            ->useLogName('attendance_follow_up');
    }
}
