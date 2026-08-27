<?php

namespace App\Support;

use App\Enums\AttendanceStatus;
use Spatie\Activitylog\Models\Activity;

/**
 * 把一列 activity_log 轉成畫面上看得懂的文字。
 *
 * 為什麼需要這一層：稽核紀錄有兩種形狀，而且短期內不會統一。
 *
 *  * 我們自己透過 AuditLog 寫的，資料在 `properties`（一組已知的 key）。
 *  * AttendanceFollowUp 走 spatie 的 LogsActivity 自動記錄，資料在
 *    `attribute_changes`（{attributes: {...}, old: {...}}），而且
 *    description 是英文的 created/updated/deleted。
 *
 * 把差異收在這裡，Blade 就只面對「摘要一行字 + 明細一組 key/value」。
 * 同樣是 plain final class，理由跟 AcademicPeriod 那幾個一樣。
 */
final class AuditLogPresenter
{
    /**
     * log_name 對應畫面上的分類標籤，也是篩選下拉選單的選項來源。
     */
    public const CATEGORIES = [
        AuditLog::AUTH => '登入',
        AuditLog::ADMIN => '後台管理',
        AuditLog::ATTENDANCE_SESSION => '點名',
        AuditLog::ATTENDANCE_RECORD => '出席狀態',
        AuditLog::ATTENDANCE_FOLLOW_UP => '處理情形',
    ];

    /**
     * properties 的 key 對應中文標籤。沒對應到的 key 會原樣顯示——
     * 這是刻意的：之後有人加了新的 properties key 卻忘了補這張表，
     * 畫面會照樣把它顯示出來（只是標籤是英文），而不是把資料吃掉。
     */
    private const FIELD_LABELS = [
        'ip' => '來源 IP',
        'user_agent' => '瀏覽器',
        'username' => '帳號',
        'name' => '姓名',
        'role' => '身分',
        'role_from' => '原身分',
        'role_to' => '新身分',
        'permission' => '權限',
        'permissions' => '權限',
        'school_class' => '班級',
        'school_class_id' => '班級 ID',
        'student_name' => '學生',
        'student_number' => '學號',
        'student_id' => '學生 ID',
        'teacher_name' => '老師',
        'teacher_id' => '老師 ID',
        'seat_number' => '座號',
        'date' => '日期',
        'period_label' => '時段',
        'student_count' => '人數',
        'status_counts' => '各狀態人數',
        'left_at' => '轉出日期',
        'returned_at' => '轉入日期',
        'gender' => '性別',
        'file_name' => '檔案名稱',
        'created' => '新增筆數',
        'attached' => '排班筆數',
        'skipped' => '略過筆數',
        'total_rows' => '總列數',
        'classes' => '涉及班級',
        'linked_user_id' => '連結帳號 ID',
        'user_id' => '帳號 ID',
        'role_id' => '身分 ID',
        'retry_after_seconds' => '需等待秒數',
        'remember' => '記住我',
        'via' => '操作來源',
        'old' => '變更前',
        'new' => '變更後',
        'attendance_session_id' => '點名場次 ID',
        'period' => '時段代號',
    ];

    public static function category(Activity $activity): string
    {
        return self::CATEGORIES[$activity->log_name] ?? ($activity->log_name ?? '其他');
    }

    /**
     * description 直接拿來當「動作」欄。唯一需要翻譯的是 spatie 自動
     * 記錄產生的英文事件名（處理情形）。
     */
    public static function action(Activity $activity): string
    {
        return match ($activity->description) {
            'created' => '新增處理情形',
            'updated' => '修改處理情形',
            'deleted' => '刪除處理情形',
            default => (string) $activity->description,
        };
    }

    /**
     * 表格裡那一行摘要——挑出這一類紀錄最關鍵的資訊，不是把 properties
     * 全部倒出來（那是展開明細的工作）。
     */
    public static function summary(Activity $activity): string
    {
        $properties = self::properties($activity);

        $parts = match ($activity->log_name) {
            AuditLog::AUTH => array_filter([
                $properties['username'] ?? null,
                $properties['ip'] ?? null,
            ]),

            AuditLog::ATTENDANCE_SESSION => array_filter([
                $properties['school_class'] ?? null,
                self::dateAndPeriod($properties),
                isset($properties['student_count']) ? "{$properties['student_count']} 人" : null,
                self::statusCounts($properties['status_counts'] ?? null),
            ]),

            AuditLog::ATTENDANCE_RECORD => array_filter([
                $properties['school_class'] ?? null,
                self::dateAndPeriod($properties),
                self::studentLabel($properties),
                self::statusChange($properties),
            ]),

            AuditLog::ATTENDANCE_FOLLOW_UP => array_filter([
                self::contentChange($activity),
            ]),

            default => array_filter([
                // 後台動作：挑最能指認「動到誰」的那一個欄位。
                $properties['username']
                    ?? $properties['student_name']
                    ?? $properties['teacher_name']
                    ?? $properties['school_class']
                    ?? $properties['role']
                    ?? $properties['file_name']
                    ?? null,
                self::roleChange($properties),
                $properties['permission'] ?? null,
            ]),
        };

        return implode(' · ', $parts) ?: '—';
    }

    /**
     * 展開後的完整明細。回傳 [中文標籤 => 已轉成字串的值]。
     *
     * @return array<string, string>
     */
    public static function details(Activity $activity): array
    {
        $rows = [];

        foreach (self::properties($activity) as $key => $value) {
            $rows[self::FIELD_LABELS[$key] ?? $key] = self::stringify($key, $value);
        }

        // 處理情形的內容變更存在另一個欄位，一併攤平進來。
        $changes = $activity->attribute_changes ?? [];

        if (isset($changes['old']['content'])) {
            $rows['變更前內容'] = (string) $changes['old']['content'];
        }

        if (isset($changes['attributes']['content'])) {
            $rows['變更後內容'] = (string) $changes['attributes']['content'];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function properties(Activity $activity): array
    {
        $properties = $activity->properties;

        return is_array($properties) ? $properties : $properties->toArray();
    }

    private static function dateAndPeriod(array $properties): ?string
    {
        if (! isset($properties['date'])) {
            return null;
        }

        return trim($properties['date'].' '.($properties['period_label'] ?? ''));
    }

    private static function studentLabel(array $properties): ?string
    {
        $number = $properties['student_number'] ?? null;
        $name = $properties['student_name'] ?? null;

        return trim(($number ? $number.' ' : '').($name ?? '')) ?: null;
    }

    private static function statusChange(array $properties): ?string
    {
        if (! array_key_exists('new', $properties)) {
            return null;
        }

        $old = self::statusLabel($properties['old'] ?? null);
        $new = self::statusLabel($properties['new']);

        return $old === null ? $new : "{$old} → {$new}";
    }

    private static function statusLabel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // 之後如果 enum 新增了狀態、或舊紀錄裡留著已經移除的狀態值，
        // 顯示原始字串總比整頁噴例外好。
        return AttendanceStatus::tryFrom($value)?->label() ?? $value;
    }

    private static function statusCounts(mixed $counts): ?string
    {
        if (! is_array($counts)) {
            return null;
        }

        $parts = [];

        foreach ($counts as $value => $count) {
            if ($count > 0) {
                $parts[] = (self::statusLabel((string) $value) ?? $value).' '.$count;
            }
        }

        return $parts ? implode('、', $parts) : null;
    }

    private static function roleChange(array $properties): ?string
    {
        if (! isset($properties['role_from'], $properties['role_to'])) {
            return null;
        }

        return RoleLabel::forName($properties['role_from']).' → '.RoleLabel::forName($properties['role_to']);
    }

    private static function contentChange(Activity $activity): ?string
    {
        $changes = $activity->attribute_changes ?? [];
        $content = $changes['attributes']['content'] ?? $changes['old']['content'] ?? null;

        return $content === null ? null : mb_strimwidth((string) $content, 0, 60, '…');
    }

    private static function stringify(string $key, mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? '是' : '否';
        }

        if ($key === 'status_counts') {
            return self::statusCounts($value) ?? '—';
        }

        if (in_array($key, ['old', 'new'], true)) {
            return self::statusLabel((string) $value) ?? (string) $value;
        }

        if (in_array($key, ['role', 'role_from', 'role_to'], true)) {
            return RoleLabel::forName((string) $value);
        }

        if (is_array($value)) {
            return implode('、', array_map(fn ($item) => is_scalar($item) ? (string) $item : json_encode($item, JSON_UNESCAPED_UNICODE), $value)) ?: '—';
        }

        return (string) $value;
    }
}
