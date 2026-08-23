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
    case Absent = 'ABSENT';
    case Late = 'LATE';
    case EarlyLeave = 'EARLY_LEAVE';

    public function label(): string
    {
        return match ($this) {
            self::Present => '出席',
            self::Absent => '缺席',
            self::Late => '遲到',
            self::EarlyLeave => '早退',
        };
    }

    /**
     * 即時看板把四種狀態併成「出席」「缺席」兩欄統計：遲到人還是有到
     * 校，算在出席那一邊；早退人提早離校，算在缺席那一邊。副班長/導師
     * 逐筆記錄時仍然要分四種，只有全校總覽這種「一眼看出有沒有問題」
     * 的彙總畫面才需要這個二分法。
     */
    public function countsAsPresent(): bool
    {
        return match ($this) {
            self::Present, self::Late => true,
            self::Absent, self::EarlyLeave => false,
        };
    }
}
