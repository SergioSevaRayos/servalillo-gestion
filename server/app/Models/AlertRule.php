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

    protected $fillable = ['type', 'enabled', 'threshold', 'time_of_day', 'updated_by'];

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'enabled' => 'boolean',
            'threshold' => 'integer',
        ];
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
