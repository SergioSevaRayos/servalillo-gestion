<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Device extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $fillable = [
        'truck_id', 'label', 'platform', 'install_identifier',
        'app_version', 'last_seen_at', 'is_active',
    ];

    protected array $auditExclude = ['last_seen_at'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(GpsPosition::class);
    }
}
