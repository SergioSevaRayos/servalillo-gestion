<?php

namespace App\Enums;

enum DeliveryNoteStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Generating = 'generating';
    case Generated = 'generated';
    case Sent = 'sent';
    case DeliveredPhysically = 'delivered_physically';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Queued => 'En cola',
            self::Generating => 'Generando',
            self::Generated => 'Generado',
            self::Sent => 'Enviado',
            self::DeliveredPhysically => 'Entregado en mano',
            self::Failed => 'Error',
        };
    }

    /** El albarán ha llegado al cliente por algún canal. */
    public function isDelivered(): bool
    {
        return in_array($this, [self::Sent, self::DeliveredPhysically], true);
    }

    /** Variante de <x-ui.badge> para pintar este estado en los listados. */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Pending, self::Queued, self::Generating => 'neutral',
            self::Generated => 'primary',
            self::Sent, self::DeliveredPhysically => 'success',
            self::Failed => 'danger',
        };
    }
}
