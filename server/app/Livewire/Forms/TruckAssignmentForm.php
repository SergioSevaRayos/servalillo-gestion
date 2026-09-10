<?php

namespace App\Livewire\Forms;

use App\Models\TruckAssignment;
use Illuminate\Support\Facades\Auth;
use Livewire\Form;

class TruckAssignmentForm extends Form
{
    public ?TruckAssignment $editing = null;

    public ?int $truck_id = null;

    public ?int $driver_id = null;

    public string $valid_from = '';

    public ?string $valid_until = null;

    public function rules(): array
    {
        $ignoreId = $this->editing?->id;

        return [
            'truck_id' => [
                'required',
                'exists:trucks,id',
                function ($attribute, $value, $fail) use ($ignoreId) {
                    if (TruckAssignment::overlaps('truck_id', (int) $value, $this->valid_from, $this->valid_until, $ignoreId)) {
                        $fail('Ese camión ya tiene otra asignación en fechas que se solapan.');
                    }
                },
            ],
            'driver_id' => [
                'required',
                'exists:drivers,id',
                function ($attribute, $value, $fail) use ($ignoreId) {
                    if (TruckAssignment::overlaps('driver_id', (int) $value, $this->valid_from, $this->valid_until, $ignoreId)) {
                        $fail('Ese chofer ya tiene otra asignación en fechas que se solapan.');
                    }
                },
            ],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }

    public function setAssignment(TruckAssignment $assignment): void
    {
        $this->editing = $assignment;
        $this->truck_id = $assignment->truck_id;
        $this->driver_id = $assignment->driver_id;
        $this->valid_from = $assignment->valid_from->toDateString();
        $this->valid_until = $assignment->valid_until?->toDateString();
    }

    public function save(): TruckAssignment
    {
        $validated = $this->validate();

        if ($this->editing) {
            $this->editing->update($validated);
            $assignment = $this->editing;
        } else {
            $assignment = TruckAssignment::create([
                ...$validated,
                'created_by' => Auth::id(),
            ]);
        }

        $this->reset();

        return $assignment;
    }
}
