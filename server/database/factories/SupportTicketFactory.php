<?php

namespace Database\Factories;

use App\Enums\SupportCategory;
use App\Enums\SupportStatus;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicket>
 */
class SupportTicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subject' => rtrim(fake()->sentence(6), '.'),
            'category' => fake()->randomElement(SupportCategory::cases())->value,
            'status' => SupportStatus::Abierto->value,
            'body' => fake()->paragraph(),
            'last_reply_at' => null,
        ];
    }

    public function status(SupportStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }
}
