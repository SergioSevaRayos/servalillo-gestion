<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla append-only de alto volumen: no se audita ni tiene updated_at.
 */
class GpsPosition extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'truck_id', 'device_id', 'driver_id', 'route_id',
        'latitude', 'longitude', 'accuracy_m', 'speed_mps', 'heading_deg',
        'battery_level', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy_m' => 'decimal:2',
            'speed_mps' => 'decimal:2',
            'heading_deg' => 'decimal:2',
            'battery_level' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
