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

    /**
     * Horas decimales para cálculos de nómina ("172,25 h"): coma decimal española, 2
     * decimales fijos. El segundo exacto solo se divide por 3600 aquí, en la última capa
     * de presentación — nunca se redondea antes (ver App\Services\AttendanceStatsService).
     */
    public static function decimalHours(int $seconds): string
    {
        $hours = max(0, $seconds) / 3600;

        return number_format($hours, 2, ',', '.').' h';
    }
}
