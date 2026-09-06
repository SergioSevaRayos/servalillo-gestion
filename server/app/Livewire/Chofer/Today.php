<?php

namespace App\Livewire\Chofer;

use App\Enums\OdometerKind;
use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Livewire\Forms\StopActionForm;
use App\Models\Route;
use App\Models\RouteStop;
use App\Services\DeliveryTypeSchemaValidator;
use App\Services\OdometerService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Web operativa del chofer: su ruta de hoy en una sola columna de paradas.
 * Alto contraste, mobile-first, sin glassmorphism (uso al aire libre).
 */
#[Layout('layouts.app')]
class Today extends Component
{
    public StopActionForm $form;

    /** Lectura de odómetro que se está introduciendo (inicio/fin de jornada). */
    public ?int $odometer = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('routes.view.own'), 403);
    }

    #[Computed]
    public function route(): ?Route
    {
        $driver = auth()->user()->driver;

        if (! $driver) {
            return null;
        }

        return Route::query()
            ->with(['truck', 'stops.deliveryType', 'odometerReadings'])
            ->where('driver_id', $driver->id)
            ->whereDate('route_date', today())
            ->orderByDesc('id')
            ->first();
    }

    #[Computed]
    public function started(): bool
    {
        return $this->route
            && in_array($this->route->status, [RouteStatus::InProgress, RouteStatus::Completed], true);
    }

    #[Computed]
    public function finished(): bool
    {
        return $this->route?->status === RouteStatus::Completed;
    }

    #[Computed]
    public function pendingCount(): int
    {
        return $this->route
            ? $this->route->stops->where('status', RouteStopStatus::Pending)->count()
            : 0;
    }

    public function openStop(RouteStop $stop): void
    {
        $this->authorize('complete', $stop);

        $this->form->setStop($stop);
        $this->dispatch('open-modal', 'stop-action');
    }

    public function saveStop(): void
    {
        $this->guardStarted();
        $this->authorize('complete', $this->form->stop);

        $this->form->apply(app(DeliveryTypeSchemaValidator::class));

        unset($this->route);
        $this->dispatch('close-modal', 'stop-action');
        $this->dispatch('toast', message: 'Parada actualizada.', variant: 'success');
    }

    public function reopenStop(): void
    {
        $this->guardStarted();
        $this->authorize('complete', $this->form->stop);

        $this->form->reopen();

        unset($this->route);
        $this->dispatch('close-modal', 'stop-action');
        $this->dispatch('toast', message: 'Parada reabierta.', variant: 'success');
    }

    public function openStartDay(): void
    {
        $this->authorizeRoute();
        $this->odometer = $this->route->truck?->odometer;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'start-day');
    }

    public function startDay(OdometerService $odometers): void
    {
        $this->authorizeRoute();

        $value = $this->validateOdometer();

        $this->runOdometer(fn () => $odometers->recordStart($this->route, $value));
        $this->route->update(['status' => RouteStatus::InProgress, 'started_at' => now()]);

        unset($this->route);
        $this->odometer = null;
        $this->dispatch('close-modal', 'start-day');
        $this->dispatch('toast', message: 'Jornada iniciada.', variant: 'success');
    }

    public function openEndDay(): void
    {
        $this->authorizeRoute();

        $readings = $this->route->odometerReadings->pluck('value', 'kind.value');

        // Prellenar con: lectura de fin ya guardada > lectura de inicio > odómetro del camión.
        // Nunca por debajo del inicio (la lectura de fin siempre es >= la de inicio).
        $this->odometer = $readings[OdometerKind::End->value]
            ?? $readings[OdometerKind::Start->value]
            ?? $this->route->truck?->odometer;

        $this->resetErrorBag();
        $this->dispatch('open-modal', 'end-day');
    }

    public function endDay(OdometerService $odometers): void
    {
        $this->authorizeRoute();

        $value = $this->validateOdometer();

        $this->runOdometer(fn () => $odometers->recordEnd($this->route, $value));
        $this->route->update(['status' => RouteStatus::Completed, 'completed_at' => now()]);

        unset($this->route);
        $this->odometer = null;
        $this->dispatch('close-modal', 'end-day');
        $this->dispatch('toast', message: 'Jornada finalizada.', variant: 'success');
    }

    private function authorizeRoute(): void
    {
        abort_unless($this->route !== null, 404);
        $this->authorize('operate', $this->route);
    }

    private function guardStarted(): void
    {
        if (! $this->started || $this->finished) {
            abort(403, 'La jornada no está en curso.');
        }
    }

    private function validateOdometer(): int
    {
        $data = validator(
            ['odometer' => $this->odometer],
            ['odometer' => ['required', 'integer', 'min:0']],
            ['odometer.required' => 'Introduce la lectura del contador.'],
        )->validate();

        return (int) $data['odometer'];
    }

    /** Ejecuta una acción del OdometerService reetiquetando sus errores al campo `odometer` del modal. */
    private function runOdometer(callable $action): void
    {
        try {
            $action();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([
                'odometer' => collect($e->errors())->flatten()->all(),
            ]);
        }
    }

    public function render()
    {
        return view('livewire.chofer.today');
    }
}
