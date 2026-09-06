<?php

namespace App\Enums;

enum OdometerKind: string
{
    case Start = 'start';
    case End = 'end';

    public function label(): string
    {
        return match ($this) {
            self::Start => 'Inicio de jornada',
            self::End => 'Fin de jornada',
        };
    }
}
