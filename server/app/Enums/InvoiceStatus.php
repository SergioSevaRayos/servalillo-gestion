<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Pending = 'pending';
    case Issued = 'issued';
    case Sent = 'sent';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Issued => 'Emitida',
            self::Sent => 'Enviada',
        };
    }

    /** Variante de <x-ui.badge> para pintar este estado en los listados. */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Issued => 'primary',
            self::Sent => 'success',
        };
    }
}
