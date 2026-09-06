<?php

namespace App\Support\DeliveryChannels;

use App\Contracts\DeliveryChannel;
use App\Enums\DeliveryNoteStatus;
use App\Models\DeliveryNote;

class PhysicalChannel implements DeliveryChannel
{
    public function key(): string
    {
        return 'physical';
    }

    public function label(): string
    {
        return 'Entrega física (en mano)';
    }

    public function requiresPdf(): bool
    {
        // Se genera el PDF igualmente para archivo interno, pero no se envía nada.
        return true;
    }

    public function validationRules(): array
    {
        return [];
    }

    public function deliver(DeliveryNote $note): void
    {
        // No se envía nada digitalmente: solo queda registrado en el sistema.
        $note->forceFill([
            'status' => DeliveryNoteStatus::DeliveredPhysically,
            'delivered_at' => $note->delivered_at ?? now(),
        ])->save();
    }
}
