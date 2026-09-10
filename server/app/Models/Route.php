<?php

namespace App\Models;

use App\Enums\ServiceKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Una ruta: camión + chofer, de reparto o de viajes, vigente entre `valid_from` y
 * `valid_until` (`null` = sin fin determinado) — "Sergio lleva el camión C-01". Es la fila
 * permanente que ve el usuario en `/rutas/listado`; NO tiene fecha propia. Cada día se le
 * genera sola su `RouteDay` (paradas, jornada, litros) mientras esté vigente
 * (`RecurringRouteService`, horizonte 14 días) — el histórico día a día se consulta desde
 * "Historial" (`App\Livewire\Routes\History`).
 */
class Route extends Model implements Auditable
{
    use AuditableTrait, HasFactory, SoftDeletes;

    protected $fillable = [
        'truck_id', 'driver_id', 'service_kind', 'name', 'notes',
        'valid_from', 'valid_until', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'service_kind' => ServiceKind::class,
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function routeDays(): HasMany
    {
        return $this->hasMany(RouteDay::class)->orderByDesc('route_date');
    }

    /**
     * ¿Hay ya otra ruta (activa, sin contar `$ignoreId`) para `$column` (truck_id|driver_id)
     * = `$id` cuyo rango [valid_from, valid_until ?? sin fin) se solapa con [$from, $until ?? sin fin)?
     */
    public static function overlaps(string $column, int $id, Carbon|string $from, Carbon|string|null $until, ?int $ignoreId = null): bool
    {
        $from = Carbon::parse($from)->toDateString();
        $until = $until ? Carbon::parse($until)->toDateString() : null;

        return static::query()
            ->where($column, $id)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $from))
            ->when($until, fn ($q) => $q->whereDate('valid_from', '<=', $until))
            ->exists();
    }
}
