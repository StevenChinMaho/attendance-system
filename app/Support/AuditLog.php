<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * 稽核紀錄的單一寫入點。
 *
 * 存在的理由跟 AcademicPeriod／AttendanceWindow／ClassCode 一樣：日誌的
 * log_name、描述用語、properties 的形狀如果散落在各個元件裡各寫各的，
 * 之後要做查詢畫面就得替每一種形狀各寫一套解析。集中在這裡之後，畫面
 * 只要處理一組約定。同樣是 plain final class 而不是 trait——PHP 不允許
 * 從 use 它的類別外面存取 trait 的常數。
 *
 * 三個貫穿所有寫入的約定：
 *
 * 1. **description 一律中文。** 原本 AttendanceFollowUp 走 spatie 的
 *    自動記錄，描述是 created/updated/deleted，跟 Recorder 手寫的中文
 *    混在同一張表裡，畫面得翻譯兩套。
 *
 * 2. **properties 要帶「當下的可讀值」，不能只存 id。** 稽核紀錄的意義
 *    是還原「當時發生了什麼」，而班級、學生、帳號都可能之後被改名甚至
 *    刪除；只存 id 的話，事後查詢會變成一堆查不到對應資料的孤兒。所以
 *    寫入時就把班級名稱、學生姓名、帳號名稱等一起存進去——這是刻意的
 *    反正規化，值代表「當時」而不是「現在」，正是稽核要的語意。
 *
 * 3. **不要記任何密碼相關的值**，連雜湊也不要。重設密碼只記「重設了
 *    誰的密碼」，不記內容。
 */
final class AuditLog
{
    /** 登入、登出、登入失敗。 */
    public const AUTH = 'auth';

    /** 後台管理動作（帳號、身分、班級、學生、名冊、匯入）。 */
    public const ADMIN = 'admin';

    /** 點名單送出／重新送出（整份，不是個別學生）。 */
    public const ATTENDANCE_SESSION = 'attendance_session';

    /** 個別學生的出席狀態變更。 */
    public const ATTENDANCE_RECORD = 'attendance_record';

    /** 處理情形（由 AttendanceFollowUp 的 LogsActivity 自動寫入）。 */
    public const ATTENDANCE_FOLLOW_UP = 'attendance_follow_up';

    /**
     * 後台管理動作。causer 一律是目前登入的操作者。
     *
     * @param  array<string, mixed>  $properties
     */
    public static function admin(string $description, array $properties = [], ?Model $subject = null): void
    {
        self::write(self::ADMIN, $description, $properties, $subject);
    }

    /**
     * 登入相關事件。一定會附上來源 IP 與 User-Agent——「帳號被別人拿去
     * 用」這件事，最直接的證據就是「同一個帳號在不該出現的時間、從不該
     * 出現的位置登入」，而狀態變更紀錄本身看不出這一點。
     *
     * $causer 傳 null 代表「這次嘗試對不到任何帳號」（例如帳號不存在），
     * 會記成匿名事件而不是掛在某個使用者身上。
     *
     * 注意 IP 要正確，前提是 bootstrap/app.php 的 trustProxies 有設定好
     * ——否則隔著 cloudflared/nginx 記到的會是內網容器 IP，這一整個功能
     * 就沒有意義了（見 TrustedProxiesTest）。
     *
     * @param  array<string, mixed>  $properties
     */
    public static function auth(string $description, ?Model $causer, array $properties = []): void
    {
        $request = request();

        self::write(self::AUTH, $description, [
            ...$properties,
            'ip' => $request->ip(),
            // 截斷：User-Agent 可以很長，而這裡只是要能區分「是不是同一
            // 台裝置／瀏覽器」，不需要完整字串。
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ], causer: $causer, useAuthUser: false);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function attendanceSession(string $description, array $properties = [], ?Model $subject = null): void
    {
        self::write(self::ATTENDANCE_SESSION, $description, $properties, $subject);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function attendanceRecord(string $description, array $properties = [], ?Model $subject = null): void
    {
        self::write(self::ATTENDANCE_RECORD, $description, $properties, $subject);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function write(
        string $logName,
        string $description,
        array $properties,
        ?Model $subject = null,
        ?Model $causer = null,
        bool $useAuthUser = true,
    ): void {
        $activity = activity($logName);

        if ($causer !== null) {
            $activity->causedBy($causer);
        } elseif ($useAuthUser && auth()->check()) {
            $activity->causedBy(auth()->user());
        } else {
            // 明確標記成匿名，而不是讓 spatie 自己去猜目前的登入者
            // ——登入失敗的當下本來就還沒有登入者，讓它掛在「剛好還在
            // session 裡的某個人」身上會是錯的歸屬。
            $activity->causedByAnonymous();
        }

        if ($subject !== null) {
            $activity->performedOn($subject);
        }

        $activity->withProperties($properties)->log($description);
    }
}
