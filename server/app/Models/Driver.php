<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Driver extends Model implements Auditable
{
    use AuditableTrait, HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'employee_code', 'license_number', 'license_expiry',
        'phone', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'license_expiry' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TruckAssignment::class);
    }
}
