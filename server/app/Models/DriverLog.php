<?php

namespace App\Models;

use App\Enums\DriverLogCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/** Una entrada del diario de incidencias de un chofer (Bloque 14). */
class DriverLog extends Model implements Auditable
{
    use AuditableTrait, HasFactory, SoftDeletes;

    protected $fillable = [
        'driver_id', 'occurred_on', 'category', 'body', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'category' => DriverLogCategory::class,
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Se ha tocado alguna vez después de crearla. */
    public function wasEdited(): bool
    {
        return $this->updated_by !== null;
    }
}
