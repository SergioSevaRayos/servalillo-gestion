<?php

namespace App\Support\DeliveryChannels;

use App\Contracts\DeliveryChannel;
use App\Enums\DeliveryNoteStatus;
use App\Mail\DeliveryNoteMail;
use App\Models\DeliveryNote;
use Illuminate\Support\Facades\Mail;

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
        Mail::to($note->recipient_email)->send(new DeliveryNoteMail($note));

        $note->forceFill([
            'status' => DeliveryNoteStatus::Sent,
            'sent_at' => now(),
        ])->save();
    }
}
