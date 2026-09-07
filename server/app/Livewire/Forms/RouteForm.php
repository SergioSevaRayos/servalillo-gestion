<?php

namespace App\Livewire\Forms;

use App\Enums\RouteStatus;
use App\Enums\ServiceKind;
use App\Models\Route;
use App\Models\Truck;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Form;

class RouteForm extends Form
{
    public ?Route $editing = null;

    public string $route_date = '';

    public ?int $truck_id = null;

    public ?int $driver_id = null;

    public string $status = 'draft';

    public string $service_kind = 'reparto';

    public ?string $name = null;

    public ?string $notes = null;

    public function rules(): array
    {
        $routeId = $this->editing?->id;

        return [
            'route_date' => ['required', 'date'],
            'truck_id' => [
                'required',
                'exists:trucks,id',
                Rule::unique('routes', 'truck_id')
                    ->where(fn ($q) => $q->where('route_date', $this->route_date))
                    ->ignore($routeId),
            ],
            'driver_id' => ['required', 'exists:drivers,id'],
            'status' => ['required', Rule::enum(RouteStatus::class)],
            'service_kind' => ['required', Rule::enum(ServiceKind::class)],
            'name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'truck_id.unique' => 'Ese camión ya tiene una ruta asignada ese día (1 camión = 1 ruta/día).',
        ];
    }

    public function setRoute(Route $route): void
    {
        $this->editing = $route;
        $this->route_date = $route->route_date->toDateString();
        $this->truck_id = $route->truck_id;
        $this->driver_id = $route->driver_id;
        $this->status = $route->status->value;
        $this->service_kind = $route->service_kind->value;
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
            $truckCode = Truck::find($validated['truck_id'])->code;
            $route = Route::create([
                ...$validated,
                'code' => 'R-'.str_replace('-', '', $validated['route_date']).'-'.$truckCode,
                'created_by' => Auth::id(),
            ]);
        }

        $this->reset();

        return $route;
    }
}
