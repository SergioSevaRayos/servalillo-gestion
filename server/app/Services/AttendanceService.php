<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\User;
use App\Support\Haversine;
use Illuminate\Support\Carbon;

/**
 * Bloque 18 (fichaje): único punto de la lógica de negocio — fichar, corregir, dar de
 * alta un fichaje olvidado, y resolver la geovalla de cada persona. Ver docs/05-fichaje.md
 * para el diseño completo (por qué una fila por día, por qué un ledger de correcciones
 * aparte, por qué nunca se bloquea un fichaje por falta de ubicación).
 */
class AttendanceService
{
    /**
     * Centro y radio dentro de los que se espera que fiche esta persona. Si su modo es
     * "remote" y tiene coordenadas propias, se usan esas (con su propio radio, o el de
     * la config si no lo tiene); si no, cae a la nave (config('servalillo.base')).
     *
     * @return array{lat: float, lng: float, radius: int}
     */
    public function effectiveGeofence(User $user): array
    {
        if ($user->attendance_mode === 'remote' && $user->attendance_latitude !== null && $user->attendance_longitude !== null) {
            return [
                'lat' => (float) $user->attendance_latitude,
                'lng' => (float) $user->attendance_longitude,
                'radius' => $user->attendance_radius_meters ?? (int) config('servalillo.attendance.default_radius_meters'),
            ];
        }

        return [
            'lat' => (float) config('servalillo.base.latitude'),
            'lng' => (float) config('servalillo.base.longitude'),
            'radius' => (int) config('servalillo.attendance.default_radius_meters'),
        ];
    }

    /** Sin coordenadas (permiso denegado, sin soporte…) nunca se marca fuera de zona. */
    private function isOutOfBounds(User $user, ?float $lat, ?float $lng): bool
    {
        if ($lat === null || $lng === null) {
            return false;
        }

        $geofence = $this->effectiveGeofence($user);

        return Haversine::meters($lat, $lng, $geofence['lat'], $geofence['lng']) > $geofence['radius'];
    }

    public function punchIn(User $user, ?float $lat, ?float $lng): Attendance
    {
        abort_unless($user->canPunchAttendance(), 403);

        $attendance = Attendance::firstOrNew(['user_id' => $user->id, 'date' => today()->toDateString()]);

        abort_if($attendance->exists && $attendance->in_at !== null, 422, 'Ya has fichado la entrada hoy.');

        $attendance->fill([
            'in_at' => now(),
            'in_latitude' => $lat,
            'in_longitude' => $lng,
            'in_out_of_bounds' => $this->isOutOfBounds($user, $lat, $lng),
            'created_by' => $attendance->created_by ?? $user->id,
        ]);
        $attendance->save();

        return $attendance;
    }

    public function punchOut(User $user, ?float $lat, ?float $lng): Attendance
    {
        abort_unless($user->canPunchAttendance(), 403);

        $attendance = Attendance::where('user_id', $user->id)->where('date', today()->toDateString())->first();

        abort_if(! $attendance || $attendance->in_at === null, 422, 'No has fichado la entrada hoy.');
        abort_if($attendance->out_at !== null, 422, 'Ya has fichado la salida hoy.');

        $outAt = now();

        $attendance->fill([
            'out_at' => $outAt,
            'out_latitude' => $lat,
            'out_longitude' => $lng,
            'out_out_of_bounds' => $this->isOutOfBounds($user, $lat, $lng),
            // diffInSeconds() puede devolver float por los microsegundos de now() (Carbon 3).
            'total_seconds' => (int) round($attendance->in_at->diffInSeconds($outAt)),
        ]);
        $attendance->save();

        return $attendance;
    }

    /**
     * Corrige una fila existente (motivo obligatorio, deja rastro en el ledger de
     * correcciones). $newValues admite 'in_at'/'out_at' (string parseable por Carbon o
     * null).
     */
    public function correct(Attendance $attendance, array $newValues, string $reason, User $correctedBy): Attendance
    {
        abort_if(trim($reason) === '', 422, 'El motivo es obligatorio.');

        $oldValues = [
            'in_at' => $attendance->in_at?->toDateTimeString(),
            'out_at' => $attendance->out_at?->toDateTimeString(),
        ];

        if (array_key_exists('in_at', $newValues)) {
            $attendance->in_at = $newValues['in_at'] ? Carbon::parse($newValues['in_at']) : null;
        }
        if (array_key_exists('out_at', $newValues)) {
            $attendance->out_at = $newValues['out_at'] ? Carbon::parse($newValues['out_at']) : null;
        }

        $attendance->total_seconds = $attendance->in_at && $attendance->out_at
            ? (int) round($attendance->in_at->diffInSeconds($attendance->out_at))
            : null;

        $attendance->save();

        AttendanceCorrection::create([
            'attendance_id' => $attendance->id,
            'corrected_by' => $correctedBy->id,
            'old_values' => $oldValues,
            'new_values' => [
                'in_at' => $attendance->in_at?->toDateTimeString(),
                'out_at' => $attendance->out_at?->toDateTimeString(),
            ],
            'reason' => $reason,
        ]);

        return $attendance;
    }

    /** Da de alta un fichaje olvidado del todo (ningún registro ese día). */
    public function createManual(User $target, string $date, ?string $inAt, ?string $outAt, string $reason, User $createdBy): Attendance
    {
        abort_if(trim($reason) === '', 422, 'El motivo es obligatorio.');
        abort_if(Attendance::where('user_id', $target->id)->where('date', $date)->exists(), 422, 'Esa persona ya tiene un fichaje ese día — corrígelo en vez de crear uno nuevo.');

        $in = $inAt ? Carbon::parse($inAt) : null;
        $out = $outAt ? Carbon::parse($outAt) : null;

        $attendance = Attendance::create([
            'user_id' => $target->id,
            'date' => $date,
            'in_at' => $in,
            'out_at' => $out,
            'total_seconds' => $in && $out ? (int) round($in->diffInSeconds($out)) : null,
            'created_by' => $createdBy->id,
        ]);

        AttendanceCorrection::create([
            'attendance_id' => $attendance->id,
            'corrected_by' => $createdBy->id,
            'old_values' => [],
            'new_values' => [
                'in_at' => $in?->toDateTimeString(),
                'out_at' => $out?->toDateTimeString(),
            ],
            'reason' => $reason,
        ]);

        return $attendance;
    }
}
