<?php

namespace App\Livewire\Chofer;

use App\Enums\OdometerKind;
use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Livewire\Forms\StopActionForm;
use App\Models\Route;
use App\Models\RouteStop;
use App\Services\DeliveryNoteService;
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

    /** Litros cargados en la cisterna (modal de empezar jornada). */
    public ?int $tankLoaded = null;

    /** Litros que quedan en la cisterna (modal de terminar jornada). */
    public ?int $tankRemaining = null;

    /** Motivo del ajuste si los litros no cuadran. */
    public ?string $tankNote = null;

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

    /**
     * Estado de la cisterna: cargado, entregado a clientes y lo que debería quedar.
     *
     * @return array{loaded: ?int, delivered: float, theoretical: ?float, has: bool}
     */
    #[Computed]
    public function tank(): array
    {
        $route = $this->route;
        $delivered = $route ? $route->deliveredLiters() : 0.0;

        return [
            'loaded' => $route?->tank_loaded_liters,
            'delivered' => $delivered,
            'theoretical' => $route?->tankTheoreticalRemaining(),
            'has' => $route?->tank_loaded_liters !== null,
        ];
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

        $this->form->apply(app(DeliveryTypeSchemaValidator::class), app(DeliveryNoteService::class));

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
        $this->tankLoaded = $this->route->truck?->capacity_liters;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'start-day');
    }

    public function startDay(OdometerService $odometers): void
    {
        $this->authorizeRoute();
        $this->resetValidation();

        $value = $this->validateOdometer();
        $loaded = $this->validateInt('tankLoaded', 'Introduce los litros cargados en la cisterna.');

        $this->runOdometer(fn () => $odometers->recordStart($this->route, $value));
        $this->route->update([
            'status' => RouteStatus::InProgress,
            'started_at' => now(),
            'tank_loaded_liters' => $loaded,
        ]);

        unset($this->route);
        $this->odometer = $this->tankLoaded = null;
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

        // Prellenar los litros restantes con lo que "debería" quedar: así, si todo cuadra,
        // el chofer solo confirma; si midió otra cosa, lo cambia y salta el aviso.
        $this->tankRemaining = $this->route->tank_remaining_liters
            ?? (int) round($this->route->tankTheoreticalRemaining() ?? 0);
        $this->tankNote = $this->route->tank_reconciliation_note;

        $this->resetErrorBag();
        $this->dispatch('open-modal', 'end-day');
    }

    /** Litros que faltan (>0) o sobran (<0) según lo introducido en el modal de terminar jornada. */
    #[Computed]
    public function endDayDiscrepancy(): ?float
    {
        $theoretical = $this->route?->tankTheoreticalRemaining();

        return ($theoretical === null || $this->tankRemaining === null)
            ? null
            : $theoretical - $this->tankRemaining;
    }

    public function endDay(OdometerService $odometers): void
    {
        $this->authorizeRoute();
        $this->resetValidation();

        $value = $this->validateOdometer();
        $remaining = $this->validateInt('tankRemaining', 'Introduce los litros que quedan en la cisterna.');

        $discrepancy = ($this->route->tankTheoreticalRemaining() ?? 0) - $remaining;
        $tolerance = (int) config('servalillo.tank_tolerance_liters', 0);
        $adjusted = abs($discrepancy) > $tolerance;

        // No cuadra y no hay explicación → se bloquea y se avisa (misma "notificación" en la vista).
        if ($adjusted && blank($this->tankNote)) {
            throw ValidationException::withMessages([
                'tankNote' => 'Los datos no coinciden. Explica el ajuste para poder cerrar la jornada.',
            ]);
        }

        $this->runOdometer(fn () => $odometers->recordEnd($this->route, $value));
        $this->route->update([
            'status' => RouteStatus::Completed,
            'completed_at' => now(),
            'tank_remaining_liters' => $remaining,
            'tank_reconciliation_note' => $adjusted ? trim($this->tankNote) : null,
        ]);

        unset($this->route);
        $this->odometer = $this->tankRemaining = null;
        $this->tankNote = null;
        $this->dispatch('close-modal', 'end-day');
        $this->dispatch('toast',
            message: $adjusted
                ? sprintf('Jornada finalizada con un ajuste de %s L.', number_format(abs($discrepancy), 0, ',', '.'))
                : 'Jornada finalizada.',
            variant: $adjusted ? 'warning' : 'success',
        );
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
        return $this->validateInt('odometer', 'Introduce la lectura del contador.');
    }

    private function validateInt(string $field, string $requiredMessage): int
    {
        $data = validator(
            [$field => $this->{$field}],
            [$field => ['required', 'integer', 'min:0', 'max:9999999']],
            ["{$field}.required" => $requiredMessage],
        )->validate();

        return (int) $data[$field];
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
