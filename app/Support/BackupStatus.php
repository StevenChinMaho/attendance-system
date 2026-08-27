<?php

namespace App\Support;

use App\Models\BackupRun;
use Illuminate\Support\Carbon;

/**
 * 回答「備份還健康嗎」——供後台的過期警告使用（見 config/backup.php）。
 *
 * 存在的理由跟其他 Support 類別一樣：判斷「多久算過期」只在這裡定義
 * 一次，畫面元件與之後可能新增的檢查指令讀的是同一個定義，不會各自
 * 寫一組比較邏輯而慢慢對不起來。plain final class，理由同
 * AcademicPeriod／AttendanceWindow。
 */
final class BackupStatus
{
    public static function enabled(): bool
    {
        return (bool) config('backup.monitor_enabled');
    }

    public static function lastRun(): ?BackupRun
    {
        return BackupRun::query()->latest('completed_at')->first();
    }

    /**
     * 距離上一次成功備份過了幾小時。從來沒有備份過時回傳 null
     * ——那跟「備份很舊」是不同的狀況，訊息也該不一樣。
     */
    public static function hoursSinceLastRun(?Carbon $now = null): ?float
    {
        $last = self::lastRun();

        if (! $last) {
            return null;
        }

        return $last->completed_at->diffInRealHours($now ?? now(), absolute: true);
    }

    /**
     * 該不該顯示警告。監控沒開啟時一律不顯示——本機開發環境沒有備份
     * 容器，開著只會每一頁都跳警告。
     */
    public static function isStale(?Carbon $now = null): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $hours = self::hoursSinceLastRun($now);

        // 從來沒有成功備份過，也算需要示警——而且是最需要的那一種，
        // 代表備份可能從一開始就沒設定好。
        if ($hours === null) {
            return true;
        }

        return $hours > (float) config('backup.warn_after_hours');
    }

    /**
     * 給畫面顯示的一行說明。
     */
    public static function warningMessage(?Carbon $now = null): string
    {
        $last = self::lastRun();

        if (! $last) {
            return '系統從來沒有成功備份過資料庫，請確認備份服務是否已經啟動。';
        }

        $when = $last->completed_at->format('Y-m-d H:i');
        $hours = (int) self::hoursSinceLastRun($now);

        return "最後一次成功備份是 {$when}（約 {$hours} 小時前），請檢查備份服務是否正常運作。";
    }
}
