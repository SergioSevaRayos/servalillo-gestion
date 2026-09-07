<?php

namespace Database\Factories;

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Enums\ServiceKind;
use App\Enums\WaterType;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        $city = fake()->randomElement(['Santa Cruz de Tenerife', 'La Laguna', 'La Orotava', 'Adeje', 'Granadilla', 'Arona', 'Güímar', 'Tegueste']);

        return [
            'external_ref' => 'AX-'.fake()->unique()->numberBetween(1000, 99999),
            'name' => fake()->randomElement([fake()->company(), fake()->lastName().' '.fake()->lastName()]),
            'tax_id' => strtoupper(fake()->bothify(fake()->randomElement(['?########', '########?']))),
            'client_type' => fake()->randomElement(ClientType::cases())->value,
            'service_kind' => ServiceKind::Reparto->value,
            'status' => ClientStatus::Customer->value,
            'water_type' => fake()->optional(0.5)->randomElement([WaterType::Corriente->value, WaterType::Potable->value]),
            'quantity_unit' => 'L',
            'tank_distance_m' => fake()->optional(0.4)->randomElement([5, 10, 15, 20, 30, 50]),
            'contact_name' => fake()->name(),
            'phone' => fake()->numerify('6## ### ###'),
            'email' => fake()->optional(0.6)->safeEmail(),
            'address' => fake()->streetAddress(),
            'postal_code' => fake()->numerify('380##'),
            'city' => $city,
            'province' => 'Santa Cruz de Tenerife',
            'latitude' => fake()->latitude(28.0, 28.6),
            'longitude' => fake()->longitude(-16.9, -16.1),
            'typical_quantity' => fake()->randomElement([300, 500, 800, 1000, 1500, 2000, 3000]),
            'frequency_days' => fake()->optional(0.6)->randomElement([7, 14, 15, 21, 30, 45, 60]),
            'delivery_weekdays' => null,
            'tank_capacity_liters' => fake()->optional(0.7)->randomElement([1000, 2000, 3000, 5000, 10000]),
            'requires_own_pump' => fake()->boolean(20),
            'preferred_channel' => fake()->randomElement(['email', 'physical']),
            'price_type' => $priceType = fake()->randomElement(['per_liter', 'fixed']),
            'price' => fake()->optional(0.5)->randomElement($priceType === 'fixed' ? [35, 45, 60, 90, 120] : [0.85, 0.95, 1.05, 1.20]),
            'payment_terms' => fake()->randomElement(['Contado', 'Transferencia 30 días', 'Domiciliado']),
            'last_served_on' => fake()->optional(0.8)->dateTimeBetween('-60 days', '-2 days'),
            'access_notes' => fake()->optional(0.4)->randomElement([
                'Portón azul al fondo del camino, llamar antes de llegar.',
                'Acceso por la parte trasera; el depósito está tras el garaje.',
                'Carretera estrecha, no entra el camión grande.',
                'Preguntar por el encargado en recepción.',
            ]),
            'notes' => fake()->optional(0.3)->sentence(),
            'is_active' => fake()->boolean(92),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function trip(): static
    {
        return $this->state(fn () => ['service_kind' => ServiceKind::Viaje->value]);
    }

    /** Cliente con calendario fijo por días de la semana. */
    public function weekly(array $weekdays = [1, 3, 5]): static
    {
        return $this->state(fn () => [
            'delivery_weekdays' => $weekdays,
            'frequency_days' => null,
            'is_active' => true,
        ]);
    }

    /** Pre-cliente "Pendiente valoración": solo lo básico de la llamada. */
    public function prospect(): static
    {
        return $this->state(fn () => [
            'status' => ClientStatus::Prospect->value,
            'external_ref' => null,
            'tax_id' => null,
            'client_type' => null,
            'frequency_days' => null,
            'delivery_weekdays' => null,
            'last_served_on' => null,
            'is_active' => true,
        ]);
    }
}
