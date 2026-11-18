<?php

namespace App\Enums;

/** Tipos de aviso configurables (/avisos). Cada uno usa un umbral numérico o una hora del día. */
enum AlertType: string
{
    case GpsSilence = 'gps_silence';
    case LowBattery = 'low_battery';
    case ShiftNotStarted = 'shift_not_started';
    case ShiftNotClosed = 'shift_not_closed';
    case Speeding = 'speeding';

    public function label(): string
    {
        return match ($this) {
            self::GpsSilence => 'Dispositivo sin señal GPS',
            self::LowBattery => 'Batería baja del móvil',
            self::ShiftNotStarted => 'Jornada sin empezar',
            self::ShiftNotClosed => 'Jornada sin cerrar',
            self::Speeding => 'Exceso de velocidad',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::GpsSilence => 'Un camión con ruta hoy lleva más de este tiempo sin enviar posición (solo dentro del horario de tracking).',
            self::LowBattery => 'La batería del móvil de un camión en ruta baja de este porcentaje.',
            self::ShiftNotStarted => 'Pasada esta hora, hay una ruta de hoy con paradas pendientes en la que el chofer aún no ha empezado la jornada.',
            self::ShiftNotClosed => 'Pasada esta hora, una jornada sigue en curso sin que el chofer la haya terminado.',
            self::Speeding => 'Dos lecturas GPS seguidas por encima de esta velocidad.',
        };
    }

    /** true = se configura con una hora (HH:MM); false = con un umbral numérico. */
    public function usesTime(): bool
    {
        return in_array($this, [self::ShiftNotStarted, self::ShiftNotClosed], true);
    }

    public function unit(): string
    {
        return match ($this) {
            self::GpsSilence => 'minutos',
            self::LowBattery => '%',
            self::Speeding => 'km/h',
            default => '',
        };
    }

    public function defaultThreshold(): ?int
    {
        return match ($this) {
            self::GpsSilence => 5,
            self::LowBattery => 15,
            self::Speeding => 110,
            default => null,
        };
    }

    public function defaultTime(): ?string
    {
        return match ($this) {
            self::ShiftNotStarted => '09:00',
            self::ShiftNotClosed => '21:00',
            default => null,
        };
    }

    /** @return array{0: int, 1: int}|null Rango válido del umbral. */
    public function thresholdRange(): ?array
    {
        return match ($this) {
            self::GpsSilence => [1, 240],
            self::LowBattery => [1, 90],
            self::Speeding => [30, 200],
            default => null,
        };
    }

    public function icon(): string
    {
        return $this === self::LowBattery ? 'wrench' : 'flag';
    }
}
