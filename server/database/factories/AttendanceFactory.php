<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    public function definition(): array
    {
        $date = today();
        $inAt = $date->copy()->setTime(8, 0);

        return [
            'user_id' => User::factory(),
            'date' => $date,
            'in_at' => $inAt,
            'out_at' => null,
            'created_by' => null,
            'total_seconds' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(function (array $attrs) {
            $inAt = $attrs['in_at'] ?? today()->copy()->setTime(8, 0);
            $outAt = $inAt->copy()->addHours(8);

            return [
                'out_at' => $outAt,
                'total_seconds' => $inAt->diffInSeconds($outAt),
            ];
        });
    }
}
