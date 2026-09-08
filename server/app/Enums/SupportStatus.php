<?php

namespace App\Enums;

/**
 * Ciclo de vida de un ticket de soporte (Bloque 12). Lo hace avanzar mantenimiento.
 */
enum SupportStatus: string
{
    case Abierto = 'abierto';
    case EnCurso = 'en_curso';
    case Resuelto = 'resuelto';

    public function label(): string
    {
        return match ($this) {
            self::Abierto => 'Abierto',
            self::EnCurso => 'En curso',
            self::Resuelto => 'Resuelto',
        };
    }

    /** Variante de <x-ui.badge> para pintar este estado en los listados. */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Abierto => 'warning',
            self::EnCurso => 'primary',
            self::Resuelto => 'success',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->label()])
            ->all();
    }
}
