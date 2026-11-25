<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Modo perdido (docs/24). Protege la información de la empresa si un móvil se pierde o lo roban:
 *
 * 1. Cierra YA todas las sesiones web del chofer asignado (y su "recordarme"): los datos de la
 *    empresa están en la web, no en el móvil, y esto no depende de que el móvil tenga conexión.
 * 2. Marca el móvil para que la APK, en su próxima sincronización, bloquee la pantalla (hace falta
 *    el PIN), muestre el mensaje en la pantalla de bloqueo y siga enviando posición también de noche.
 */
class LostDeviceService
{
    public const DEFAULT_MESSAGE = 'Este móvil pertenece a Aguas del Servalillo. Si lo encuentra, por favor llame a la empresa.';

    /** @return int nº de sesiones web cerradas */
    public function enable(Device $device, string $message): int
    {
        $device->forceFill([
            'lost_mode' => true,
            'lost_message' => $message,
            'lost_mode_at' => now(),
            // Un móvil desactivado para su servicio y no recibiría la orden de bloqueo.
            'is_active' => true,
        ])->save();

        return $this->endDriverSessions($device);
    }

    public function disable(Device $device): void
    {
        $device->forceFill([
            'lost_mode' => false,
            'lost_message' => null,
            'lost_mode_at' => null,
        ])->save();
    }

    /** El terminal ha confirmado que tiene el modo perdido aplicado. */
    public function confirmed(Device $device): bool
    {
        return $device->lost_mode && (bool) (($device->reported_policies ?? [])['lost_mode'] ?? false);
    }

    private function endDriverSessions(Device $device): int
    {
        $user = $device->driver?->user;
        if (! $user) {
            return 0;
        }

        // Invalida también la cookie "recordarme": si no, volvería a entrar sin contraseña.
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        // Sesiones en BD (SESSION_DRIVER=database en local y producción).
        $table = config('session.table', 'sessions');
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return DB::table($table)->where('user_id', $user->id)->delete();
    }
}
