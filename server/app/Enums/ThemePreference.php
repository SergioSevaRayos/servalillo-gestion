<?php

namespace App\Enums;

enum ThemePreference: string
{
    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';

    public function label(): string
    {
        return match ($this) {
            self::System => 'Automático',
            self::Light => 'Claro',
            self::Dark => 'Oscuro',
        };
    }
}
