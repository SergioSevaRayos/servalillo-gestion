<?php

namespace App\Models;

use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Route extends Model implements Auditable
{
    use AuditableTrait, HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'route_date', 'truck_id', 'driver_id', 'status',
        'name', 'notes', 'started_at', 'completed_at', 'created_by',
        'tank_loaded_liters', 'tank_remaining_liters', 'tank_reconciliation_note',
    ];

    protected function casts(): array
    {
        return [
            'route_date' => 'date',
            'status' => RouteStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'tank_loaded_liters' => 'integer',
            'tank_remaining_liters' => 'integer',
        ];
    }

    /** Litros ya entregados a clientes en esta ruta (paradas completadas). */
    public function deliveredLiters(): float
    {
        return (float) $this->stops
            ->where('status', RouteStopStatus::Completed)
            ->sum('delivered_quantity');
    }

    /** Litros que deberían quedar en la cisterna = cargado − entregado. */
    public function tankTheoreticalRemaining(): ?float
    {
        return $this->tank_loaded_liters === null
            ? null
            : $this->tank_loaded_liters - $this->deliveredLiters();
    }

    /**
     * Diferencia entre lo que debería quedar y lo medido al terminar.
     * >0 = falta producto (se perdió/derramó); <0 = sobra (raro). null si no hay datos.
     */
    public function tankDiscrepancy(): ?float
    {
        $theoretical = $this->tankTheoreticalRemaining();

        return ($theoretical === null || $this->tank_remaining_liters === null)
            ? null
            : $theoretical - $this->tank_remaining_liters;
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

    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class)->orderBy('position');
    }

    public function odometerReadings(): HasMany
    {
        return $this->hasMany(OdometerReading::class);
    }

    public function scopeForDate(Builder $query, mixed $date): Builder
    {
        return $query->whereDate('route_date', $date);
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            fn (RouteStatus $s) => $s->value,
            RouteStatus::operational(),
        ));
    }
}
