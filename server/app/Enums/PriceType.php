<?php

namespace App\Enums;

/**
 * Cómo se factura al cliente:
 * - `fixed`: tarifa fija por servicio (importe cerrado).
 * - `per_liter`: precio por litro entregado.
 */
enum PriceType: string
{
    case Fixed = 'fixed';
    case PerLiter = 'per_liter';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Tarifa fija',
            self::PerLiter => 'Por litro',
        };
    }

    /** Sufijo para mostrar un importe: "45,00 €" (fija) / "0,9500 €/L" (por litro). */
    public function suffix(): string
    {
        return match ($this) {
            self::Fixed => ' €',
            self::PerLiter => ' €/L',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type) => [$type->value => $type->label()])
            ->all();
    }
}
