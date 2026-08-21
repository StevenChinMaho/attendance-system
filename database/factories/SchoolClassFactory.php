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
        return [
            'academic_year' => fake()->numberBetween(110, 115),
            'semester' => fake()->numberBetween(1, 2),
            'grade' => fake()->numberBetween(1, 3),
            'class_number' => (string) fake()->numberBetween(1, 20),
        ];
    }
}
