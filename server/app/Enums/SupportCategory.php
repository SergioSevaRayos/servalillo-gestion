<?php

namespace App\Enums;

/**
 * Tipo de incidencia que administración abre hacia mantenimiento (Bloque 12).
 */
enum SupportCategory: string
{
    case Fallo = 'fallo';
    case Necesidad = 'necesidad';
    case Consulta = 'consulta';

    public function label(): string
    {
        return match ($this) {
            self::Fallo => 'Fallo / error',
            self::Necesidad => 'Necesidad',
            self::Consulta => 'Consulta',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
