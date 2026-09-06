<?php

namespace App\Models;

use App\Enums\RouteStatus;
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
    ];

    protected function casts(): array
    {
        return [
            'route_date' => 'date',
            'status' => RouteStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
