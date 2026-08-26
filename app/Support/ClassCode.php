<?php

namespace App\Support;

/**
 * 學校匯出的 Excel 用「班級代號」這種三碼數字表示班級（第一碼是年級，
 * 後兩碼是補零的班級編號）：101 = 1年1班、211 = 2年11班。系統裡則是
 * grade + class_number 兩個獨立的整數欄位，班級編號不補零（1 就是 1，
 * 不是 01——見 change_class_number_to_integer_on_school_classes_table
 * migration）。這個類別是兩邊唯一的轉換點，避免各處各自 substr 一份、
 * 又有的記得去掉補零、有的忘記，導致「01」比對不到資料庫裡的「1」。
 *
 * 跟 App\Support\AcademicPeriod／App\Livewire\Concerns\AttendancePeriods
 * 一樣是 plain final class 而不是 trait——PHP 不允許從 use 它的類別以外
 * 存取 trait 常數，Blade 或別的類別要拿 PATTERN 就會編譯失敗。
 */
final class ClassCode
{
    /**
     * 一律三碼：第一碼年級（1-3），後兩碼班級編號。長度不對或含非數字
     * 一律視為格式錯誤，不猜——例如打成 "10" 或 "1011"，與其硬解析出一個
     * 可能是錯的年級/班級，不如直接讓匯入畫面標成錯誤讓人工確認。
     */
    public const PATTERN = '/^[1-3]\d{2}$/';

    /**
     * 解析成 ['grade' => int, 'class_number' => int]，格式不符回傳 null。
     * class_number 用 (int) 去掉補零，才能跟資料庫裡的整數欄位直接比對。
     */
    public static function parse(string $code): ?array
    {
        $code = trim($code);

        if (! preg_match(self::PATTERN, $code)) {
            return null;
        }

        $classNumber = (int) substr($code, 1);

        // 000 這種「年級對但班級是 0」的代號語意上不存在，一併擋掉。
        if ($classNumber < 1) {
            return null;
        }

        return [
            'grade' => (int) substr($code, 0, 1),
            'class_number' => $classNumber,
        ];
    }

    /**
     * parse() 的反向操作：把系統裡的 grade + class_number 組回學校慣用的
     * 三碼代號（1年1班 → "101"、2年11班 → "211"）。跟 parse() 放在同一個
     * 類別，兩個方向的規則（第一碼年級、後兩碼補零班級編號）才不會各自
     * 散在別的地方、改了一邊忘了另一邊。
     */
    public static function format(int $grade, int $classNumber): string
    {
        return $grade.str_pad((string) $classNumber, 2, '0', STR_PAD_LEFT);
    }
}
