<?php

namespace App\Support\DeliveryChannels;

use App\Contracts\DeliveryChannel;
use App\Enums\DeliveryNoteStatus;
use App\Models\DeliveryNote;

class EmailChannel implements DeliveryChannel
{
    public function key(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Email';
    }

    public function requiresPdf(): bool
    {
        return true;
    }

    public function validationRules(): array
    {
        return [
            'recipient_email' => ['required', 'email'],
        ];
    }

    public function deliver(DeliveryNote $note): void
    {
        // Implementación real en el Bloque 8 (Mailable + adjunto PDF).
        // Aquí solo el contrato: envía el correo y marca el estado.
        // Mail::to($note->recipient_email)->send(new DeliveryNoteMail($note));

        $note->forceFill([
            'status' => DeliveryNoteStatus::Sent,
            'sent_at' => now(),
        ])->save();
    }
}
