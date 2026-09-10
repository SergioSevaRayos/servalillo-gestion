<?php

namespace App\Support;

/**
 * Formateo corto de duraciones para la UI: "14 min", "1 h 05 min", "2 h".
 */
class Duration
{
    public static function humanShort(int $seconds): string
    {
        $minutes = (int) round(max(0, $seconds) / 60);

        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0
            ? $hours.' h'
            : sprintf('%d h %02d min', $hours, $rest);
    }
}
