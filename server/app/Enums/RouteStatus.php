<?php

namespace App\Enums;

enum RouteStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Published => 'Publicada',
            self::InProgress => 'En curso',
            self::Completed => 'Completada',
            self::Cancelled => 'Cancelada',
        };
    }

    /** Rutas que el chofer ve como "activas" hoy. */
    public static function operational(): array
    {
        return [self::Published, self::InProgress];
    }

    /** Variante de <x-ui.badge> para pintar este estado en los listados. */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Published => 'primary',
            self::InProgress => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }
}
