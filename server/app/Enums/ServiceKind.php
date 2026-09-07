<?php

namespace App\Enums;

/**
 * Tipo de servicio. Hoy solo se opera "Reparto"; "Viaje" se gestionará más adelante
 * (misma clasificación en clientes, rutas y paradas para poder separarlos ya).
 */
enum ServiceKind: string
{
    case Reparto = 'reparto';
    case Viaje = 'viaje';

    public function label(): string
    {
        return match ($this) {
            self::Reparto => 'Reparto',
            self::Viaje => 'Viaje',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Reparto => 'Repartos',
            self::Viaje => 'Viajes',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $kind) => [$kind->value => $kind->label()])
            ->all();
    }
}
