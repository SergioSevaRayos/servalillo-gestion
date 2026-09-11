<?php

namespace App\Livewire\Routes;

use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Models\Device;
use App\Models\Driver;
use App\Models\Route;
use App\Models\RouteDay;
use App\Services\RouteGeometry;
use App\Services\StopDwellService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "Qué ha hecho esta ruta un día concreto" (petición del usuario tras pasar `Route` a ser
 * la ficha permanente camión+chofer): historial paginado de sus `RouteDay`, con detalle por
 * día (paradas, jornada, albaranes) y mapa del recorrido si hay datos.
 */
#[Layout('layouts.app')]
class History extends Component
{
    use WithPagination;

    public Route $route;

    #[Url(history: true)]
    public ?string $from = null;

    #[Url(history: true)]
    public ?string $to = null;

    public ?int $viewingDayId = null;

    public ?int $statusRouteId = null;

    public function mount(Route $route): void
    {
        $this->authorize('view', $route);

        $this->route = $route;
    }

    public function openStatusModal(int $routeDayId): void
    {
        $day = RouteDay::where('route_id', $this->route->id)->findOrFail($routeDayId);
        $this->authorize('update', $this->route);

        $this->statusRouteId = $day->id;
        $this->dispatch('open-modal', 'route-status');
    }

    #[Computed]
    public function statusRoute(): ?RouteDay
    {
        return $this->statusRouteId
            ? RouteDay::with('truck')->where('route_id', $this->route->id)->find($this->statusRouteId)
            : null;
    }

    public function setStatus(int $routeDayId, string $status): void
    {
        $day = RouteDay::where('route_id', $this->route->id)->findOrFail($routeDayId);
        $this->authorize('update', $this->route);

        $day->changeStatus(RouteStatus::from($status));

        $this->statusRouteId = null;
        $this->dispatch('close-modal', 'route-status');
        $this->dispatch('toast', message: 'Estado de la ruta cambiado a "'.$day->status->label().'".', variant: 'success');
    }

    /**
     * Válvula manual: deshace una sustitución automática equivocada (ver
     * App\Services\RouteTerminalPairingService), fuerza una que la guarda de colisión bloqueó, o
     * devuelve la ruta a mano a media jornada.
     */
    public ?int $reassignRouteDayId = null;

    public ?int $reassignDriverId = null;

    public bool $reassignDeviceToo = true;

    public function openReassignModal(int $routeDayId): void
    {
        $day = RouteDay::where('route_id', $this->route->id)->findOrFail($routeDayId);
        $this->authorize('update', $this->route);

        $this->reassignRouteDayId = $day->id;
        $this->reassignDriverId = $day->driver_id;
        $this->reassignDeviceToo = true;
        $this->dispatch('open-modal', 'route-reassign-driver');
    }

    #[Computed]
    public function reassignRouteDay(): ?RouteDay
    {
        return $this->reassignRouteDayId
            ? RouteDay::with('driver.user')->where('route_id', $this->route->id)->find($this->reassignRouteDayId)
            : null;
    }

    #[Computed]
    public function drivers()
    {
        return Driver::with('user')->where('is_active', true)->get()->sortBy(fn ($d) => $d->user->name);
    }

    public function reassignDriver(): void
    {
        abort_unless($this->reassignRouteDayId !== null && $this->reassignDriverId !== null, 400);

        $day = RouteDay::where('route_id', $this->route->id)->findOrFail($this->reassignRouteDayId);
        $this->authorize('update', $this->route);

        $original = $day->driver_id;
        $day->update(['driver_id' => $this->reassignDriverId]);

        if ($this->reassignDeviceToo && $original !== null) {
            $device = Device::where('driver_id', $original)->first();

            if ($device !== null && ! Device::where('driver_id', $this->reassignDriverId)->exists()) {
                $device->update(['driver_id' => $this->reassignDriverId]);
            }
        }

        $this->reassignRouteDayId = null;
        $this->dispatch('close-modal', 'route-reassign-driver');
        $this->dispatch('toast', message: 'Chofer del día reasignado.', variant: 'success');
    }

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingTo(): void
    {
        $this->resetPage();
    }

    public function viewDay(int $routeDayId): void
    {
        $day = RouteDay::where('route_id', $this->route->id)->findOrFail($routeDayId);
        $this->authorize('view', $day);

        // Los días recientes (aún dentro de la ventana de recálculo) se ponen al día bajo demanda;
        // los viejos ya los cerró el pase nocturno `paradas:calcular-permanencia`.
        $oldest = today()->subDays((int) config('servalillo.dwell.recompute_max_age_days'));
        if ($day->route_date->gte($oldest) && $day->dwellIsStale()) {
            app(StopDwellService::class)->recomputeForRouteDay($day);
        }

        $this->viewingDayId = $day->id;
        $this->dispatch('open-modal', 'day-detail');
    }

    /** "Ver recorrido" de un día concreto — misma infra que el tablero (Board::showRouteMap). */
    public function showDayMap(int $routeDayId): void
    {
        $day = RouteDay::where('route_id', $this->route->id)->findOrFail($routeDayId);
        $this->authorize('view', $day);

        $this->dispatch('open-route-map', ...app(RouteGeometry::class)->payloadFor($day));
    }

    #[Computed]
    public function viewingDay(): ?RouteDay
    {
        if ($this->viewingDayId === null) {
            return null;
        }

        return RouteDay::with(['stops.deliveryType', 'stops.deliveryNote', 'stops.visits'])
            ->where('route_id', $this->route->id)
            ->find($this->viewingDayId);
    }

    /**
     * Tramos de trayecto entre paradas consecutivas del día abierto: cuánto tardó el camión y a
     * qué velocidad circuló entre una y la siguiente (a partir del GPS, ver StopDwellService).
     * Se indexa por el id de la parada de LLEGADA para pintarlo justo antes de esa parada.
     *
     * @return array<int, array{from_stop_id: int, from_name: string, to_stop_id: int, to_name: string, departed_at: Carbon, arrived_at: Carbon, seconds: int, avg_speed_kmh: ?int, max_speed_kmh: ?int}>
     */
    #[Computed]
    public function transitLegs(): array
    {
        if ($this->viewingDay === null) {
            return [];
        }

        return collect(app(StopDwellService::class)->transitLegs($this->viewingDay))
            ->keyBy('to_stop_id')
            ->all();
    }

    public function render()
    {
        $days = RouteDay::query()
            ->where('route_id', $this->route->id)
            ->withCount([
                'stops',
                'stops as completed_stops_count' => fn ($q) => $q->where('status', RouteStopStatus::Completed),
                'stops as failed_stops_count' => fn ($q) => $q->whereIn('status', [RouteStopStatus::Failed, RouteStopStatus::Skipped]),
                'stops as pending_stops_count' => fn ($q) => $q->where('status', RouteStopStatus::Pending),
            ])
            ->withSum('stopVisits as on_site_seconds', 'seconds')
            ->when($this->from, fn ($q) => $q->whereDate('route_date', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('route_date', '<=', $this->to))
            ->orderByDesc('route_date')
            ->paginate(14);

        return view('livewire.routes.history', ['days' => $days]);
    }
}
