<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de errores/excepciones para el panel de Mantenimiento.
 * No se audita (es en sí mismo un log).
 */
class ErrorLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'level', 'message', 'exception_class', 'file', 'line',
        'context', 'user_id', 'url', 'method', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'line' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
