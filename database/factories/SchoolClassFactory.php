<?php

namespace Database\Factories;

use App\Models\SchoolClass;
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

        // class_number 用遞增計數器而非隨機值：(academic_year, semester,
        // grade, class_number) 是唯一索引，隨機值在整個測試套件跑下來
        // 建立夠多班級時會有真實機率互相撞號，讓測試偶發性失敗——遞增
        // 保證每次呼叫這個 factory 都是全新的班級代號，不會跟任何一筆
        // 已建立的紀錄衝突。
        return [
            'academic_year' => fake()->numberBetween(110, 115),
            'semester' => fake()->numberBetween(1, 2),
            'grade' => fake()->numberBetween(1, 3),
            'class_number' => (string) (++$classNumber),
        ];
    }
}
