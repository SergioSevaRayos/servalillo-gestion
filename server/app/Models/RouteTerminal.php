<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Un teléfono/navegador vinculado a una ruta permanente (ver App\Services\RouteTerminalPairingService).
 * Registro de uso, no dato de negocio a auditar — mismo criterio que GpsPosition/LoginLog.
 */
class RouteTerminal extends Model
{
    use HasFactory;

    protected $fillable = [
        'route_id', 'token', 'label', 'paired_at', 'last_used_at', 'created_by', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'paired_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public static function generateToken(): string
    {
        return Str::random(48);
    }
}
