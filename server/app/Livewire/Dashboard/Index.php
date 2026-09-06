<?php

namespace App\Livewire\Dashboard;

use App\Enums\RouteStatus;
use App\Services\FleetStatsService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    /** Rangos disponibles (clave => días hacia atrás, incluyendo hoy). */
    public const RANGES = ['7d' => 7, '30d' => 30, '90d' => 90, 'year' => 365];

    #[Url]
    public string $range = '30d';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('stats.view'), 403);

        if (! array_key_exists($this->range, self::RANGES)) {
            $this->range = '30d';
        }
    }

    public function setRange(string $range): void
    {
        if (array_key_exists($range, self::RANGES)) {
            $this->range = $range;
            $this->dispatch('stats-updated', charts: $this->chartData());
        }
    }

    #[Computed]
    public function stats(): array
    {
        $days = self::RANGES[$this->range];

        return app(FleetStatsService::class)->report(
            Carbon::today()->subDays($days - 1)->startOfDay(),
            Carbon::today()->endOfDay(),
        );
    }

    /**
     * Datos ya preparados para Chart.js (etiquetas + series). Se pasan al front en el
     * primer render y se reenvían por el evento `stats-updated` al cambiar de rango.
     *
     * @return array<string, mixed>
     */
    public function chartData(): array
    {
        $stats = $this->stats();
        $compact = $this->range === 'year' || $this->range === '90d';

        $daily = collect($stats['operations']['daily']);

        $routeStatus = collect($stats['operations']['route_status'])
            ->filter(fn (int $total) => $total > 0);

        return [
            'daily' => [
                'labels' => $daily->map(fn (array $d) => Carbon::parse($d['date'])
                    ->isoFormat($compact ? 'D MMM' : 'ddd D MMM'))->all(),
                'completed' => $daily->pluck('completed')->all(),
                'failed' => $daily->pluck('failed')->all(),
            ],
            'routeStatus' => [
                'labels' => $routeStatus->keys()
                    ->map(fn (string $value) => RouteStatus::from($value)->label())->all(),
                'data' => $routeStatus->values()->all(),
            ],
            'volumeByType' => [
                'labels' => collect($stats['volume']['by_type'])->pluck('type')->all(),
                'delivered' => collect($stats['volume']['by_type'])->pluck('delivered')->all(),
            ],
            'byDriver' => [
                'labels' => collect($stats['by_driver'])->pluck('driver')->all(),
                'completed' => collect($stats['by_driver'])->pluck('completed')->all(),
                'failed' => collect($stats['by_driver'])->pluck('failed')->all(),
            ],
        ];
    }

    public function render()
    {
        return view('livewire.dashboard.index', [
            'stats' => $this->stats(),
            'chartData' => $this->chartData(),
            'ranges' => self::RANGES,
        ]);
    }
}
