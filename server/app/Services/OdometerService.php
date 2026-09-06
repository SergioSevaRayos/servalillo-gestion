<?php

namespace App\Services;

use App\Enums\OdometerKind;
use App\Models\OdometerReading;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lecturas de contador (odómetro) de inicio y fin de jornada.
 *
 * Regla de negocio: hay como mucho una lectura de cada tipo por ruta (índice único
 * `route_id + kind`). Al cerrar la jornada (`end`), el valor pasa a ser el odómetro
 * actual del camión.
 */
class OdometerService
{
    public function recordStart(Route $route, int $value): OdometerReading
    {
        $this->guardValue($value, $route->truck?->odometer);

        return OdometerReading::updateOrCreate(
            ['route_id' => $route->id, 'kind' => OdometerKind::Start->value],
            [
                'truck_id' => $route->truck_id,
                'driver_id' => $route->driver_id,
                'value' => $value,
                'recorded_at' => now(),
            ],
        );
    }

    public function recordEnd(Route $route, int $value): OdometerReading
    {
        $start = $route->odometerReadings()
            ->where('kind', OdometerKind::Start->value)
            ->value('value');

        if ($start !== null && $value < $start) {
            throw ValidationException::withMessages([
                'value' => "La lectura de fin ({$value}) no puede ser menor que la de inicio ({$start}).",
            ]);
        }

        return DB::transaction(function () use ($route, $value) {
            $reading = OdometerReading::updateOrCreate(
                ['route_id' => $route->id, 'kind' => OdometerKind::End->value],
                [
                    'truck_id' => $route->truck_id,
                    'driver_id' => $route->driver_id,
                    'value' => $value,
                    'recorded_at' => now(),
                ],
            );

            // El fin de jornada fija el odómetro conocido del camión.
            $route->truck?->update(['odometer' => $value]);

            return $reading;
        });
    }

    private function guardValue(int $value, ?int $truckOdometer): void
    {
        if ($value < 0) {
            throw ValidationException::withMessages(['value' => 'La lectura no puede ser negativa.']);
        }

        // El contador no retrocede: avisamos si la lectura de inicio es menor que el último valor conocido.
        if ($truckOdometer !== null && $value < $truckOdometer) {
            throw ValidationException::withMessages([
                'value' => "La lectura ({$value}) es menor que el último odómetro conocido del camión ({$truckOdometer}).",
            ]);
        }
    }
}
