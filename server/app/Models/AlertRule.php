<?php

namespace App\Models;

use App\Enums\AlertType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/** Configuración de un tipo de aviso. Una fila por `AlertType`, creada con sus valores por defecto. */
class AlertRule extends Model implements Auditable
{
    use AuditableTrait;

    protected $fillable = ['type', 'enabled', 'threshold', 'time_of_day', 'device_ids', 'updated_by'];

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'enabled' => 'boolean',
            'threshold' => 'integer',
            'device_ids' => 'array',
        ];
    }

    /** ¿Este aviso vale para ese dispositivo? Sin selección (`device_ids` null) vale para todos. */
    public function appliesToDevice(int $deviceId): bool
    {
        return $this->device_ids === null || in_array($deviceId, array_map('intval', $this->device_ids), true);
    }

    /** Garantiza una fila por tipo (valores por defecto si faltan) y las devuelve indexadas por tipo. */
    public static function ensureDefaults(): Collection
    {
        $existing = static::query()->get()->keyBy(fn (self $r) => $r->type->value);

        foreach (AlertType::cases() as $type) {
            if (! $existing->has($type->value)) {
                $existing[$type->value] = static::create([
                    'type' => $type,
                    'enabled' => true,
                    'threshold' => $type->defaultThreshold(),
                    'time_of_day' => $type->defaultTime(),
                ]);
            }
        }

        return $existing;
    }

    public static function forType(AlertType $type): self
    {
        return static::ensureDefaults()->get($type->value);
    }
}
