<?php

namespace Database\Factories;

use App\Models\AttendanceSession;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceSession>
 */
class AttendanceSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_class_id' => SchoolClass::factory(),
            'date' => fake()->date(),
            'period' => fake()->randomElement(['MORNING', 'NOON', 'AFTERNOON']),
            'recorded_by' => User::factory(),
        ];
    }
}
