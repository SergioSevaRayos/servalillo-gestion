<?php

namespace App\Livewire\Routes;

use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Enums\ServiceKind;
use App\Livewire\Forms\RouteStopForm;
use App\Models\Client;
use App\Models\DeliveryType;
use App\Models\Route;
use App\Models\RouteDay;
use App\Models\RouteStop;
use App\Services\DeliveryTypeSchemaValidator;
use App\Services\RecurringRouteService;
use App\Services\RecurringStopService;
use App\Services\RouteGeometry;
use App\Services\RouteOptimizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    /** Ruta que se está reordenando con "Ruta eficiente" (mientras se elige el origen). */
    public ?int $optimizingRouteId = null;

    /** Término del buscador de clientes para añadir a "Sin asignar". */
    public string $clientSearch = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Route::class);

        $this->date = $this->date ?: now()->toDateString();

        if (! in_array($this->kind, array_column(ServiceKind::cases(), 'value'), true)) {
            $this->kind = ServiceKind::Reparto->value;
        }

        $this->generateRecurringRoutes();
        $this->generateRecurringStops();
    }

    public function updatedDate(): void
    {
        $this->generateRecurringRoutes();
        $this->generateRecurringStops();
    }

    /** Crea, si falta, la ruta del día para cada asignación camión↔chofer vigente. */
    private function generateRecurringRoutes(): void
    {
        if (auth()->user()?->can('routes.update')) {
            app(RecurringRouteService::class)->generateForDate(Carbon::parse($this->date));
        }
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
        $this->generateRecurringRoutes();
        $this->generateRecurringStops();
    }

    public function nextDay(): void
    {
        $this->date = Carbon::parse($this->date)->addDay()->toDateString();
        $this->generateRecurringRoutes();
        $this->generateRecurringStops();
    }

    public function today(): void
    {
        $this->date = now()->toDateString();
        $this->generateRecurringRoutes();
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

    /**
     * Buscar un cliente ya registrado y meterlo en "Sin asignar" de un toque, sin rellenar el
     * formulario de parada — oficina solo tiene que arrastrarlo luego a la columna del camión.
     */
    public function openClientSearch(): void
    {
        $this->authorize('create', RouteStop::class);
        abort_unless(auth()->user()->can('routes.update'), 403);

        $this->clientSearch = '';
        $this->dispatch('open-modal', 'client-search');
    }

    /** Clientes reales del tipo de servicio activo que coinciden con la búsqueda (mín. 2 caracteres). */
    #[Computed]
    public function clientMatches()
    {
        $term = trim($this->clientSearch);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        return Client::query()
            ->customers()
            ->active()
            ->kind($this->kind)
            ->search($term)
            ->orderBy('name')
            ->limit(8)
            ->get();
    }

    /**
     * Añade el cliente elegido a "Sin asignar" como parada pendiente — mismo copiado de datos que
     * `Clients\Show::planDelivery()`.
     */
    public function addClientToBacklog(Client $client): void
    {
        $this->authorize('create', RouteStop::class);
        abort_unless(auth()->user()->can('routes.update'), 403);
        abort_if($client->isProspect(), 422, 'Ese registro es un pre-cliente sin valorar.');

        RouteStop::create([
            'route_id' => null,
            'position' => (RouteStop::whereNull('route_id')->max('position') ?? 0) + 1,
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
        $this->dispatch('close-modal', 'client-search');
        $this->dispatch('toast', message: $client->name.' '.__('añadido a "Sin asignar".'), variant: 'success');
    }

    public function saveStop(): void
    {
        $this->form->editing
            ? $this->authorize('update', $this->form->editing)
            : $this->authorize('create', RouteStop::class);

        // Nueva parada asignada directamente a una ruta cuya jornada ya estaba terminada
        // (mismo criterio que al arrastrar desde "Sin asignar" — ver reorderStops()).
        $reopened = ! $this->form->editing
            && $this->form->route_id !== null
            && (RouteDay::find($this->form->route_id)?->reopenIfCompleted() ?? false);

        $this->form->save(app(DeliveryTypeSchemaValidator::class));

        $this->dispatch('close-modal', 'stop-form');
        $this->dispatch('toast',
            message: $reopened
                ? 'Parada guardada. La jornada de esa ruta estaba terminada: se ha reabierto.'
                : 'Parada guardada correctamente.',
            variant: $reopened ? 'warning' : 'success',
        );
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
            abort_unless(RouteDay::whereKey($toRouteId)->exists(), 422, 'Ruta destino inválida.');
        }

        $allIds = array_unique([...$fromStopIds, ...$toStopIds]);
        $stops = RouteStop::whereIn('id', $allIds)->get()->keyBy('id');
        abort_unless($stops->count() === count($allIds), 422, 'Alguna parada ya no existe.');

        // Una parada CERRADA (completada/omitida/fallida) no se puede reasignar de ruta ni
        // sacar/meter en "Sin asignar" — el filtro de SortableJS ya lo impide en el cliente, pero
        // nunca hay que confiar solo en eso. Y tampoco se mueve dentro de su columna: conserva su
        // hueco y las pendientes se recolocan alrededor (lo hace reindexColumn()).
        foreach ($toStopIds as $stopId) {
            $stop = $stops[$stopId];

            abort_if(
                $stop->route_id !== $toRouteId && $stop->status !== RouteStopStatus::Pending,
                422,
                'Solo se pueden mover paradas pendientes.',
            );
        }

        $reopened = false;

        DB::transaction(function () use ($fromRouteId, $fromStopIds, $toRouteId, $toStopIds, $stops, &$reopened) {
            // Si la ruta destino ya tenía la jornada terminada (p. ej. se le asigna una parada
            // desde "Sin asignar" un rato después de cerrar), se reabre — mismo criterio que
            // Chofer\Today::addClientStop().
            if ($toRouteId !== null && $fromRouteId !== $toRouteId) {
                $reopened = RouteDay::find($toRouteId)?->reopenIfCompleted() ?? false;
            }

            $this->reindexColumn($toRouteId, $toStopIds, $stops);

            if ($fromRouteId !== $toRouteId) {
                $this->reindexColumn($fromRouteId, $fromStopIds, $stops);
            }
        });

        if ($reopened) {
            $this->dispatch('toast', message: 'La jornada de esa ruta estaba terminada: se ha reabierto.', variant: 'warning');
        }
    }

    /**
     * Recoloca las paradas de una columna tras un arrastre. Las paradas cerradas conservan su
     * hueco relativo al flujo de pendientes (no se mueven); las pendientes se reparten en el
     * orden en que se han soltado. $routeId null = "Sin asignar".
     *
     * @param  array<int, int|string>  $droppedOrder
     * @param  Collection<int, RouteStop>  $stops
     */
    private function reindexColumn(?int $routeId, array $droppedOrder, $stops): void
    {
        // Orden PREVIO de la columna (por `position`): fuente de verdad de dónde van las cerradas.
        $previousOrder = $stops
            ->filter(fn (RouteStop $s) => $s->route_id === $routeId)
            ->sortBy('position')
            ->values();

        // Pendientes en el orden en que las dejó el arrastre.
        $pendingQueue = collect($droppedOrder)
            ->map(fn ($id) => $stops[$id] ?? null)
            ->filter(fn (?RouteStop $s) => $s !== null && $s->status === RouteStopStatus::Pending)
            ->pluck('id')
            ->all();

        $finalOrder = [];

        foreach ($previousOrder as $stop) {
            if ($stop->status !== RouteStopStatus::Pending) {
                $finalOrder[] = $stop->id;                  // cerrada: se queda en su hueco
            } elseif ($pendingQueue !== []) {
                $finalOrder[] = array_shift($pendingQueue);  // pendiente: siguiente del arrastre
            }
            // si una pendiente se fue a otra columna, su hueco simplemente desaparece
        }

        foreach ($pendingQueue as $id) {
            $finalOrder[] = $id;                             // pendiente que llega de otra columna
        }

        foreach ($finalOrder as $index => $id) {
            $stop = $stops[$id];
            $position = $index + 1;

            if ($stop->route_id !== $routeId || $stop->position !== $position) {
                $stop->update(['route_id' => $routeId, 'position' => $position]);
            }
        }
    }

    public ?int $statusRouteId = null;

    public function openStatusModal(int $routeId): void
    {
        $route = RouteDay::findOrFail($routeId);
        $this->authorize('update', $route->route);

        $this->statusRouteId = $routeId;
        $this->dispatch('open-modal', 'route-status');
    }

    #[Computed]
    public function statusRoute(): ?RouteDay
    {
        return $this->statusRouteId ? RouteDay::with('truck')->find($this->statusRouteId) : null;
    }

    /**
     * Oficina cambia a mano el estado del día (p. ej. reabrir una ruta que el chofer cerró por
     * error, o cancelar la de un día que no salió). Salir de "Completada" deshace el cierre de
     * jornada — ver `RouteDay::changeStatus()`.
     */
    public function setStatus(int $routeId, string $status): void
    {
        $route = RouteDay::findOrFail($routeId);
        $this->authorize('update', $route->route);

        $route->changeStatus(RouteStatus::from($status));

        $this->statusRouteId = null;
        $this->dispatch('close-modal', 'route-status');
        $this->dispatch('toast', message: 'Estado de la ruta cambiado a "'.$route->status->label().'".', variant: 'success');
    }

    /** Paso 1 de "Ruta eficiente": abre el modal para elegir el punto de partida. */
    public function startOptimize(int $routeId): void
    {
        $route = RouteDay::findOrFail($routeId);
        $this->authorize('reorderStops', $route);

        $this->optimizingRouteId = $routeId;
        $this->dispatch('open-modal', 'route-optimize');
    }

    /**
     * Paso 2: reordena la ruta desde el origen elegido. $from = 'base' o el id de una parada
     * pendiente de la ruta.
     */
    public function runOptimize(string $from): void
    {
        abort_unless($this->optimizingRouteId !== null, 400);

        $route = RouteDay::with('stops')->findOrFail($this->optimizingRouteId);
        $this->authorize('reorderStops', $route);

        $optimizer = app(RouteOptimizer::class);
        $origin = $this->resolveOptimizeOrigin($route, $from, $optimizer);

        $result = $optimizer->optimize($route, $origin);

        $this->optimizingRouteId = null;
        $this->dispatch('close-modal', 'route-optimize');
        $this->dispatch('toast', ...$optimizer->toast($result));
    }

    /**
     * @param  'base'|'vehicle'|string  $from
     * @return array{0: float, 1: float}
     */
    private function resolveOptimizeOrigin(RouteDay $route, string $from, RouteOptimizer $optimizer): array
    {
        if ($from === 'base') {
            return $optimizer->baseOrigin();
        }

        if ($from === 'vehicle') {
            $position = $optimizer->latestVehiclePosition($route);

            abort_if($position === null, 422, 'No hay una posición reciente del camión.');

            return $position;
        }

        $stop = $route->stops->firstWhere('id', (int) $from);

        abort_unless(
            $stop !== null
                && $stop->status === RouteStopStatus::Pending
                && $stop->latitude !== null
                && $stop->longitude !== null,
            422,
            'Esa parada no sirve como punto de partida.',
        );

        return [(float) $stop->latitude, (float) $stop->longitude];
    }

    /** Paradas pendientes con ubicación de la ruta que se está reordenando (para el modal). */
    #[Computed]
    public function optimizingStops()
    {
        if ($this->optimizingRouteId === null) {
            return collect();
        }

        return RouteStop::query()
            ->where('route_id', $this->optimizingRouteId)
            ->where('status', RouteStopStatus::Pending)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('position')
            ->get();
    }

    /** Antigüedad de la última posición GPS del camión de la ruta que se reordena (o null). */
    #[Computed]
    public function optimizingVehicleAge(): ?string
    {
        if ($this->optimizingRouteId === null) {
            return null;
        }

        $route = RouteDay::find($this->optimizingRouteId);

        return $route ? app(RouteOptimizer::class)->vehiclePositionAge($route) : null;
    }

    /** "Ver recorrido": abre el mapa con las paradas de la ruta y su trazado. */
    public function showRouteMap(int $routeId): void
    {
        $route = RouteDay::findOrFail($routeId);
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
        // generateRecurringRoutes() (mount/updatedDate/previousDay/nextDay/today) ya garantiza
        // el RouteDay de hoy para cada Route vigente antes de llegar aquí.
        $routes = RouteDay::query()
            ->with(['truck', 'driver.user', 'stops.deliveryType', 'route'])
            ->whereDate('route_date', $this->date)
            ->where('service_kind', $this->kind)
            ->get()
            ->sortBy(fn (RouteDay $route) => $route->truck->code);

        // "Sin asignar": todo lo que sigue sin ruta, tenga o no fecha de servicio — el día que
        // se esté viendo en el tablero no la vacía ni la filtra, es la misma columna siempre.
        $unassigned = RouteStop::query()
            ->unassigned()
            ->where('service_kind', $this->kind)
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
