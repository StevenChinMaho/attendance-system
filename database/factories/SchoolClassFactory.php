<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use App\Support\AcademicPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolClass>
 */
class SchoolClassFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $classNumber = 0;

        return [
            // 預設抓「目前」學年度／學期（見 App\Support\AcademicPeriod），
            // 讓不特別在意學年度的測試自動落在畫面預設會顯示的範圍內
            // （班級管理、即時看板都只顯示目前選取的學年度）；需要測試
            // 特定學年度的情境時，仍可在 create([...]) 覆寫這兩個欄位。
            'academic_year' => AcademicPeriod::currentYear(),
            'semester' => AcademicPeriod::currentSemester(),
            'grade' => fake()->numberBetween(1, 3),
            // class_number 用遞增計數器而非隨機值：(academic_year,
            // semester, grade, class_number) 是唯一索引，隨機值在整個
            // 測試套件跑下來建立夠多班級時會有真實機率互相撞號，讓測試
            // 偶發性失敗——遞增保證每次呼叫這個 factory 都是全新的班級
            // 代號，不會跟任何一筆已建立的紀錄衝突。
            'class_number' => ++$classNumber,
        ];
    }
}
