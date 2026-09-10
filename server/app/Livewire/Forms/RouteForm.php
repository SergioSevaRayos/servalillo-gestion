<?php

namespace App\Livewire\Forms;

use App\Enums\ServiceKind;
use App\Models\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Form;

class RouteForm extends Form
{
    public ?Route $editing = null;

    public ?int $truck_id = null;

    public ?int $driver_id = null;

    public string $service_kind = 'reparto';

    public string $valid_from = '';

    public ?string $valid_until = null;

    public ?string $name = null;

    public ?string $notes = null;

    public function rules(): array
    {
        $ignoreId = $this->editing?->id;

        return [
            'truck_id' => [
                'required',
                'exists:trucks,id',
                function ($attribute, $value, $fail) use ($ignoreId) {
                    if (Route::overlaps('truck_id', (int) $value, $this->valid_from, $this->valid_until, $ignoreId)) {
                        $fail('Ese camión ya tiene otra ruta en fechas que se solapan.');
                    }
                },
            ],
            'driver_id' => [
                'required',
                'exists:drivers,id',
                function ($attribute, $value, $fail) use ($ignoreId) {
                    if (Route::overlaps('driver_id', (int) $value, $this->valid_from, $this->valid_until, $ignoreId)) {
                        $fail('Ese chofer ya tiene otra ruta en fechas que se solapan.');
                    }
                },
            ],
            'service_kind' => ['required', Rule::enum(ServiceKind::class)],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function setRoute(Route $route): void
    {
        $this->editing = $route;
        $this->truck_id = $route->truck_id;
        $this->driver_id = $route->driver_id;
        $this->service_kind = $route->service_kind->value;
        $this->valid_from = $route->valid_from->toDateString();
        $this->valid_until = $route->valid_until?->toDateString();
        $this->name = $route->name;
        $this->notes = $route->notes;
    }

    public function save(): Route
    {
        $validated = $this->validate();

        if ($this->editing) {
            $this->editing->update($validated);
            $route = $this->editing;
        } else {
            $route = Route::create([
                ...$validated,
                'created_by' => Auth::id(),
            ]);
        }

        $this->reset();

        return $route;
    }
}
