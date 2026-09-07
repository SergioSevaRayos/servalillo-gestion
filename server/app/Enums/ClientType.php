<?php

namespace App\Enums;

enum ClientType: string
{
    case Particular = 'particular';
    case Empresa = 'empresa';
    case Comunidad = 'comunidad';
    case Agricola = 'agricola';
    case Industrial = 'industrial';
    case Obra = 'obra';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Particular => 'Particular',
            self::Empresa => 'Empresa',
            self::Comunidad => 'Comunidad de vecinos',
            self::Agricola => 'Explotación agrícola',
            self::Industrial => 'Industria',
            self::Obra => 'Obra / construcción',
            self::Otro => 'Otro',
        };
    }

    /** @return array<string, string> value => label, para <select> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
