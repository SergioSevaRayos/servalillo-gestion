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
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Web operativa del chofer: la ruta de un día concreto (por defecto hoy) en una columna
 * de paradas, con selector de día. Alto contraste, mobile-first, sin glassmorphism.
 *
 * Solo se puede *operar* (empezar/terminar jornada, cerrar paradas) la ruta de hoy o una
 * que quedó `InProgress` (p. ej. cerrar la de anoche). Los otros días son solo lectura.
 */
#[Layout('layouts.app')]
class Today extends Component
{
    public StopActionForm $form;

    /** Día que se está viendo (YYYY-MM-DD). */
    #[Url]
    public string $date = '';

    /** Lectura de odómetro que se está introduciendo (inicio/fin de jornada). */
    public ?int $odometer = null;

    /** Lectura del contador de litros al empezar la jornada. */
    public ?int $meterStart = null;

    /** Lectura del contador de litros al terminar la jornada. */
    public ?int $meterEnd = null;

    /** Motivo del ajuste si el contador no cuadra con lo repartido. */
    public ?string $meterNote = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('routes.view.own'), 403);

        $this->date = $this->normalizeDate($this->date);
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
            ->whereDate('route_date', $this->date)
            ->orderByDesc('id')
            ->first();
    }

    #[Computed]
    public function isToday(): bool
    {
        return $this->date === today()->toDateString();
    }

    /** ¿Se puede operar la ruta que se está viendo? (hoy, o una que quedó a medias). */
    #[Computed]
    public function operable(): bool
    {
        return $this->isToday() || $this->route?->status === RouteStatus::InProgress;
    }

    /** Los 7 días (lunes→domingo) de la semana del día seleccionado, para el selector. */
    #[Computed]
    public function weekDays(): array
    {
        $monday = Carbon::parse($this->date)->startOfWeek(Carbon::MONDAY);

        return collect(range(0, 6))
            ->map(fn (int $i) => $monday->copy()->addDays($i))
            ->all();
    }

    public function selectDay(string $date): void
    {
        $this->date = $this->normalizeDate($date);
        unset($this->route);
    }

    public function shiftWeek(int $weeks): void
    {
        $this->date = Carbon::parse($this->date)->addWeeks($weeks)->toDateString();
        unset($this->route);
    }

    public function goToday(): void
    {
        $this->date = today()->toDateString();
        unset($this->route);
    }

    private function normalizeDate(?string $date): string
    {
        try {
            return $date ? Carbon::parse($date)->toDateString() : today()->toDateString();
        } catch (\Throwable) {
            return today()->toDateString();
        }
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
     * Contador de litros: lectura al empezar, repartido en la jornada y por dónde
     * debería ir el contador ahora mismo (inicio + repartido).
     *
     * @return array{start: ?int, delivered: float, expected: ?float, has: bool}
     */
    #[Computed]
    public function meter(): array
    {
        $route = $this->route;
        $delivered = $route ? $route->deliveredLiters() : 0.0;

        return [
            'start' => $route?->liter_meter_start,
            'delivered' => $delivered,
            'expected' => $route?->literMeterExpected(),
            'has' => $route?->liter_meter_start !== null,
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
        $this->meterStart = $this->route->truck?->liter_meter;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'start-day');
    }

    public function startDay(OdometerService $odometers): void
    {
        $this->authorizeRoute();
        $this->resetValidation();

        $value = $this->validateOdometer();
        $meter = $this->validateInt('meterStart', 'Introduce la lectura del contador de litros.');

        $this->runOdometer(fn () => $odometers->recordStart($this->route, $value));
        $this->route->update([
            'status' => RouteStatus::InProgress,
            'started_at' => now(),
            'liter_meter_start' => $meter,
        ]);

        unset($this->route);
        $this->odometer = $this->meterStart = null;
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

        // Prellenar con "por dónde debería ir" (inicio + repartido): si todo cuadra el chofer
        // solo confirma; si el contador marca otra cosa, lo cambia y salta el aviso.
        $this->meterEnd = $this->route->liter_meter_end
            ?? (int) round($this->route->literMeterExpected() ?? 0);
        $this->meterNote = $this->route->liter_discrepancy_note;

        $this->resetErrorBag();
        $this->dispatch('open-modal', 'end-day');
    }

    /** (fin introducido − inicio) − repartido. >0 = marca de más, <0 = de menos. */
    #[Computed]
    public function endMeterDiscrepancy(): ?float
    {
        $route = $this->route;

        return ($route?->liter_meter_start === null || $this->meterEnd === null)
            ? null
            : ($this->meterEnd - $route->liter_meter_start) - $route->deliveredLiters();
    }

    public function endDay(OdometerService $odometers): void
    {
        $this->authorizeRoute();
        $this->resetValidation();

        $value = $this->validateOdometer();
        $meter = $this->validateInt('meterEnd', 'Introduce la lectura del contador de litros.');

        $discrepancy = ($meter - ($this->route->liter_meter_start ?? $meter)) - $this->route->deliveredLiters();
        $tolerance = (int) config('servalillo.liter_meter_tolerance', 0);
        $adjusted = abs($discrepancy) > $tolerance;

        // No cuadra y no hay explicación → se bloquea y se avisa (misma "notificación" en la vista).
        if ($adjusted && blank($this->meterNote)) {
            throw ValidationException::withMessages([
                'meterNote' => 'El contador no cuadra con lo repartido. Explica el ajuste para poder cerrar la jornada.',
            ]);
        }

        $this->runOdometer(fn () => $odometers->recordEnd($this->route, $value));
        $this->route->update([
            'status' => RouteStatus::Completed,
            'completed_at' => now(),
            'liter_meter_end' => $meter,
            'liter_discrepancy_note' => $adjusted ? trim($this->meterNote) : null,
        ]);
        $this->route->truck?->update(['liter_meter' => $meter]);

        unset($this->route);
        $this->odometer = $this->meterEnd = null;
        $this->meterNote = null;
        $this->dispatch('close-modal', 'end-day');
        $this->dispatch('toast',
            message: $adjusted
                ? sprintf('Jornada finalizada con un ajuste de %s L en el contador.', number_format(abs($discrepancy), 0, ',', '.'))
                : 'Jornada finalizada.',
            variant: $adjusted ? 'warning' : 'success',
        );
    }

    private function authorizeRoute(): void
    {
        abort_unless($this->route !== null, 404);
        $this->authorize('operate', $this->route);
        abort_unless($this->operable(), 403, 'Solo puedes operar tu ruta activa.');
    }

    private function guardStarted(): void
    {
        $this->authorizeRoute();

        if (! $this->started || $this->finished) {
            abort(403, 'La jornada no está en curso.');
        }
    }

    private function validateOdometer(): int
    {
        return $this->validateInt('odometer', 'Introduce la lectura del cuentakilómetros.');
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
