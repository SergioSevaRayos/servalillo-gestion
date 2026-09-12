<?php

namespace App\Models;

use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Bloque 18 (fichaje): un tramo de jornada (entrada→salida) de una persona un día
 * concreto. `out_at` vacío = jornada abierta. Auditado (owen-it) como el resto de
 * modelos de negocio, en paralelo al ledger dedicado `AttendanceCorrection` — ver
 * docs/05-fichaje.md.
 */
#[Fillable([
    'user_id', 'date', 'in_at', 'out_at',
    'in_latitude', 'in_longitude', 'out_latitude', 'out_longitude',
    'in_out_of_bounds', 'out_out_of_bounds', 'total_seconds', 'created_by',
])]
class Attendance extends Model implements Auditable
{
    /** @use HasFactory<AttendanceFactory> */
    use AuditableTrait, HasFactory;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'in_at' => 'datetime',
            'out_at' => 'datetime',
            'in_latitude' => 'decimal:7',
            'in_longitude' => 'decimal:7',
            'out_latitude' => 'decimal:7',
            'out_longitude' => 'decimal:7',
            'in_out_of_bounds' => 'boolean',
            'out_out_of_bounds' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class)->latest('id');
    }

    public function isOpen(): bool
    {
        return $this->in_at !== null && $this->out_at === null;
    }
}
