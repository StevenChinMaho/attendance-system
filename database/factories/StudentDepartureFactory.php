<?php

namespace Database\Factories;

use App\Models\Student;
use App\Models\StudentDeparture;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentDeparture>
 */
class StudentDepartureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'left_at' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'returned_at' => null,
        ];
    }
}
