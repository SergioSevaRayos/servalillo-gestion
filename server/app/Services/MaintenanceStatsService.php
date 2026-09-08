<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Agrega las métricas del panel de inicio del rol mantenimiento (Bloque 12): salud del
 * sistema (errores 5xx), canal de soporte y actividad de auditoría. Todo con agregación
 * en BD; `support_tickets` usa SoftDeletes → se excluye `deleted_at` a mano.
 */
class MaintenanceStatsService
{
    /** @return array<string, mixed> */
    public function report(Carbon $from, Carbon $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        return [
            'range' => ['from' => $fromDate, 'to' => $toDate],
            'kpis' => $this->kpis($from, $to),
            'errors_daily' => $this->daily('error_logs', 'occurred_at', $fromDate, $toDate),
            'errors_by_class' => $this->errorsByClass($from, $to),
            'tickets_daily' => $this->ticketsDaily($fromDate, $toDate),
            'tickets_by_category' => $this->ticketsByCategory($from, $to),
            'tickets_by_status' => $this->ticketsByStatus(),
            'audits_daily' => $this->daily('audits', 'created_at', $fromDate, $toDate),
            'audits_by_model' => $this->auditsByModel($from, $to),
            'logs' => $this->logs(),
        ];
    }

    /** @return array<string, int> */
    private function kpis(Carbon $from, Carbon $to): array
    {
        return [
            'open_tickets' => DB::table('support_tickets')->whereNull('deleted_at')
                ->whereIn('status', ['abierto', 'en_curso'])->count(),
            'waiting_support' => DB::table('support_tickets')->whereNull('deleted_at')
                ->whereIn('status', ['abierto', 'en_curso'])
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('support_ticket_replies as r')
                    ->whereColumn('r.support_ticket_id', 'support_tickets.id')
                    ->whereColumn('r.user_id', '!=', 'support_tickets.user_id'))
                ->count(),
            'resolved_period' => DB::table('support_tickets')->whereNull('deleted_at')
                ->where('status', 'resuelto')->whereBetween('updated_at', [$from, $to])->count(),
            'errors_period' => DB::table('error_logs')->whereBetween('occurred_at', [$from, $to])->count(),
            'errors_24h' => DB::table('error_logs')->where('occurred_at', '>=', now()->subDay())->count(),
            'audits_period' => DB::table('audits')->whereBetween('created_at', [$from, $to])->count(),
            'unread' => (int) auth()->user()?->unreadNotifications()->count(),
        ];
    }

    /**
     * Serie diaria de conteos rellenando los días sin datos con 0.
     *
     * @return list<array{date: string, count: int}>
     */
    private function daily(string $table, string $column, string $from, string $to): array
    {
        $byDay = DB::table($table)
            ->whereBetween($column, [$from, $to])
            ->groupByRaw("{$column}::date")
            ->selectRaw("{$column}::date as day, count(*) as total")
            ->pluck('total', 'day')
            ->all();

        $out = [];
        for ($cursor = Carbon::parse($from); $cursor->lte(Carbon::parse($to)); $cursor->addDay()) {
            $day = $cursor->toDateString();
            $out[] = ['date' => $day, 'count' => (int) ($byDay[$day] ?? 0)];
        }

        return $out;
    }

    /** @return list<array{label: string, count: int}> */
    private function errorsByClass(Carbon $from, Carbon $to): array
    {
        return DB::table('error_logs')
            ->whereBetween('occurred_at', [$from, $to])
            ->groupBy('exception_class')
            ->selectRaw('exception_class, count(*) as total')
            ->orderByDesc('total')
            ->limit(6)
            ->get()
            ->map(fn ($r) => [
                'label' => class_basename($r->exception_class) ?: 'Error',
                'count' => (int) $r->total,
            ])
            ->all();
    }

    /** @return list<array{date: string, opened: int, resolved: int}> */
    private function ticketsDaily(string $from, string $to): array
    {
        $opened = DB::table('support_tickets')->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->groupByRaw('created_at::date')
            ->selectRaw('created_at::date as day, count(*) as total')
            ->pluck('total', 'day')->all();

        $resolved = DB::table('support_tickets')->whereNull('deleted_at')
            ->where('status', 'resuelto')
            ->whereBetween('updated_at', [$from, $to])
            ->groupByRaw('updated_at::date')
            ->selectRaw('updated_at::date as day, count(*) as total')
            ->pluck('total', 'day')->all();

        $out = [];
        for ($cursor = Carbon::parse($from); $cursor->lte(Carbon::parse($to)); $cursor->addDay()) {
            $day = $cursor->toDateString();
            $out[] = [
                'date' => $day,
                'opened' => (int) ($opened[$day] ?? 0),
                'resolved' => (int) ($resolved[$day] ?? 0),
            ];
        }

        return $out;
    }

    /** @return array<string, int> */
    private function ticketsByCategory(Carbon $from, Carbon $to): array
    {
        $counts = DB::table('support_tickets')->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('category')
            ->selectRaw('category, count(*) as total')
            ->pluck('total', 'category')->all();

        $out = [];
        foreach (['fallo', 'necesidad', 'consulta'] as $cat) {
            $out[$cat] = (int) ($counts[$cat] ?? 0);
        }

        return $out;
    }

    /** @return array<string, int> */
    private function ticketsByStatus(): array
    {
        $counts = DB::table('support_tickets')->whereNull('deleted_at')
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status')->all();

        $out = [];
        foreach (['abierto', 'en_curso', 'resuelto'] as $status) {
            $out[$status] = (int) ($counts[$status] ?? 0);
        }

        return $out;
    }

    /** @return list<array{label: string, count: int}> */
    private function auditsByModel(Carbon $from, Carbon $to): array
    {
        return DB::table('audits')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('auditable_type')
            ->selectRaw('auditable_type, count(*) as total')
            ->orderByDesc('total')
            ->limit(6)
            ->get()
            ->map(fn ($r) => [
                'label' => class_basename($r->auditable_type) ?: '—',
                'count' => (int) $r->total,
            ])
            ->all();
    }

    /** @return array{count: int, total_bytes: int, biggest: ?array{name: string, bytes: int}} */
    private function logs(): array
    {
        $files = collect(glob(storage_path('logs/*.log')) ?: [])
            ->map(fn (string $p) => ['name' => basename($p), 'bytes' => (int) (filesize($p) ?: 0)]);

        return [
            'count' => $files->count(),
            'total_bytes' => (int) $files->sum('bytes'),
            'biggest' => $files->sortByDesc('bytes')->first(),
        ];
    }

    /**
     * Avisos accionables (no dependen del rango). Cada uno: level, title, detail, url.
     *
     * @return list<array{level: string, title: string, detail: string, url: string}>
     */
    public function alerts(): array
    {
        $alerts = [];

        $stale = DB::table('support_tickets')->whereNull('deleted_at')
            ->whereIn('status', ['abierto', 'en_curso'])
            ->where('created_at', '<', now()->subDays(2))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('support_ticket_replies as r')
                ->whereColumn('r.support_ticket_id', 'support_tickets.id')
                ->whereColumn('r.user_id', '!=', 'support_tickets.user_id'))
            ->count();
        if ($stale > 0) {
            $alerts[] = [
                'level' => 'danger',
                'title' => trans_choice(':count incidencia sin responder|:count incidencias sin responder', $stale, ['count' => $stale]),
                'detail' => 'Llevan más de 2 días esperando una respuesta de mantenimiento.',
                'url' => route('maintenance.support', ['status' => 'abierto']),
            ];
        }

        $errors24h = DB::table('error_logs')->where('occurred_at', '>=', now()->subDay())->count();
        if ($errors24h > 0) {
            $alerts[] = [
                'level' => 'warning',
                'title' => trans_choice(':count error en las últimas 24 h|:count errores en las últimas 24 h', $errors24h, ['count' => $errors24h]),
                'detail' => 'Revisa la traza en el visor de errores.',
                'url' => route('maintenance.errors'),
            ];
        }

        $biggest = collect(glob(storage_path('logs/*.log')) ?: [])
            ->map(fn (string $p) => ['name' => basename($p), 'bytes' => (int) (filesize($p) ?: 0)])
            ->sortByDesc('bytes')->first();
        if ($biggest && $biggest['bytes'] > 5 * 1024 * 1024) {
            $alerts[] = [
                'level' => 'warning',
                'title' => 'El log ha crecido mucho',
                'detail' => sprintf('%s ocupa %s. Considera rotarlo o revisar qué lo está llenando.',
                    $biggest['name'], $this->humanBytes($biggest['bytes'])),
                'url' => route('maintenance.logs', ['file' => $biggest['name']]),
            ];
        }

        $oldErrors = DB::table('error_logs')->where('occurred_at', '<', now()->subDays(30))->count();
        if ($oldErrors > 0) {
            $alerts[] = [
                'level' => 'info',
                'title' => trans_choice(':count error de más de 30 días|:count errores de más de 30 días', $oldErrors, ['count' => $oldErrors]),
                'detail' => 'Puedes purgarlos desde el listado de errores.',
                'url' => route('maintenance.errors'),
            ];
        }

        return $alerts;
    }

    private function humanBytes(int $n): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($n < 1024) {
                return round($n, $unit === 'B' ? 0 : 1).' '.$unit;
            }
            $n /= 1024;
        }

        return round($n, 1).' TB';
    }
}
