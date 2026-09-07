<?php

namespace App\Models;

use App\Enums\RouteStopStatus;
use App\Enums\ServiceKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class RouteStop extends Model implements Auditable
{
    use AuditableTrait, HasFactory, SoftDeletes;

    protected $fillable = [
        'route_id', 'position', 'service_kind', 'customer_name', 'customer_tax_id', 'address',
        'latitude', 'longitude', 'contact_name', 'contact_phone', 'delivery_type_id',
        'status', 'scheduled_window_start', 'scheduled_window_end',
        'planned_quantity', 'delivered_quantity', 'completed_at', 'failure_reason', 'data',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'service_kind' => ServiceKind::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'status' => RouteStopStatus::class,
            'scheduled_window_start' => 'datetime',
            'scheduled_window_end' => 'datetime',
            'planned_quantity' => 'decimal:2',
            'delivered_quantity' => 'decimal:2',
            'completed_at' => 'datetime',
            'data' => 'array',
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function deliveryType(): BelongsTo
    {
        return $this->belongsTo(DeliveryType::class);
    }

    public function deliveryNote(): HasOne
    {
        return $this->hasOne(DeliveryNote::class);
    }

    /** Paradas del "backlog": creadas pero sin ruta/camión/chofer asignado todavía. */
    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('route_id');
    }
}
