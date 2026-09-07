<?php

namespace App\Models;

use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Enums\ServiceKind;
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
        'code', 'route_date', 'truck_id', 'driver_id', 'status', 'service_kind',
        'name', 'notes', 'started_at', 'completed_at', 'created_by',
        'liter_meter_start', 'liter_meter_end', 'liter_discrepancy_note',
    ];

    protected function casts(): array
    {
        return [
            'route_date' => 'date',
            'status' => RouteStatus::class,
            'service_kind' => ServiceKind::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'liter_meter_start' => 'integer',
            'liter_meter_end' => 'integer',
        ];
    }

    /** Litros ya repartidos a clientes en esta ruta (paradas completadas). */
    public function deliveredLiters(): float
    {
        return (float) $this->stops
            ->where('status', RouteStopStatus::Completed)
            ->sum('delivered_quantity');
    }

    /** Por dónde debería ir el contador ahora mismo = lectura de inicio + litros repartidos. */
    public function literMeterExpected(): ?float
    {
        return $this->liter_meter_start === null
            ? null
            : $this->liter_meter_start + $this->deliveredLiters();
    }

    /**
     * (fin − inicio) − repartido. >0 = el contador marca más de lo repartido
     * (mermas/derrames); <0 = marca menos (raro). null si faltan datos.
     */
    public function literDiscrepancy(): ?float
    {
        return ($this->liter_meter_start === null || $this->liter_meter_end === null)
            ? null
            : ($this->liter_meter_end - $this->liter_meter_start) - $this->deliveredLiters();
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
