<?php

namespace App\Livewire\Routes;

use App\Enums\RouteStopStatus;
use App\Enums\ServiceKind;
use App\Livewire\Forms\RouteStopForm;
use App\Models\DeliveryType;
use App\Models\Route;
use App\Models\RouteStop;
use App\Services\DeliveryTypeSchemaValidator;
use App\Services\RecurringStopService;
use App\Services\RouteGeometry;
use App\Services\RouteOptimizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Board extends Component
{
    public RouteStopForm $form;

    #[Url(history: true)]
    public string $date = '';

    /** Filtro de la vista: 'reparto' (por defecto) o 'viaje'. */
    #[Url(history: true)]
    public string $kind = 'reparto';

    public function mount(): void
    {
        $this->authorize('viewAny', Route::class);

        $this->date = $this->date ?: now()->toDateString();

        if (! in_array($this->kind, array_column(ServiceKind::cases(), 'value'), true)) {
            $this->kind = ServiceKind::Reparto->value;
        }

        $this->generateRecurringStops();
    }

    public function updatedDate(): void
    {
        $this->generateRecurringStops();
    }

    /** Crea las paradas de los clientes con calendario fijo para el día que se está viendo. */
    private function generateRecurringStops(): void
    {
        if (auth()->user()?->can('routes.update')) {
            app(RecurringStopService::class)->generateForDate(Carbon::parse($this->date));
        }
    }

    public function setKind(string $kind): void
    {
        $this->kind = ServiceKind::tryFrom($kind)?->value ?? ServiceKind::Reparto->value;
    }

    public function previousDay(): void
    {
        $this->date = Carbon::parse($this->date)->subDay()->toDateString();
        $this->generateRecurringStops();
    }

    public function nextDay(): void
    {
        $this->date = Carbon::parse($this->date)->addDay()->toDateString();
        $this->generateRecurringStops();
    }

    public function today(): void
    {
        $this->date = now()->toDateString();
        $this->generateRecurringStops();
    }

    public function openCreateStop(?int $routeId): void
    {
        $this->authorize('create', RouteStop::class);

        $this->form->forColumn($routeId, $this->kind);
        $this->dispatch('open-modal', 'stop-form');
    }

    public function openEditStop(RouteStop $stop): void
    {
        $this->authorize('update', $stop);

        $this->form->setStop($stop);
        $this->dispatch('open-modal', 'stop-form');
    }

    public function saveStop(): void
    {
        $this->form->editing
            ? $this->authorize('update', $this->form->editing)
            : $this->authorize('create', RouteStop::class);

        $this->form->save(app(DeliveryTypeSchemaValidator::class));

        $this->dispatch('close-modal', 'stop-form');
        $this->dispatch('toast', message: 'Parada guardada correctamente.', variant: 'success');
    }

    public function deleteStop(RouteStop $stop): void
    {
        $this->authorize('delete', $stop);

        $stop->delete();

        $this->dispatch('close-modal', 'stop-form');
        $this->dispatch('toast', message: 'Parada eliminada.', variant: 'success');
    }

    /**
     * Persiste un arrastre (SortableJS): reordena la columna destino y, si el arrastre
     * cruzó de columna, reindexa también la de origen. $toRouteId null = "Sin asignar".
     *
     * @param  array<int, int|string>  $fromStopIds
     * @param  array<int, int|string>  $toStopIds
     */
    public function reorderStops(?int $fromRouteId, array $fromStopIds, ?int $toRouteId, array $toStopIds): void
    {
        abort_unless(auth()->user()->can('routes.reorder_stops'), 403);

        if ($toRouteId !== null) {
            abort_unless(Route::whereKey($toRouteId)->exists(), 422, 'Ruta destino inválida.');
        }

        $allIds = array_unique([...$fromStopIds, ...$toStopIds]);
        $stops = RouteStop::whereIn('id', $allIds)->get()->keyBy('id');
        abort_unless($stops->count() === count($allIds), 422, 'Alguna parada ya no existe.');

        // Una parada cerrada (completada/omitida/fallida) NO se puede reasignar a otra ruta ni
        // sacar/meter en "Sin asignar" — el filtro de SortableJS ya lo impide en el cliente, pero
        // nunca hay que confiar solo en eso. Su `position` SÍ puede desplazarse cuando se reordenan
        // las paradas pendientes de alrededor (es solo orden de visualización).
        DB::transaction(function () use ($fromRouteId, $fromStopIds, $toRouteId, $toStopIds, $stops) {
            foreach (array_values($toStopIds) as $index => $stopId) {
                $stop = $stops[$stopId];
                $newPosition = $index + 1;
                $changingRoute = $stop->route_id !== $toRouteId;

                if (! $changingRoute && $stop->position === $newPosition) {
                    continue;
                }

                abort_if($changingRoute && $stop->status !== RouteStopStatus::Pending, 422, 'Solo se pueden mover paradas pendientes.');

                $stop->update(['route_id' => $toRouteId, 'position' => $newPosition]);
            }

            if ($fromRouteId !== $toRouteId) {
                foreach (array_values($fromStopIds) as $index => $stopId) {
                    $stop = $stops[$stopId];
                    $newPosition = $index + 1;

                    if ($stop->position !== $newPosition) {
                        $stop->update(['position' => $newPosition]);
                    }
                }
            }
        });
    }

    /** "Ruta eficiente": reordena las paradas pendientes de una ruta para acortar el recorrido. */
    public function optimizeRoute(int $routeId): void
    {
        $route = Route::findOrFail($routeId);
        $this->authorize('reorderStops', $route);

        $optimizer = app(RouteOptimizer::class);
        $this->dispatch('toast', ...$optimizer->toast($optimizer->optimize($route)));
    }

    /** "Ver recorrido": abre el mapa con las paradas de la ruta y su trazado. */
    public function showRouteMap(int $routeId): void
    {
        $route = Route::findOrFail($routeId);
        $this->authorize('view', $route);

        $this->dispatch('open-route-map', ...app(RouteGeometry::class)->payloadFor($route));
    }

    #[Computed]
    public function deliveryTypes()
    {
        return DeliveryType::where('is_active', true)->get();
    }

    #[Computed]
    public function selectedDeliveryType(): ?DeliveryType
    {
        return $this->deliveryTypes->firstWhere('id', (int) $this->form->delivery_type_id);
    }

    public function render()
    {
        $routes = Route::query()
            ->with(['truck', 'driver.user', 'stops.deliveryType'])
            ->whereDate('route_date', $this->date)
            ->where('service_kind', $this->kind)
            ->get()
            ->sortBy(fn (Route $route) => $route->truck->code);

        // "Sin asignar": backlog sin fecha + las recurrentes generadas para el día que se ve.
        $unassigned = RouteStop::query()
            ->unassigned()
            ->where('service_kind', $this->kind)
            ->where(fn ($q) => $q->whereNull('scheduled_for')->orWhereDate('scheduled_for', $this->date))
            ->with('deliveryType')
            ->orderBy('position')
            ->get();

        return view('livewire.routes.board', [
            'routes' => $routes,
            'unassigned' => $unassigned,
            'kinds' => ServiceKind::cases(),
        ]);
    }
}
