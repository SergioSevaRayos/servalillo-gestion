<?php

namespace App\Enums;

/**
 * Naturaleza de una entrada del diario de incidencias del chofer (Bloque 14): para poder
 * distinguir de un vistazo lo positivo de lo negativo en el listado.
 */
enum DriverLogCategory: string
{
    case Positive = 'positive';
    case Negative = 'negative';
    case Neutral = 'neutral';

    public function label(): string
    {
        return match ($this) {
            self::Positive => 'Positiva',
            self::Negative => 'Negativa',
            self::Neutral => 'Neutra',
        };
    }

    /** Variante de <x-ui.badge> para pintar la categoría en el listado. */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Positive => 'success',
            self::Negative => 'danger',
            self::Neutral => 'neutral',
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
