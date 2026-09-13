<?php

namespace App\Services;

use App\Models\Attendance;
use App\Support\Duration;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use SimpleXMLElement;

/**
 * Bloque 18 (fichaje): genera la exportación en "formato legal" (un XML de registro de
 * jornada, pensado para una posible Inspección de Trabajo), las filas para la vista del
 * PDF, y un tercer formato JSON "interoperable" preliminar. Adapta el esquema del
 * proyecto de referencia (/home/sergio/VSC/Fichajes) sin las pausas (aquí no existen) —
 * ver docs/05-fichaje.md, sección "Auditoría del formato de datos frente a la ley".
 *
 * El desglose ordinarias/extraordinarias (`AttendanceStatsService::dailyBreakdownWithHours()`)
 * se calcula por (usuario, semana ISO) y se cachea aquí mismo mientras se recorre la
 * colección — evita repetir la misma consulta de la semana varias veces para los días de
 * una misma persona, sin dejar de ser exacto: la semana se lee siempre entera aunque el
 * rango exportado sea más corto (p. ej. un mes cuya primera semana empieza antes).
 */
class AttendanceExportService
{
    /** @var array<string, array<string, array{ordinary_seconds: ?int, extra_seconds: ?int}>> */
    private array $weeklyBreakdownCache = [];

    public function __construct(private readonly AttendanceStatsService $stats) {}

    /** @param  Collection<int, Attendance>  $attendances */
    public function toLegalXml(Collection $attendances): string
    {
        $root = new SimpleXMLElement('<RegistroJornada/>');

        $company = $root->addChild('Empresa');
        $company->addChild('Nombre', htmlspecialchars((string) config('servalillo.company.name')));
        $company->addChild('CIF', htmlspecialchars((string) config('servalillo.company.tax_id')));

        $records = $root->addChild('Jornadas');

        foreach ($attendances->loadMissing(['user', 'corrections.correctedBy']) as $attendance) {
            $jornada = $records->addChild('Jornada');

            $empleado = $jornada->addChild('Empleado');
            $empleado->addChild('Nombre', htmlspecialchars($attendance->user->name));
            $empleado->addChild('DNI', htmlspecialchars((string) $attendance->user->dni));

            $jornada->addChild('Fecha', $attendance->date->toDateString());
            $jornada->addChild('Entrada', $attendance->in_at?->toDateTimeString() ?? '');
            $jornada->addChild('EntradaLatitud', $attendance->in_latitude !== null ? (string) $attendance->in_latitude : '');
            $jornada->addChild('EntradaLongitud', $attendance->in_longitude !== null ? (string) $attendance->in_longitude : '');
            $jornada->addChild('Salida', $attendance->out_at?->toDateTimeString() ?? '');
            $jornada->addChild('SalidaLatitud', $attendance->out_latitude !== null ? (string) $attendance->out_latitude : '');
            $jornada->addChild('SalidaLongitud', $attendance->out_longitude !== null ? (string) $attendance->out_longitude : '');

            // Legible ("8 h 05 min") Y en crudo (segundos) — el legible es para una persona,
            // el crudo es el que de verdad puede procesar un sistema automático.
            $jornada->addChild('TiempoEfectivo', $attendance->total_seconds !== null ? Duration::humanShort($attendance->total_seconds) : '');
            $jornada->addChild('TiempoEfectivoSegundos', (string) ($attendance->total_seconds ?? ''));

            $breakdown = $this->breakdownFor($attendance);
            $jornada->addChild('TiempoOrdinario', $breakdown['ordinary_seconds'] !== null ? Duration::humanShort($breakdown['ordinary_seconds']) : '');
            $jornada->addChild('TiempoOrdinarioSegundos', (string) ($breakdown['ordinary_seconds'] ?? ''));
            $jornada->addChild('TiempoExtraordinario', $breakdown['extra_seconds'] !== null ? Duration::humanShort($breakdown['extra_seconds']) : '');
            $jornada->addChild('TiempoExtraordinarioSegundos', (string) ($breakdown['extra_seconds'] ?? ''));

            $jornada->addChild('MetodoRegistro', $this->method($attendance));
            $jornada->addChild('FueraDeZona', ($attendance->in_out_of_bounds || $attendance->out_out_of_bounds) ? 'si' : 'no');

            $lastCorrection = $attendance->corrections->first();
            $jornada->addChild('Corregido', $lastCorrection ? 'si' : 'no');
            if ($lastCorrection) {
                $correccion = $jornada->addChild('UltimaCorreccion');
                $correccion->addChild('Por', htmlspecialchars($lastCorrection->correctedBy?->name ?? ''));
                $correccion->addChild('Fecha', $lastCorrection->created_at->toDateTimeString());
                $correccion->addChild('Motivo', htmlspecialchars($lastCorrection->reason));
            }
        }

        return $root->asXML();
    }

    /** @param  Collection<int, Attendance>  $attendances
     * @return list<array{name: string, dni: ?string, date: string, in_at: ?string, in_coords: ?string, out_at: ?string, out_coords: ?string, hours: string, ordinary_hours: string, extra_hours: string, corrected: bool, corrected_note: ?string}> */
    public function toPdfRows(Collection $attendances): array
    {
        return $attendances->loadMissing(['user', 'corrections.correctedBy'])->map(function (Attendance $a) {
            $breakdown = $this->breakdownFor($a);
            $lastCorrection = $a->corrections->first();

            return [
                'name' => $a->user->name,
                'dni' => $a->user->dni,
                'date' => $a->date->format('d/m/Y'),
                'in_at' => $a->in_at?->format('H:i'),
                'in_coords' => $this->coordsLabel($a->in_latitude, $a->in_longitude),
                'out_at' => $a->out_at?->format('H:i'),
                'out_coords' => $this->coordsLabel($a->out_latitude, $a->out_longitude),
                'hours' => $a->total_seconds !== null ? Duration::humanShort($a->total_seconds) : '—',
                'ordinary_hours' => $breakdown['ordinary_seconds'] !== null ? Duration::humanShort($breakdown['ordinary_seconds']) : '—',
                'extra_hours' => $breakdown['extra_seconds'] !== null ? Duration::humanShort($breakdown['extra_seconds']) : '—',
                'corrected' => (bool) $lastCorrection,
                'corrected_note' => $lastCorrection ? $lastCorrection->reason : null,
            ];
        })->values()->all();
    }

    /**
     * Formato "interoperable" preliminar (JSON) — mismo contenido que el XML legal, listo
     * para adaptarse cuando el reglamento de desarrollo del art. 34 bis ET (proyecto de
     * ley) fije el formato/protocolo real de interoperabilidad con la ITSS. Hoy NO habla
     * con la Inspección: es un export descargable, igual que el XML/PDF, protegido por el
     * mismo permiso `attendance.manage` — no existe todavía ninguna especificación oficial
     * a la que ajustarse.
     *
     * @param  Collection<int, Attendance>  $attendances
     * @return array{empresa: array{nombre: string, cif: string}, jornadas: list<array<string, mixed>>}
     */
    public function toInteroperableArray(Collection $attendances): array
    {
        $jornadas = $attendances->loadMissing(['user', 'corrections.correctedBy'])->map(function (Attendance $a) {
            $breakdown = $this->breakdownFor($a);
            $lastCorrection = $a->corrections->first();

            return [
                'empleado' => ['nombre' => $a->user->name, 'dni' => $a->user->dni],
                'fecha' => $a->date->toDateString(),
                'entrada' => $a->in_at?->toIso8601String(),
                'entrada_latitud' => $a->in_latitude !== null ? (float) $a->in_latitude : null,
                'entrada_longitud' => $a->in_longitude !== null ? (float) $a->in_longitude : null,
                'salida' => $a->out_at?->toIso8601String(),
                'salida_latitud' => $a->out_latitude !== null ? (float) $a->out_latitude : null,
                'salida_longitud' => $a->out_longitude !== null ? (float) $a->out_longitude : null,
                'tiempo_efectivo_segundos' => $a->total_seconds,
                'tiempo_ordinario_segundos' => $breakdown['ordinary_seconds'],
                'tiempo_extraordinario_segundos' => $breakdown['extra_seconds'],
                'metodo_registro' => $this->method($a),
                'fuera_de_zona' => $a->in_out_of_bounds || $a->out_out_of_bounds,
                'corregido' => (bool) $lastCorrection,
                'ultima_correccion' => $lastCorrection ? [
                    'por' => $lastCorrection->correctedBy?->name,
                    'fecha' => $lastCorrection->created_at->toIso8601String(),
                    'motivo' => $lastCorrection->reason,
                ] : null,
            ];
        })->values()->all();

        return [
            'empresa' => [
                'nombre' => (string) config('servalillo.company.name'),
                'cif' => (string) config('servalillo.company.tax_id'),
            ],
            'jornadas' => $jornadas,
        ];
    }

    /** @return array{ordinary_seconds: ?int, extra_seconds: ?int} */
    private function breakdownFor(Attendance $attendance): array
    {
        $weekStart = $attendance->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $cacheKey = $attendance->user_id.':'.$weekStart;

        if (! isset($this->weeklyBreakdownCache[$cacheKey])) {
            $weekEnd = $attendance->date->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();
            $days = $this->stats->dailyBreakdownWithHours($attendance->user_id, $weekStart, $weekEnd);
            $this->weeklyBreakdownCache[$cacheKey] = collect($days)->keyBy('date')->all();
        }

        $day = $this->weeklyBreakdownCache[$cacheKey][$attendance->date->toDateString()] ?? null;

        return [
            'ordinary_seconds' => $day['ordinary_seconds'] ?? null,
            'extra_seconds' => $day['extra_seconds'] ?? null,
        ];
    }

    private function coordsLabel(mixed $lat, mixed $lng): ?string
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        return number_format((float) $lat, 6).', '.number_format((float) $lng, 6);
    }

    private function method(Attendance $attendance): string
    {
        return $attendance->created_by === null || $attendance->created_by === $attendance->user_id
            ? 'web'
            : 'manual';
    }
}
