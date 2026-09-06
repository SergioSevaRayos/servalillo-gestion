<?php

namespace Database\Factories;

use App\Enums\DeliveryNoteStatus;
use App\Models\DeliveryNote;
use App\Models\RouteStop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryNote>
 */
class DeliveryNoteFactory extends Factory
{
    public function definition(): array
    {
        $qty = fake()->numberBetween(2, 20) * 100;

        return [
            'route_stop_id' => RouteStop::factory(),
            'number' => 'ALB-'.now()->year.'-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'issued_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'customer_snapshot' => [
                'name' => fake()->company(),
                'tax_id' => strtoupper(fake()->bothify('?########')),
                'address' => fake()->streetAddress(),
            ],
            'delivered_quantity' => $qty,
            'signer_name' => fake()->name(),
            'delivery_channel' => fake()->randomElement(['email', 'physical']),
            'recipient_email' => fake()->optional()->safeEmail(),
            'status' => DeliveryNoteStatus::Generated,
            'created_by' => null,
        ];
    }

    public function channel(string $key): static
    {
        return $this->state(fn () => [
            'delivery_channel' => $key,
            'recipient_email' => $key === 'email' ? fake()->safeEmail() : null,
        ]);
    }

    public function status(DeliveryNoteStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
