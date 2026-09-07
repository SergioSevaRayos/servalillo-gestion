<?php

namespace App\Livewire\Forms;

use App\Enums\ClientType;
use App\Models\Client;
use Illuminate\Validation\Rule;
use Livewire\Form;

class ClientForm extends Form
{
    public ?Client $editing = null;

    // Identificación
    public ?string $external_ref = null;

    public string $name = '';

    public ?string $tax_id = null;

    public ?string $client_type = null;

    // Contacto
    public ?string $contact_name = null;

    public ?string $phone = null;

    public ?string $secondary_phone = null;

    public ?string $email = null;

    // Ubicación
    public ?string $address = null;

    public ?string $postal_code = null;

    public ?string $city = null;

    public ?string $province = null;

    public ?string $latitude = null;

    public ?string $longitude = null;

    // Reparto habitual
    public ?int $default_delivery_type_id = null;

    public ?float $typical_quantity = null;

    public ?int $frequency_days = null;

    public ?int $tank_capacity_liters = null;

    public bool $requires_own_pump = false;

    public ?string $preferred_channel = null;

    public ?float $price_per_liter = null;

    public ?string $payment_terms = null;

    public ?string $last_served_on = null;

    // Observaciones
    public ?string $access_notes = null;

    public ?string $notes = null;

    public bool $is_active = true;

    public function rules(): array
    {
        $id = $this->editing?->id;

        return [
            'external_ref' => ['nullable', 'string', 'max:60', Rule::unique('clients', 'external_ref')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'client_type' => ['nullable', Rule::enum(ClientType::class)],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'secondary_phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:120'],
            'province' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'default_delivery_type_id' => ['nullable', 'exists:delivery_types,id'],
            'typical_quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'frequency_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'tank_capacity_liters' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'requires_own_pump' => ['boolean'],
            'preferred_channel' => ['nullable', 'in:email,physical'],
            'price_per_liter' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_terms' => ['nullable', 'string', 'max:120'],
            'last_served_on' => ['nullable', 'date'],
            'access_notes' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'external_ref.unique' => 'Ya hay un cliente con ese código.',
        ];
    }

    public function setClient(Client $client): void
    {
        $this->editing = $client;

        foreach (array_keys($this->rules()) as $field) {
            $this->{$field} = match ($field) {
                'client_type' => $client->client_type?->value,
                'last_served_on' => $client->last_served_on?->toDateString(),
                'typical_quantity', 'price_per_liter' => $client->{$field} !== null ? (float) $client->{$field} : null,
                'latitude', 'longitude' => $client->{$field} !== null ? (string) $client->{$field} : null,
                default => $client->{$field},
            };
        }
    }

    public function save(): Client
    {
        $validated = $this->validate();

        $client = $this->editing
            ? tap($this->editing)->update($validated)
            : Client::create($validated);

        $this->reset();

        return $client;
    }
}
