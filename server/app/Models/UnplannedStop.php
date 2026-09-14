<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un tramo en el que el camión estuvo parado (≥5 min por defecto) en un punto que NO es
 * ni una parada de la ruta ni la base. Dato derivado de `gps_positions` — lo reconstruye
 * `App\Services\StopDwellService` por día (borra + reinserta), no se audita. Visible SOLO
 * para administración/mantenimiento: `App\Services\RouteGeometry::payloadFor()` lo omite
 * salvo que se pida explícitamente (`includeUnplannedStops: true`), y el chofer nunca lo pide.
 *
 * `left_at` / `seconds` null = sigue abierta (el camión seguía ahí en la última posición conocida).
 *
 * `notified_at`: cuándo se avisó a administración por la campana (`App\Notifications\
 * UnplannedStopDetected`). Se conserva entre recálculos (StopDwellService::run() la
 * empareja con la fila anterior por `entered_at`, identidad estable de la parada) para no
 * volver a notificar la misma parada en cada recálculo — null = todavía sin avisar.
 */
class UnplannedStop extends Model
{
    protected $fillable = [
        'route_id', 'latitude', 'longitude', 'entered_at', 'left_at', 'seconds', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'entered_at' => 'datetime',
            'left_at' => 'datetime',
            'seconds' => 'integer',
            'notified_at' => 'datetime',
        ];
    }

    public function routeDay(): BelongsTo
    {
        return $this->belongsTo(RouteDay::class, 'route_id');
    }
}
