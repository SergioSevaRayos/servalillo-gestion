<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un acceso correcto a la web (login), para el visualizador de Mantenimiento.
 * Append-only, no se audita (es en sí mismo un log) — mismo criterio que ErrorLog.
 */
class LoginLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'ip', 'user_agent', 'logged_in_at'];

    protected function casts(): array
    {
        return [
            'logged_in_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
