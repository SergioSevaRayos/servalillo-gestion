<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Asignación camión↔chofer, vigente entre `valid_from` y `valid_until` (`null` = sin fin
 * determinado). Es la "ruta permanente" que el usuario configura una vez; `RecurringRouteService`
 * la lee cada día para generar (o no, si ya existe) la `Route` de ese camión ese día.
 */
class TruckAssignment extends Model implements Auditable
{
    use AuditableTrait, HasFactory, SoftDeletes;

    protected $fillable = ['truck_id', 'driver_id', 'valid_from', 'valid_until', 'created_by'];

    protected function casts(): array
    {
        return [
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

    /**
     * ¿Hay ya otra asignación (activa, sin contar `$ignoreId`) para `$column` (truck_id|driver_id)
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
