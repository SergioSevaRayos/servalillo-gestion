<?php

namespace App\Services;

use App\Models\Attendance;
use App\Support\Duration;
use Illuminate\Database\Eloquent\Collection;
use SimpleXMLElement;

/**
 * Bloque 18 (fichaje): genera la exportación en "formato legal" (un XML de registro de
 * jornada, pensado para una posible Inspección de Trabajo) y las filas para la vista
 * del PDF. Adapta el esquema del proyecto de referencia (/home/sergio/VSC/Fichajes)
 * sin las pausas (aquí no existen) — ver docs/05-fichaje.md.
 */
class AttendanceExportService
{
    /** @param  Collection<int, Attendance>  $attendances */
    public function toLegalXml(Collection $attendances): string
    {
        $root = new SimpleXMLElement('<RegistroJornada/>');

        $company = $root->addChild('Empresa');
        $company->addChild('Nombre', htmlspecialchars((string) config('servalillo.company.name')));
        $company->addChild('CIF', htmlspecialchars((string) config('servalillo.company.tax_id')));

        $records = $root->addChild('Jornadas');

        foreach ($attendances->loadMissing('user') as $attendance) {
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
            $jornada->addChild('TiempoEfectivo', $attendance->total_seconds !== null ? Duration::humanShort($attendance->total_seconds) : '');
            $jornada->addChild('MetodoRegistro', $this->method($attendance));
            $jornada->addChild('FueraDeZona', ($attendance->in_out_of_bounds || $attendance->out_out_of_bounds) ? 'si' : 'no');
        }

        return $root->asXML();
    }

    /** @param  Collection<int, Attendance>  $attendances
     * @return list<array{name: string, dni: ?string, date: string, in_at: ?string, in_coords: ?string, out_at: ?string, out_coords: ?string, hours: string, corrected: bool}> */
    public function toPdfRows(Collection $attendances): array
    {
        return $attendances->loadMissing('user')->map(fn (Attendance $a) => [
            'name' => $a->user->name,
            'dni' => $a->user->dni,
            'date' => $a->date->format('d/m/Y'),
            'in_at' => $a->in_at?->format('H:i'),
            'in_coords' => $this->coordsLabel($a->in_latitude, $a->in_longitude),
            'out_at' => $a->out_at?->format('H:i'),
            'out_coords' => $this->coordsLabel($a->out_latitude, $a->out_longitude),
            'hours' => $a->total_seconds !== null ? Duration::humanShort($a->total_seconds) : '—',
            'corrected' => $a->corrections()->exists(),
        ])->values()->all();
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
