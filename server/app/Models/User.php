<?php

namespace App\Models;

use App\Enums\ThemePreference;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'phone', 'is_active', 'theme_preference'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements Auditable
{
    /** @use HasFactory<UserFactory> */
    use AuditableTrait, HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /** Atributos que NO se auditan. */
    protected array $auditExclude = ['password', 'remember_token', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'theme_preference' => ThemePreference::class,
        ];
    }

    public function driver(): HasOne
    {
        return $this->hasOne(Driver::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function isDriver(): bool
    {
        return $this->hasRole('chofer');
    }

    /** Mantenimiento y administrador comparten el acceso completo de gestión. */
    public function isManager(): bool
    {
        return $this->hasAnyRole(['administrador', 'mantenimiento']);
    }

    /** Soporte técnico: único rol con acceso al panel de Mantenimiento (auditoría + logs). */
    public function isMaintenance(): bool
    {
        return $this->hasRole('mantenimiento');
    }
}
