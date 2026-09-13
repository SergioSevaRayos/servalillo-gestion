<?php

namespace App\Livewire\Forms;

use App\Models\Client;
use App\Models\Route;
use App\Services\ClientDeliveryPlanner;
use Illuminate\Support\Carbon;
use Livewire\Form;

/**
 * "Planificar reparto" ampliado (2026-09-13) — ver App\Services\ClientDeliveryPlanner para el
 * diseño completo. Compartido entre `Clients\Index` (botón por fila) y `Clients\Show` (ficha).
 */
class ClientPlanForm extends Form
{
    public ?Client $client = null;

    /** Vacío = "Sin asignar" (mismo comportamiento que el botón de siempre). */
    public ?string $route_id = null;

    public string $starts_on = '';

    public string $ends_on = '';

    /** @var list<int> */
    public array $weekdays = [];

    public function setClient(Client $client): void
    {
        $this->client = $client;
        $this->route_id = null;
        $this->starts_on = today()->toDateString();
        $this->ends_on = today()->addMonth()->toDateString();
        $this->weekdays = [];
    }

    public function toggleWeekday(int $day): void
    {
        $this->weekdays = in_array($day, $this->weekdays, true)
            ? array_values(array_diff($this->weekdays, [$day]))
            : [...$this->weekdays, $day];
    }

    public function rules(): array
    {
        return [
            'route_id' => ['nullable', 'exists:routes,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
        ];
    }

    public function messages(): array
    {
        return [
            'weekdays.required' => 'Marca al menos un día de la semana.',
        ];
    }

    /** @return int paradas creadas */
    public function save(): int
    {
        $validated = $this->validate();

        // Rango generoso pero acotado: sin tope, un despiste (p. ej. el año equivocado) podría
        // generar miles de paradas de golpe.
        abort_if(Carbon::parse($validated['starts_on'])->diffInDays(Carbon::parse($validated['ends_on'])) > 366, 422, 'El rango no puede superar 1 año.');

        $route = $validated['route_id'] ? Route::findOrFail($validated['route_id']) : null;

        return app(ClientDeliveryPlanner::class)->plan(
            $this->client,
            $route,
            Carbon::parse($validated['starts_on']),
            Carbon::parse($validated['ends_on']),
            $validated['weekdays'],
        );
    }
}
