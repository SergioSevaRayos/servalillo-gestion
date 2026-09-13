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

#[Fillable(['name', 'email', 'password', 'phone', 'is_active', 'theme_preference', 'dni', 'attendance_mode', 'attendance_latitude', 'attendance_longitude', 'attendance_radius_meters', 'weekly_contracted_hours'])]
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
            'attendance_latitude' => 'decimal:7',
            'attendance_longitude' => 'decimal:7',
        ];
    }

    public function driver(): HasOne
    {
        return $this->hasOne(Driver::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Solo administrador y chofer fichan — mantenimiento es el gestor técnico, no personal.
     * Excluye además a quien ficha con huella en la base a través del sistema externo
     * (`attendance_mode = 'external'`, ya implementado y sin relación con esta app):
     * esa persona no usa `/fichar` en absoluto, sus horas se llevan aparte.
     */
    public function canPunchAttendance(): bool
    {
        return ($this->hasRole('administrador') || $this->isDriver()) && $this->attendance_mode !== 'external';
    }

    /**
     * Horas semanales contratadas para desagregar ordinarias/extraordinarias en la
     * exportación legal — si la persona no tiene las suyas, se usa el umbral general.
     */
    public function effectiveWeeklyContractedHours(): float
    {
        return (float) ($this->weekly_contracted_hours ?? config('servalillo.attendance.default_weekly_hours'));
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
