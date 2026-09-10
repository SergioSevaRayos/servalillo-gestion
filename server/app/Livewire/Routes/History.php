<?php

namespace App\Livewire\Routes;

use App\Enums\RouteStopStatus;
use App\Models\Route;
use App\Models\RouteDay;
use App\Services\RouteGeometry;
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

    public function mount(Route $route): void
    {
        $this->authorize('view', $route);

        $this->route = $route;
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

        return RouteDay::with(['stops.deliveryType', 'stops.deliveryNote'])
            ->where('route_id', $this->route->id)
            ->find($this->viewingDayId);
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
            ->when($this->from, fn ($q) => $q->whereDate('route_date', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('route_date', '<=', $this->to))
            ->orderByDesc('route_date')
            ->paginate(14);

        return view('livewire.routes.history', ['days' => $days]);
    }
}
