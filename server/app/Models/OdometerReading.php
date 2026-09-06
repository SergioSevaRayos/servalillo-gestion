<?php

namespace App\Models;

use App\Enums\OdometerKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class OdometerReading extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $fillable = [
        'route_id', 'truck_id', 'driver_id', 'kind', 'value', 'recorded_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'kind' => OdometerKind::class,
            'value' => 'integer',
            'recorded_at' => 'datetime',
        ];
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
}
