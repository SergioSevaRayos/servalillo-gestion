<?php

namespace App\Livewire\Maintenance;

use App\Models\ErrorLog;
use App\Models\SupportTicket;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;
use OwenIt\Auditing\Models\Audit;

/**
 * Panel de inicio del rol mantenimiento (`/mantenimiento`). En vez del panel estadístico
 * de la empresa, muestra lo que necesita el técnico: incidencias de soporte abiertas,
 * errores recientes, actividad de auditoría y los ficheros de log.
 */
#[Layout('layouts.app')]
class Overview extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', ErrorLog::class);
    }

    public function render()
    {
        $weekAgo = now()->subDays(7);

        $recentTickets = SupportTicket::query()
            ->with('creator')
            ->whereIn('status', ['abierto', 'en_curso'])
            ->orderByRaw('COALESCE(last_reply_at, created_at) DESC')
            ->limit(6)
            ->get();

        $recentErrors = ErrorLog::query()
            ->with('user')
            ->latest('occurred_at')
            ->limit(6)
            ->get();

        $recentAudits = Audit::query()
            ->with('user')
            ->latest()
            ->limit(8)
            ->get();

        $logFiles = collect(glob(storage_path('logs/*.log')) ?: [])
            ->sortByDesc(fn (string $p) => filemtime($p))
            ->take(6)
            ->map(fn (string $p) => [
                'name' => basename($p),
                'size' => filesize($p) ?: 0,
                'modified' => Carbon::createFromTimestamp(filemtime($p)),
            ])
            ->values();

        return view('livewire.maintenance.overview', [
            'kpis' => [
                'open_tickets' => SupportTicket::whereIn('status', ['abierto', 'en_curso'])->count(),
                'in_progress' => SupportTicket::where('status', 'en_curso')->count(),
                'errors_week' => ErrorLog::where('occurred_at', '>=', $weekAgo)->count(),
                'audits_today' => Audit::whereDate('created_at', today())->count(),
                'unread' => auth()->user()->unreadNotifications()->count(),
            ],
            'recentTickets' => $recentTickets,
            'recentErrors' => $recentErrors,
            'recentAudits' => $recentAudits,
            'logFiles' => $logFiles,
            'auditModels' => Audits::MODELS,
        ]);
    }
}
