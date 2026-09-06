<?php

namespace Database\Factories;

use App\Models\DeliveryType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeliveryType>
 */
class DeliveryTypeFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Reparto estándar', 'Suministro industrial', 'Entrega programada']);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'is_active' => true,
            'field_schema' => [],
        ];
    }
}
