<?php

namespace App\Enums;

/**
 * 對應 system_structure.md「出席狀態列舉」。導師與家長聯繫後的細節
 * （例如「電聯未接」「9:19已到」）不屬於狀態本身，而是記錄在
 * attendance_follow_ups（Phase 5），因為同一筆紀錄可能經歷多次追蹤，
 * 需要保留時間序列，而非覆蓋這裡的單一狀態值。
 */
enum AttendanceStatus: string
{
    case Present = 'PRESENT';
    case Late = 'LATE';
    case SickLeave = 'SICK_LEAVE';
    case PersonalLeave = 'PERSONAL_LEAVE';
    case OfficialLeave = 'OFFICIAL_LEAVE';
    case Absent = 'ABSENT';

    public function label(): string
    {
        return match ($this) {
            self::Present => '出席',
            self::Late => '遲到',
            self::SickLeave => '病假',
            self::PersonalLeave => '事假',
            self::OfficialLeave => '公假',
            self::Absent => '缺席',
        };
    }
}
