<?php

namespace App\Livewire\Forms;

use App\Models\Truck;
use Illuminate\Validation\Rule;
use Livewire\Form;

class TruckForm extends Form
{
    public ?Truck $editing = null;

    public string $plate = '';
    public string $code = '';
    public ?string $description = null;
    public ?int $capacity_liters = null;
    public ?int $compartments = null;
    public ?string $model = null;
    public ?int $year = null;
    public int $odometer = 0;
    public bool $is_active = true;
    public ?string $notes = null;

    public function rules(): array
    {
        $truckId = $this->editing?->id;

        return [
            'plate' => ['required', 'string', 'max:20', Rule::unique('trucks', 'plate')->ignore($truckId)],
            'code' => ['required', 'string', 'max:20', Rule::unique('trucks', 'code')->ignore($truckId)],
            'description' => ['nullable', 'string', 'max:255'],
            'capacity_liters' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'compartments' => ['nullable', 'integer', 'min:1', 'max:20'],
            'model' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'min:1980', 'max:'.(date('Y') + 1)],
            'odometer' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function setTruck(Truck $truck): void
    {
        $this->editing = $truck;
        $this->plate = $truck->plate;
        $this->code = $truck->code;
        $this->description = $truck->description;
        $this->capacity_liters = $truck->capacity_liters;
        $this->compartments = $truck->compartments;
        $this->model = $truck->model;
        $this->year = $truck->year;
        $this->odometer = $truck->odometer;
        $this->is_active = $truck->is_active;
        $this->notes = $truck->notes;
    }

    public function save(): Truck
    {
        $validated = $this->validate();

        $truck = $this->editing
            ? tap($this->editing)->update($validated)
            : Truck::create($validated);

        $this->reset();

        return $truck;
    }
}
