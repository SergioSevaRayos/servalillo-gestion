<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Ventana de tracking GPS: la APK se pausa entre `pause_start` y `pause_end`
 * (config servalillo.tracking, p. ej. 22:00–05:00, cruza la medianoche).
 */
class TrackingWindow
{
    public static function isActive(?Carbon $at = null): bool
    {
        $at ??= now();
        $time = $at->format('H:i');
        $start = (string) config('servalillo.tracking.pause_start');
        $end = (string) config('servalillo.tracking.pause_end');

        // Pausa que cruza la medianoche (22:00–05:00) o dentro del mismo día (13:00–15:00).
        $paused = $start > $end
            ? ($time >= $start || $time < $end)
            : ($time >= $start && $time < $end);

        return ! $paused;
    }
}
