<?php

namespace App\Support;

use App\Models\SchoolClass;
use Carbon\CarbonInterface;

/**
 * 學年度／學期是貫穿全系統的最高層級篩選（班級管理頁面、即時看板都只
 * 顯示「目前選取」的那個學年度＋學期，見 system_structure.md 學年制度），
 * 獨立成一個不依賴任何 Livewire 元件的純粹輔助類別，讓
 * App\Livewire\Concerns\ScopesToSelectedAcademicPeriod、nav bar 的
 * AcademicPeriodSwitcher、SchoolClassFactory 共用同一套規則，不各自
 * 散落一份重複邏輯。
 *
 * 跟 App\Livewire\Concerns\AttendancePeriods 一樣刻意寫成 final class
 * 而非 trait：PHP 不允許從外部透過 SomeTrait::CONST 直接存取 trait 的
 * 常數，這個坑之前已經在 AttendancePeriods 踩過一次並改掉，這裡直接
 * 沿用同樣的作法，不要日後有人覺得「這應該是個 trait」又重踩一次。
 *
 * 學年度切算規則：每學年從 8/1 開始、到隔年 7/31 結束；同一學年度內，
 * 8月～隔年1月是第一學期，2月～7月是第二學期。
 */
final class AcademicPeriod
{
    private const SESSION_YEAR_KEY = 'academic_period.year';

    private const SESSION_SEMESTER_KEY = 'academic_period.semester';

    /**
     * 學年度下拉選單往未來延伸的年數——不是「無限」，刻意限制在目前
     * 學年度之後 10 年，選單才不會長到沒有意義；真的需要更遠的未來時
     * 再調整這個常數即可。
     */
    private const FUTURE_YEAR_RANGE = 10;

    public static function currentYear(): int
    {
        return self::forDate(now())['year'];
    }

    public static function currentSemester(): int
    {
        return self::forDate(now())['semester'];
    }

    /**
     * @return array{year: int, semester: int}
     */
    public static function forDate(CarbonInterface $date): array
    {
        $rocYear = $date->year - 1911;

        return match (true) {
            $date->month >= 8 => ['year' => $rocYear, 'semester' => 1],
            $date->month === 1 => ['year' => $rocYear - 1, 'semester' => 1],
            default => ['year' => $rocYear - 1, 'semester' => 2],
        };
    }

    /**
     * 使用者目前「選取」要檢視的學年度／學期——預設是目前學年度／學期，
     * 除非透過 setSelected() 主動切換過。存在 session：這只是「我現在
     * 想看哪個學年度」的個人瀏覽狀態，不是系統設定，不影響其他使用者，
     * 也不需要因此新增資料表欄位。
     */
    public static function selectedYear(): int
    {
        return (int) session(self::SESSION_YEAR_KEY, self::currentYear());
    }

    public static function selectedSemester(): int
    {
        return (int) session(self::SESSION_SEMESTER_KEY, self::currentSemester());
    }

    public static function setSelected(int $year, int $semester): void
    {
        session([
            self::SESSION_YEAR_KEY => $year,
            self::SESSION_SEMESTER_KEY => $semester,
        ]);
    }

    /**
     * 目前選取的學年度／學期，是不是就是「現實世界中此刻真正的」那個
     * 學年度／學期——選取範圍只在寒暑假才會變動，多數時候切換一次之後
     * 就會放著不管很久，容易忘記自己還停留在別的學期，看著班級管理、
     * 即時看板的資料卻誤以為是本學期的。nav bar 的 AcademicPeriodSwitcher
     * 跟這幾個頁面的「顯示範圍：...」用同一個判斷，需要提醒使用者的地方
     * 都從這裡讀，不要各自比較 selectedYear()/currentYear() 兜出邏輯。
     */
    public static function isSelectedCurrent(): bool
    {
        return self::selectedYear() === self::currentYear()
            && self::selectedSemester() === self::currentSemester();
    }

    /**
     * 下拉選單可選的學年度範圍：從資料庫最舊一筆班級紀錄的學年度開始，
     * 到目前學年度後推 10 年——涵蓋歷史資料，也預留近期新學年的空間。
     * 一筆班級都還沒有時（例如全新安裝）就從目前學年度開始。
     *
     * @return list<int>
     */
    public static function yearOptions(): array
    {
        $earliest = SchoolClass::min('academic_year') ?? self::currentYear();
        $latest = self::currentYear() + self::FUTURE_YEAR_RANGE;

        return range((int) $earliest, $latest);
    }

    /**
     * @return array<int, string>
     */
    public static function semesterOptions(): array
    {
        return [1 => '上學期', 2 => '下學期'];
    }

    public static function label(int $year, int $semester): string
    {
        return "{$year}學年度 ".(self::semesterOptions()[$semester] ?? '');
    }
}
