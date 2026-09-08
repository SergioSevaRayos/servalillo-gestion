<?php

namespace App\Models;

use Database\Factories\SupportTicketReplyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Respuesta dentro del hilo de un ticket de soporte (Bloque 12). La escribe el
 * administrador creador o mantenimiento.
 */
class SupportTicketReply extends Model
{
    /** @use HasFactory<SupportTicketReplyFactory> */
    use HasFactory;

    protected $fillable = [
        'support_ticket_id', 'user_id', 'body',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
