<?php

namespace App\Enums;

enum RouteStopStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Completed => 'Completada',
            self::Skipped => 'Cancelada',
            self::Failed => 'Fallida',
        };
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Skipped, self::Failed], true);
    }

    /** Variante de <x-ui.badge> para pintar este estado en los listados. */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Pending => 'neutral',
            self::Completed => 'success',
            self::Skipped => 'neutral',
            self::Failed => 'danger',
        };
    }
}
