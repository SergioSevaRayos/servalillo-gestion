<?php

namespace App\Models;

use App\Enums\SupportCategory;
use App\Enums\SupportStatus;
use Database\Factories\SupportTicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Incidencia/necesidad que administración abre hacia mantenimiento (Bloque 12).
 * No es Auditable a propósito: el hilo de respuestas + softDeletes + la notificación
 * de cambio de estado ya dan la trazabilidad necesaria.
 */
class SupportTicket extends Model
{
    /** @use HasFactory<SupportTicketFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'subject', 'category', 'status', 'body', 'last_reply_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => SupportCategory::class,
            'status' => SupportStatus::class,
            'last_reply_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SupportTicketReply::class)->orderBy('created_at');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = "%{$term}%";

        return $query->where(fn ($q) => $q
            ->where('subject', 'ilike', $like)
            ->orWhere('body', 'ilike', $like)
            ->orWhereIn('user_id', User::query()->where('name', 'ilike', $like)->pluck('id')));
    }

    public function isOpen(): bool
    {
        return $this->status !== SupportStatus::Resuelto;
    }

    public function hasReplies(): bool
    {
        return $this->replies()->exists();
    }

    public function markReplied(?Carbon $at = null): void
    {
        $this->forceFill(['last_reply_at' => $at ?? now()])->save();
    }
}
