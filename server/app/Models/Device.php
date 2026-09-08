<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Móvil de empresa con la APK "tracker" (Bloque 10). Se enrola "en blanco" contra
 * POST /api/device/register (token Sanctum con habilidad `gps:ingest`) y el servicio técnico
 * lo asigna a un chofer desde el panel de Mantenimiento. El camión y la ruta de cada posición
 * GPS se deducen de la ruta de ese chofer (ver App\Services\GpsIngestService).
 */
class Device extends Model implements Auditable
{
    use AuditableTrait, HasApiTokens, HasFactory;

    protected $fillable = [
        'driver_id', 'label', 'platform', 'install_identifier',
        'app_version', 'last_seen_at', 'is_active',
    ];

    protected array $auditExclude = ['last_seen_at'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(GpsPosition::class);
    }

    public function scopeUnassigned(Builder $query): void
    {
        $query->whereNull('driver_id');
    }

    /**
     * Config que la APK necesita para saber cada cuánto reportar. Se devuelve en el enrolamiento.
     *
     * @return array{ping_interval_seconds: int, ping_distance_meters: int, pause_start: string, pause_end: string}
     */
    public function trackingConfig(): array
    {
        $tracking = config('servalillo.tracking');

        return [
            'ping_interval_seconds' => (int) $tracking['ping_interval_seconds'],
            'ping_distance_meters' => (int) $tracking['ping_distance_meters'],
            'pause_start' => (string) $tracking['pause_start'],
            'pause_end' => (string) $tracking['pause_end'],
        ];
    }
}
