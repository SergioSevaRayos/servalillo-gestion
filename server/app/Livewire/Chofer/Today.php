<?php

namespace App\Livewire\Chofer;

use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Livewire\Forms\StopActionForm;
use App\Models\Client;
use App\Models\DeliveryType;
use App\Models\Route;
use App\Models\RouteStop;
use App\Services\DeliveryNoteService;
use App\Services\DeliveryTypeSchemaValidator;
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

    /** Lectura del contador de litros al empezar la jornada. */
    public ?int $meterStart = null;

    /** Lectura del contador de litros al terminar la jornada. */
    public ?int $meterEnd = null;

    /** Motivo del ajuste si el contador no cuadra con lo repartido. */
    public ?string $meterNote = null;

    /** Búsqueda de cliente para añadirlo a la ruta (llamada de un cliente sobre la marcha). */
    public string $clientSearch = '';

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

    /**
     * Paradas que este chofer reprogramó para el día que se está viendo y que aún no
     * están en una ruta (oficina tiene que asignarlas). Se muestran aparte, en solo lectura.
     */
    #[Computed]
    public function rescheduledForDay()
    {
        return RouteStop::query()
            ->whereNull('route_id')
            ->whereDate('scheduled_for', $this->date)
            ->where('rescheduled_by', auth()->id())
            ->where('status', RouteStopStatus::Pending)
            ->with('deliveryType')
            ->orderBy('customer_name')
            ->get();
    }

    /** ¿Se puede operar la ruta que se está viendo? (hoy, o una que quedó a medias). */
    #[Computed]
    public function operable(): bool
    {
        return $this->isToday() || $this->route?->status === RouteStatus::InProgress;
    }

    public function selectDay(string $date): void
    {
        $this->date = $this->normalizeDate($date);
        unset($this->route);
    }

    public function shiftDay(int $days): void
    {
        $this->date = Carbon::parse($this->date)->addDays($days)->toDateString();
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

        $rescheduled = $this->form->outcome !== 'completed' && filled($this->form->reschedule_on);

        $this->form->apply(app(DeliveryTypeSchemaValidator::class), app(DeliveryNoteService::class));

        unset($this->route);
        $this->dispatch('close-modal', 'stop-action');
        $this->dispatch('toast',
            message: $rescheduled ? 'Parada reprogramada para otro día.' : 'Parada actualizada.',
            variant: 'success',
        );
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

    /** Abre el buscador para añadir un cliente que ha llamado como una parada más. */
    public function openAddStop(): void
    {
        $this->authorizeRoute();
        $this->clientSearch = '';
        $this->dispatch('open-modal', 'add-stop');
    }

    /** Clientes activos que coinciden con la búsqueda (mín. 2 caracteres). */
    #[Computed]
    public function clientMatches()
    {
        $term = trim($this->clientSearch);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        return Client::query()->customers()->active()->search($term)->orderBy('name')->limit(8)->get();
    }

    /** Añade el cliente elegido al final de la ruta como parada pendiente. */
    public function addClientStop(Client $client): void
    {
        $this->authorizeRoute();
        abort_if($this->finished, 403, 'La jornada ya está cerrada.');
        abort_if($client->isProspect(), 422, 'Ese registro es un pre-cliente sin valorar.');

        $route = $this->route;

        RouteStop::create([
            'route_id' => $route->id,
            'position' => ($route->stops()->max('position') ?? 0) + 1,
            'service_kind' => $client->service_kind->value,
            'customer_name' => $client->name,
            'customer_tax_id' => $client->tax_id,
            'address' => $client->address,
            'latitude' => $client->latitude,
            'longitude' => $client->longitude,
            'contact_name' => $client->contact_name,
            'contact_phone' => $client->phone,
            'delivery_type_id' => DeliveryType::waterId(),
            'status' => RouteStopStatus::Pending,
            'planned_quantity' => $client->typical_quantity,
            'data' => [],
        ]);

        $this->clientSearch = '';
        unset($this->route);
        $this->dispatch('close-modal', 'add-stop');
        $this->dispatch('toast', message: "{$client->name} añadido a la ruta.", variant: 'success');
    }

    public function openStartDay(): void
    {
        $this->authorizeRoute();
        $this->meterStart = $this->route->truck?->liter_meter;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'start-day');
    }

    public function startDay(): void
    {
        $this->authorizeRoute();
        $this->resetValidation();

        $meter = $this->validateMeter('meterStart', min: $this->route->truck?->liter_meter, minMessage: 'La lectura no puede ser menor que la última registrada del camión (:min).');

        $this->route->update([
            'status' => RouteStatus::InProgress,
            'started_at' => now(),
            'liter_meter_start' => $meter,
        ]);

        unset($this->route);
        $this->meterStart = null;
        $this->dispatch('close-modal', 'start-day');
        $this->dispatch('toast', message: 'Jornada iniciada.', variant: 'success');
    }

    public function openEndDay(): void
    {
        $this->authorizeRoute();

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

    public function endDay(): void
    {
        $this->authorizeRoute();
        $this->resetValidation();

        $meter = $this->validateMeter('meterEnd', min: $this->route->liter_meter_start, minMessage: 'La lectura de fin no puede ser menor que la de inicio (:min).');

        $discrepancy = ($meter - ($this->route->liter_meter_start ?? $meter)) - $this->route->deliveredLiters();
        $tolerance = (int) config('servalillo.liter_meter_tolerance', 0);
        $adjusted = abs($discrepancy) > $tolerance;

        // No cuadra y no hay explicación → se bloquea y se avisa (misma "notificación" en la vista).
        if ($adjusted && blank($this->meterNote)) {
            throw ValidationException::withMessages([
                'meterNote' => 'El contador no cuadra con lo repartido. Explica el ajuste para poder cerrar la jornada.',
            ]);
        }

        $this->route->update([
            'status' => RouteStatus::Completed,
            'completed_at' => now(),
            'liter_meter_end' => $meter,
            'liter_discrepancy_note' => $adjusted ? trim($this->meterNote) : null,
        ]);
        $this->route->truck?->update(['liter_meter' => $meter]);

        unset($this->route);
        $this->meterEnd = null;
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

    /**
     * Valida una lectura del contador de litros: entero >= 0 y, si se pasa `$min`,
     * que no sea menor (el contador nunca retrocede).
     */
    private function validateMeter(string $field, ?int $min, string $minMessage): int
    {
        $value = $this->{$field};

        validator(
            [$field => $value],
            [$field => ['required', 'integer', 'min:0', 'max:99999999']],
            ["{$field}.required" => 'Introduce la lectura del contador de litros.'],
        )->validate();

        $value = (int) $value;

        if ($min !== null && $value < $min) {
            throw ValidationException::withMessages([
                $field => str_replace(':min', number_format($min, 0, ',', '.'), $minMessage),
            ]);
        }

        return $value;
    }

    public function render()
    {
        return view('livewire.chofer.today');
    }
}
