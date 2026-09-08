<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Truck extends Model implements Auditable
{
    use AuditableTrait, HasFactory, SoftDeletes;

    protected $fillable = [
        'plate', 'code', 'description', 'capacity_liters', 'compartments',
        'model', 'year', 'odometer', 'liter_meter', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'capacity_liters' => 'integer',
            'compartments' => 'integer',
            'year' => 'integer',
            'odometer' => 'integer',
            'liter_meter' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TruckAssignment::class);
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(TruckAssignment::class)
            ->whereNull('valid_until')
            ->latestOfMany();
    }
}
