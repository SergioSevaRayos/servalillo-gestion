<?php

namespace App\Livewire\Forms;

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Enums\PriceType;
use App\Enums\ServiceKind;
use App\Enums\WaterType;
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

    public string $service_kind = 'reparto';

    /** 'customer' (cliente real) | 'prospect' (pendiente valoración). */
    public string $status = 'customer';

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

    // Datos del suministro
    public ?string $water_type = null;

    /** Número que teclea el operario, en la unidad elegida. NO es columna: se convierte a litros al guardar. */
    public ?float $quantity_input = null;

    /** 'L' | 'm3' — unidad en que el cliente citó la cantidad. */
    public string $quantity_unit = 'L';

    public ?int $tank_distance_m = null;

    // Reparto habitual
    public ?int $frequency_days = null;

    /** @var array<int, int> días ISO (1..7) de reparto fijo */
    public array $delivery_weekdays = [];

    public ?string $schedule_starts_on = null;

    public ?string $schedule_ends_on = null;

    public ?int $tank_capacity_liters = null;

    public bool $requires_own_pump = false;

    public ?string $preferred_channel = null;

    public ?float $price = null;

    public string $price_type = 'per_liter';

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
            'service_kind' => ['required', Rule::enum(ServiceKind::class)],
            'status' => ['required', Rule::enum(ClientStatus::class)],
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
            'water_type' => ['nullable', Rule::enum(WaterType::class)],
            'quantity_input' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'quantity_unit' => ['required', 'in:L,m3'],
            'tank_distance_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'frequency_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'delivery_weekdays' => ['array'],
            'delivery_weekdays.*' => ['integer', 'between:1,7'],
            'schedule_starts_on' => ['nullable', 'date'],
            'schedule_ends_on' => ['nullable', 'date', 'after_or_equal:schedule_starts_on'],
            'tank_capacity_liters' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'requires_own_pump' => ['boolean'],
            'preferred_channel' => ['nullable', 'in:email,physical'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'price_type' => ['required', Rule::enum(PriceType::class)],
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
            if (str_contains($field, '.')) {
                continue; // reglas de elementos de array (delivery_weekdays.*)
            }

            $this->{$field} = match ($field) {
                'client_type' => $client->client_type?->value,
                'service_kind' => $client->service_kind->value,
                'status' => $client->status?->value ?? 'customer',
                'water_type' => $client->water_type?->value,
                'quantity_unit' => $client->quantity_unit ?? 'L',
                'quantity_input' => $client->typical_quantity === null
                    ? null
                    : ($client->quantity_unit === 'm3'
                        ? (float) $client->typical_quantity / 1000
                        : (float) $client->typical_quantity),
                'delivery_weekdays' => $client->deliveryWeekdays(),
                'schedule_starts_on' => $client->schedule_starts_on?->toDateString(),
                'schedule_ends_on' => $client->schedule_ends_on?->toDateString(),
                'last_served_on' => $client->last_served_on?->toDateString(),
                'price' => $client->price !== null ? (float) $client->price : null,
                'price_type' => $client->price_type?->value ?? 'per_liter',
                'latitude', 'longitude' => $client->{$field} !== null ? (string) $client->{$field} : null,
                default => $client->{$field},
            };
        }
    }

    public function save(): Client
    {
        $validated = $this->validate();

        // La cantidad se captura como número + unidad, pero en BD va siempre en litros.
        $liters = $validated['quantity_input'] === null
            ? null
            : ($validated['quantity_unit'] === 'm3'
                ? $validated['quantity_input'] * 1000
                : $validated['quantity_input']);

        unset($validated['quantity_input']); // no es columna
        $validated['typical_quantity'] = $liters; // sí lo es

        // Sin importe no tiene sentido guardar el tipo de precio.
        if ($validated['price'] === null) {
            $validated['price_type'] = null;
        }

        // Calendario por días: normaliza (ordena, quita duplicados) o lo deja en null si no hay días.
        $weekdays = collect($validated['delivery_weekdays'] ?? [])->map(fn ($d) => (int) $d)->unique()->sort()->values()->all();
        $validated['delivery_weekdays'] = $weekdays === [] ? null : $weekdays;

        if ($weekdays === []) {
            $validated['schedule_starts_on'] = null;
            $validated['schedule_ends_on'] = null;
        }

        $client = $this->editing
            ? tap($this->editing)->update($validated)
            : Client::create($validated);

        $this->reset();

        return $client;
    }

    /** Marca/desmarca un día de reparto (1 = lunes … 7 = domingo). */
    public function toggleWeekday(int $day): void
    {
        if ($day < 1 || $day > 7) {
            return;
        }

        $days = array_map('intval', $this->delivery_weekdays);

        $this->delivery_weekdays = in_array($day, $days, true)
            ? array_values(array_diff($days, [$day]))
            : [...$days, $day];

        sort($this->delivery_weekdays);
    }
}
