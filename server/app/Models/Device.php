<?php

namespace App\Models;

use App\Services\DevicePinHasher;
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
        'block_screen_capture', 'block_uninstall', 'block_factory_reset', 'block_safe_boot',
        'release_requested', 'reported_device_owner', 'reported_policies',
        'policies_reported_at', 'released_at',
    ];

    /** Restricciones del terminal (docs/24): columna => clave en la API y en `reported_policies`. */
    public const POLICIES = [
        'block_screen_capture' => 'block_screen_capture',
        'block_uninstall' => 'block_uninstall',
        'block_factory_reset' => 'block_factory_reset',
        'block_safe_boot' => 'block_safe_boot',
    ];

    protected $hidden = ['unlock_pin_hash', 'unlock_pin_salt'];

    // La clave nunca va a la auditoría; lo reportado cambia en cada sincronización (ruido).
    protected array $auditExclude = [
        'last_seen_at', 'unlock_pin_hash', 'unlock_pin_salt',
        'reported_device_owner', 'reported_policies', 'policies_reported_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
            'block_screen_capture' => 'boolean',
            'block_uninstall' => 'boolean',
            'block_factory_reset' => 'boolean',
            'block_safe_boot' => 'boolean',
            'release_requested' => 'boolean',
            'reported_device_owner' => 'boolean',
            'reported_policies' => 'array',
            'policies_reported_at' => 'datetime',
            'released_at' => 'datetime',
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
     * Restricciones que se piden desde la web para este terminal.
     *
     * @return array{block_screen_capture: bool, block_uninstall: bool, block_factory_reset: bool, block_safe_boot: bool}
     */
    public function desiredPolicies(): array
    {
        return collect(self::POLICIES)
            ->mapWithKeys(fn (string $key, string $column) => [$key => (bool) $this->{$column}])
            ->all();
    }

    public function hasUnlockPin(): bool
    {
        return filled($this->unlock_pin_hash) && filled($this->unlock_pin_salt);
    }

    public function setUnlockPin(string $pin): void
    {
        $hashed = app(DevicePinHasher::class)->make($pin);

        $this->forceFill([
            'unlock_pin_hash' => $hashed['hash'],
            'unlock_pin_salt' => $hashed['salt'],
        ]);
    }

    /**
     * Estado de la protección para el panel:
     * - `none`: no se pide nada ni se ha reportado administración.
     * - `unmanaged`: se piden restricciones, pero el terminal dice NO ser administrador (falta el alta por adb).
     * - `pending`: el terminal aún no ha confirmado lo último que se pidió (o una liberación).
     * - `protected`: lo reportado coincide con lo pedido y hay alguna restricción activa.
     * - `released`: liberado (ya no es administrador del dispositivo).
     */
    public function protectionState(): string
    {
        $wantsAny = in_array(true, $this->desiredPolicies(), true);

        if ($this->release_requested) {
            return 'pending';
        }

        if ($this->reported_device_owner === null) {
            return $wantsAny ? 'pending' : 'none';
        }

        if (! $this->reported_device_owner) {
            if ($this->released_at && ! $wantsAny) {
                return 'released';
            }

            return $wantsAny ? 'unmanaged' : 'none';
        }

        $reported = (array) ($this->reported_policies ?? []);
        foreach ($this->desiredPolicies() as $key => $wanted) {
            if ((bool) ($reported[$key] ?? false) !== $wanted) {
                return 'pending';
            }
        }

        return $wantsAny ? 'protected' : 'none';
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
