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

/**
 * La actividad de una ruta permanente (`Route`) en un día concreto: paradas, jornada
 * (litros inicio/fin), estado. Antes esta tabla se llamaba `routes` y era "la ruta"; ahora
 * "la ruta" es la fila permanente en `routes` (camión+chofer, sin fecha) y esto es solo su
 * instancia de un día — se genera sola cada madrugada (`RecurringRouteService`, horizonte
 * 14 días) para cada `Route` vigente. `route_stops.route_id` sigue apuntando aquí sin cambios.
 */
class RouteDay extends Model implements Auditable
{
    use AuditableTrait, HasFactory, SoftDeletes;

    protected $table = 'route_days';

    protected $fillable = [
        'code', 'route_date', 'route_id', 'truck_id', 'driver_id', 'status', 'service_kind',
        'name', 'notes', 'started_at', 'completed_at', 'created_by',
        'liter_meter_start', 'liter_meter_end', 'liter_discrepancy_note',
    ];

    /** `dwell_recalculated_at` es un sello de proceso interno — no aporta nada a la auditoría. */
    protected array $auditExclude = ['dwell_recalculated_at'];

    protected function casts(): array
    {
        return [
            'route_date' => 'date',
            'status' => RouteStatus::class,
            'service_kind' => ServiceKind::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'dwell_recalculated_at' => 'datetime',
            'liter_meter_start' => 'integer',
            'liter_meter_end' => 'integer',
        ];
    }

    /** ¿Toca recalcular el tiempo de permanencia en las paradas de este día? */
    public function dwellIsStale(): bool
    {
        return $this->dwell_recalculated_at === null
            || $this->dwell_recalculated_at->lt(now()->subSeconds((int) config('servalillo.dwell.recompute_every_seconds')));
    }

    /**
     * Cambia el estado del día. Si **sale** de "Completada", deshace el cierre de jornada: borra
     * `completed_at` / `liter_meter_end` / la nota de descuadre y revierte `trucks.liter_meter` a
     * la lectura de inicio — para que si el chofer vuelve a operar, cierre con el contador bien.
     */
    public function changeStatus(RouteStatus $to): void
    {
        $undoClose = $this->status === RouteStatus::Completed && $to !== RouteStatus::Completed;

        $this->update([
            'status' => $to,
            ...($undoClose ? ['completed_at' => null, 'liter_meter_end' => null, 'liter_discrepancy_note' => null] : []),
        ]);

        if ($undoClose && $this->liter_meter_start !== null) {
            $this->truck?->update(['liter_meter' => $this->liter_meter_start]);
        }
    }

    /**
     * Si el día ya estaba cerrado (jornada terminada) y le llega una parada nueva — un cliente
     * que llama tarde, o la oficina que asigna algo desde "Sin asignar" — lo reabre para que el
     * chofer pueda hacer el reparto extra y volver a cerrar la jornada con la lectura correcta
     * del contador. Devuelve si realmente estaba cerrado (para avisar de que se ha reabierto).
     */
    public function reopenIfCompleted(): bool
    {
        if ($this->status !== RouteStatus::Completed) {
            return false;
        }

        $this->changeStatus(RouteStatus::InProgress);

        return true;
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

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
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
        return $this->hasMany(RouteStop::class, 'route_id')->orderBy('position');
    }

    public function odometerReadings(): HasMany
    {
        return $this->hasMany(OdometerReading::class, 'route_id');
    }

    /** Visitas a paradas (tiempo de permanencia) de todas las paradas de este día. */
    public function stopVisits(): HasMany
    {
        return $this->hasMany(StopVisit::class, 'route_id');
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
