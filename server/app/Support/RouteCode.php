<?php

namespace App\Support;

use App\Enums\ServiceKind;
use Illuminate\Support\Carbon;

/**
 * Código de una ruta: `{R|V}-{YYYYMMDD}-{código camión}`. Compartido entre `RouteForm`
 * (alta/edición manual) y `RecurringRouteService` (generación automática desde una
 * asignación camión↔chofer) para que no se desincronicen.
 */
class RouteCode
{
    public static function build(ServiceKind $kind, Carbon|string $date, string $truckCode): string
    {
        $prefix = $kind === ServiceKind::Viaje ? 'V-' : 'R-';
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        return $prefix.str_replace('-', '', $date).'-'.$truckCode;
    }
}
