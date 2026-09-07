<?php

namespace App\Enums;

enum WaterType: string
{
    case Corriente = 'corriente';
    case Potable = 'potable';

    public function label(): string
    {
        return match ($this) {
            self::Corriente => 'Corriente',
            self::Potable => 'Potable',
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
