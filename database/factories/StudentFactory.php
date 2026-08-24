<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_number' => (string) fake()->unique()->numberBetween(10001, 99999),
            'name' => fake()->name(),
            'gender' => fake()->randomElement(['男', '女']),
        ];
    }

    /**
     * 把建立好的學生連到某個班級（附上座號）——school_class_id/seat_number
     * 不再是 students 表自己的欄位（多對多，見 SchoolClass::students()
     * 的說明），不能再用 Factory::for()（那個是給 belongsTo/morphTo 用的，
     * 會設外鍵欄位，但多對多沒有外鍵欄位可設），改用 afterCreating 直接
     * attach 到中間表。沒有指定座號時預設用學生自己的 id（保證唯一，
     * 不需要額外的計數器狀態），不在乎座號實際值的測試可以不傳。
     */
    public function forClass(SchoolClass $schoolClass, ?string $seatNumber = null): static
    {
        return $this->afterCreating(function (Student $student) use ($schoolClass, $seatNumber) {
            $student->schoolClasses()->attach($schoolClass->id, [
                'seat_number' => $seatNumber ?? (string) $student->id,
            ]);
        });
    }
}
