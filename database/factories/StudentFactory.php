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
        static $seatNumber = 0;

        return [
            'school_class_id' => SchoolClass::factory(),
            'student_number' => (string) fake()->unique()->numberBetween(10001, 99999),
            'seat_number' => (string) (++$seatNumber),
            'name' => fake()->name(),
            'gender' => fake()->randomElement(['男', '女']),
        ];
    }
}
