<?php

namespace Database\Factories;

use App\Models\AttendanceFollowUp;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceFollowUp>
 */
class AttendanceFollowUpFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attendance_record_id' => AttendanceRecord::factory(),
            'created_by' => User::factory(),
            'content' => fake()->randomElement(['電聯未接', '9:19已到', '請假一天，家長已電聯確認']),
        ];
    }
}
