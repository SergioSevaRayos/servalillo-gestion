<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bloque 18 (fichaje): único punto de cálculo de horas/días trabajados — usado tanto por
 * las tarjetas KPI de `/fichar` (una persona, sus 4 periodos actuales) como por la tabla
 * comparativa de `/fichajes/totales` (todas las personas, un periodo). Mismo criterio que
 * FleetStatsService/MaintenanceStatsService: agregación en BD (Query Builder), sin mutar
 * nada, sin lanzar al llamador.
 *
 * Precisión (el jornal depende de esto): solo cuentan los fichajes YA CERRADOS
 * (`out_at IS NOT NULL`, con `total_seconds` ya calculado) — una jornada abierta nunca
 * suma a ningún total, se expone aparte vía `openShiftSeconds()` como dato informativo
 * "en curso". "Días trabajados" = nº de jornadas cerradas, NO un equivalente de 8h: el
 * chofer no tiene jornada fija, un día de 3h y uno de 14h cuentan igual como "1 día".
 * Semana = ISO, lunes-domingo, siempre explícito (nunca el default de locale de Carbon).
 */
class AttendanceStatsService
{
    private const PERIODS = ['day', 'week', 'month', 'year'];

    /** @return array{from: Carbon, to: Carbon} */
    public function rangeFor(string $period, Carbon $anchor): array
    {
        if (! in_array($period, self::PERIODS, true)) {
            throw new InvalidArgumentException("Periodo desconocido: {$period}");
        }

        return match ($period) {
            'day' => ['from' => $anchor->copy()->startOfDay(), 'to' => $anchor->copy()->endOfDay()],
            'week' => ['from' => $anchor->copy()->startOfWeek(Carbon::MONDAY), 'to' => $anchor->copy()->endOfWeek(Carbon::SUNDAY)],
            'month' => ['from' => $anchor->copy()->startOfMonth(), 'to' => $anchor->copy()->endOfMonth()],
            'year' => ['from' => $anchor->copy()->startOfYear(), 'to' => $anchor->copy()->endOfYear()],
        };
    }

    /** @return array{seconds: int, days: int, range: array{from: string, to: string}} */
    public function totalsFor(int $userId, Carbon $from, Carbon $to): array
    {
        $row = DB::table('attendances')
            ->where('user_id', $userId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('out_at')
            ->selectRaw('coalesce(sum(total_seconds), 0) as seconds, count(*) as days')
            ->first();

        return [
            'seconds' => (int) $row->seconds,
            'days' => (int) $row->days,
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ];
    }

    /** Los 4 periodos "actuales" (hoy/semana/mes/año) de una persona — KPIs de /fichar. */
    public function currentPeriods(int $userId): array
    {
        $now = Carbon::now();

        return collect(self::PERIODS)
            ->mapWithKeys(function (string $period) use ($userId, $now) {
                $range = $this->rangeFor($period, $now);

                return [$period => $this->totalsFor($userId, $range['from'], $range['to'])];
            })->all();
    }

    /** Segundos transcurridos de la jornada de hoy si está abierta, o null. Nunca forma parte de un total. */
    public function openShiftSeconds(int $userId): ?int
    {
        $inAt = DB::table('attendances')
            ->where('user_id', $userId)
            ->where('date', Carbon::today()->toDateString())
            ->whereNotNull('in_at')
            ->whereNull('out_at')
            ->value('in_at');

        return $inAt ? max(0, (int) round(Carbon::parse($inAt)->diffInSeconds(now()))) : null;
    }

    /**
     * Tabla comparativa: una fila por persona que ficha, para un periodo. Rellena a cero
     * quien no tenga fichajes cerrados en el rango; no incluye a mantenimiento (no ficha).
     *
     * @return list<array{user_id: int, name: string, seconds: int, days: int}>
     */
    public function comparisonTable(string $from, string $to): array
    {
        $byUser = DB::table('attendances')
            ->whereBetween('date', [$from, $to])
            ->whereNotNull('out_at')
            ->groupBy('user_id')
            ->selectRaw('user_id, coalesce(sum(total_seconds), 0) as seconds, count(*) as days')
            ->get()
            ->keyBy('user_id');

        return User::query()->get()->filter->canPunchAttendance()->sortBy('name')->values()
            ->map(fn (User $user) => [
                'user_id' => $user->id,
                'name' => $user->name,
                'seconds' => (int) ($byUser[$user->id]->seconds ?? 0),
                'days' => (int) ($byUser[$user->id]->days ?? 0),
            ])->values()->all();
    }

    /**
     * Desglose día a día de una persona en un rango — los días con jornada abierta se
     * incluyen (marcados), pero sin segundos (nunca se cuentan en ningún total).
     *
     * @return list<array{date: string, in_at: ?string, out_at: ?string, seconds: ?int, open: bool}>
     */
    public function dailyBreakdown(int $userId, string $from, string $to): array
    {
        return DB::table('attendances')
            ->where('user_id', $userId)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get(['date', 'in_at', 'out_at', 'total_seconds'])
            ->map(fn ($row) => [
                'date' => Carbon::parse($row->date)->toDateString(),
                'in_at' => $row->in_at,
                'out_at' => $row->out_at,
                'seconds' => $row->out_at !== null ? (int) $row->total_seconds : null,
                'open' => $row->in_at !== null && $row->out_at === null,
            ])->values()->all();
    }

    /** Segundos de la jornada ordinaria semanal de una persona (las suyas propias, o el umbral general). */
    public function contractedWeeklySeconds(int $userId): int
    {
        $user = User::find($userId);
        $hours = $user
            ? $user->effectiveWeeklyContractedHours()
            : (float) config('servalillo.attendance.default_weekly_hours');

        return (int) round($hours * 3600);
    }

    /**
     * Desglose día a día CON horas ordinarias/extraordinarias (art. 34 bis ET, proyecto de
     * ley — "identificarán de manera desagregada si las horas realizadas son ordinarias [o]
     * extraordinarias"). El acumulado se calcula sobre la SEMANA ISO COMPLETA que contiene
     * cada día, aunque el rango pedido sea más corto (p. ej. un mes cuya primera semana
     * empieza en el mes anterior) — si no, el reparto ordinaria/extra de los primeros días
     * del rango saldría mal. Se calcula sobre la semana entera y solo al final se recorta
     * al rango pedido.
     *
     * Las horas "ordinarias" de una semana se agotan primero (con el orden cronológico de
     * los días); todo lo que exceda el umbral semanal, sea el día que sea, es "extraordinaria".
     * Un día con jornada abierta no consume ni aporta horas ordinarias ni extraordinarias
     * (no se cuenta hasta cerrarse, igual que en cualquier otro total de este servicio).
     *
     * @return list<array{date: string, in_at: ?string, out_at: ?string, seconds: ?int, open: bool, ordinary_seconds: ?int, extra_seconds: ?int}>
     */
    public function dailyBreakdownWithHours(int $userId, string $from, string $to): array
    {
        $from = Carbon::parse($from);
        $to = Carbon::parse($to);
        $expandedFrom = $from->copy()->startOfWeek(Carbon::MONDAY);
        $expandedTo = $to->copy()->endOfWeek(Carbon::SUNDAY);
        $contractedSeconds = $this->contractedWeeklySeconds($userId);

        $rows = DB::table('attendances')
            ->where('user_id', $userId)
            ->whereBetween('date', [$expandedFrom->toDateString(), $expandedTo->toDateString()])
            ->orderBy('date')
            ->get(['date', 'in_at', 'out_at', 'total_seconds']);

        $result = [];
        $weekOrdinaryUsed = 0;
        $currentWeekStart = null;

        foreach ($rows as $row) {
            $date = Carbon::parse($row->date);
            $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY)->toDateString();

            if ($weekStart !== $currentWeekStart) {
                $currentWeekStart = $weekStart;
                $weekOrdinaryUsed = 0;
            }

            $closed = $row->out_at !== null;
            $seconds = $closed ? (int) $row->total_seconds : null;
            $ordinary = null;
            $extra = null;

            if ($closed) {
                $ordinary = min($seconds, max(0, $contractedSeconds - $weekOrdinaryUsed));
                $extra = $seconds - $ordinary;
                $weekOrdinaryUsed += $ordinary;
            }

            $result[$date->toDateString()] = [
                'date' => $date->toDateString(),
                'in_at' => $row->in_at,
                'out_at' => $row->out_at,
                'seconds' => $seconds,
                'open' => $row->in_at !== null && $row->out_at === null,
                'ordinary_seconds' => $ordinary,
                'extra_seconds' => $extra,
            ];
        }

        return array_values(array_filter(
            $result,
            fn (array $day) => $day['date'] >= $from->toDateString() && $day['date'] <= $to->toDateString(),
        ));
    }
}
