<?php

namespace App\Enums;

/**
 * Estado de un cliente en su ciclo de alta:
 * - `prospect` ("Pendiente valoración"): posible cliente apuntado por teléfono, aún sin valorar.
 * - `customer` ("Cliente"): cliente real, ya valorado y aprobado.
 * Descartar un prospecto lo borra definitivamente (no hay estado "descartado").
 */
enum ClientStatus: string
{
    case Prospect = 'prospect';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Prospect => 'Pendiente valoración',
            self::Customer => 'Cliente',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status) => [$status->value => $status->label()])
            ->all();
    }
}
