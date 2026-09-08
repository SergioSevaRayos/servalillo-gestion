<?php

namespace App\Livewire\Maintenance;

use App\Models\ErrorLog;
use App\Models\SupportTicket;
use App\Services\MaintenanceStatsService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use OwenIt\Auditing\Models\Audit;

/**
 * Panel de inicio del rol mantenimiento (`/mantenimiento`, pestaña "Resumen"). El
 * equivalente de `/dashboard` para el técnico: KPIs y gráficos de salud del sistema
 * (errores 5xx), canal de soporte y auditoría, además de avisos accionables y accesos
 * directos a los últimos registros.
 */
#[Layout('layouts.app')]
class Overview extends Component
{
    /** Rangos disponibles (clave => días hacia atrás, incluyendo hoy). */
    public const RANGES = ['7d' => 7, '30d' => 30, '90d' => 90];

    #[Url]
    public string $range = '30d';

    public function mount(): void
    {
        $this->authorize('viewAny', ErrorLog::class);

        if (! array_key_exists($this->range, self::RANGES)) {
            $this->range = '30d';
        }
    }

    public function setRange(string $range): void
    {
        if (array_key_exists($range, self::RANGES)) {
            $this->range = $range;
            $this->dispatch('maint-stats-updated', charts: $this->chartData());
        }
    }

    #[Computed]
    public function stats(): array
    {
        $days = self::RANGES[$this->range];

        return app(MaintenanceStatsService::class)->report(
            Carbon::today()->subDays($days - 1)->startOfDay(),
            Carbon::today()->endOfDay(),
        );
    }

    /** @return array<string, mixed> */
    public function chartData(): array
    {
        $stats = $this->stats();
        $compact = $this->range === '90d';

        $label = fn (string $date) => Carbon::parse($date)->isoFormat($compact ? 'D MMM' : 'ddd D MMM');

        return [
            'errorsDaily' => [
                'labels' => collect($stats['errors_daily'])->map(fn ($d) => $label($d['date']))->all(),
                'data' => collect($stats['errors_daily'])->pluck('count')->all(),
            ],
            'ticketsDaily' => [
                'labels' => collect($stats['tickets_daily'])->map(fn ($d) => $label($d['date']))->all(),
                'opened' => collect($stats['tickets_daily'])->pluck('opened')->all(),
                'resolved' => collect($stats['tickets_daily'])->pluck('resolved')->all(),
            ],
            'auditsDaily' => [
                'labels' => collect($stats['audits_daily'])->map(fn ($d) => $label($d['date']))->all(),
                'data' => collect($stats['audits_daily'])->pluck('count')->all(),
            ],
            'ticketsByCategory' => [
                'labels' => [__('Fallo'), __('Necesidad'), __('Consulta')],
                'data' => array_values($stats['tickets_by_category']),
            ],
        ];
    }

    public function render()
    {
        return view('livewire.maintenance.overview', [
            'stats' => $this->stats(),
            'chartData' => $this->chartData(),
            'alerts' => app(MaintenanceStatsService::class)->alerts(),
            'ranges' => self::RANGES,
            'recentTickets' => SupportTicket::with('creator')
                ->whereIn('status', ['abierto', 'en_curso'])
                ->orderByRaw('COALESCE(last_reply_at, created_at) DESC')
                ->limit(5)->get(),
            'recentErrors' => ErrorLog::with('user')->latest('occurred_at')->limit(5)->get(),
            'recentAudits' => Audit::with('user')->latest()->limit(6)->get(),
            'auditModels' => Audits::MODELS,
        ]);
    }
}
