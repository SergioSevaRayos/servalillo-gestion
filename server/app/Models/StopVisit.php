<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un tramo en el que el camión estuvo dentro del radio de una parada (geocerca). Dato derivado
 * de `gps_positions` — lo reconstruye `App\Services\StopDwellService` por día. No se audita.
 *
 * `left_at` / `seconds` null = visita abierta (el camión seguía dentro en la última posición).
 */
class StopVisit extends Model
{
    use HasFactory;

    protected $fillable = [
        'route_stop_id', 'route_id', 'entered_at', 'left_at', 'seconds',
    ];

    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'left_at' => 'datetime',
            'seconds' => 'integer',
        ];
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'route_stop_id');
    }

    public function routeDay(): BelongsTo
    {
        return $this->belongsTo(RouteDay::class, 'route_id');
    }
}
