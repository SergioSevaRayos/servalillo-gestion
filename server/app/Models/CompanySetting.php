<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Fila única de ajustes generales editables desde /mantenimiento/ajustes (Bloque 18,
 * 2026-09-13): ubicación de la base (empresa) y el tiempo mínimo de una parada no
 * programada. `App\Providers\AppServiceProvider::boot()` pisa `config('servalillo.base.*')`
 * / `config('servalillo.dwell.unplanned_stop_min_seconds')` con estos valores si no son
 * null; si nunca se ha guardado nada aquí, se queda con el .env
 * (BASE_LATITUDE/BASE_LONGITUDE, DWELL_UNPLANNED_MIN_SECONDS). Auditable: quién cambió
 * qué y cuándo interesa (afecta a "Ruta eficiente", la geovalla por defecto de fichaje y
 * la detección de paradas no programadas).
 */
class CompanySetting extends Model implements Auditable
{
    use AuditableTrait;

    protected $fillable = ['base_latitude', 'base_longitude', 'unplanned_stop_minutes'];

    protected function casts(): array
    {
        return [
            'base_latitude' => 'decimal:7',
            'base_longitude' => 'decimal:7',
            'unplanned_stop_minutes' => 'integer',
        ];
    }

    /** Fila única: la crea si todavía no existe ninguna. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }
}
