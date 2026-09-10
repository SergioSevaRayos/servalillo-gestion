<?php

namespace App\Console\Commands;

use App\Services\StopDwellService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RecomputeStopDwell extends Command
{
    protected $signature = 'paradas:calcular-permanencia
        {fecha? : Día concreto (YYYY-MM-DD). Sin argumento recalcula ayer y hoy.}';

    protected $description = 'Recalcula el tiempo de permanencia del camión en cada parada a partir del GPS';

    public function handle(StopDwellService $service): int
    {
        $fecha = $this->argument('fecha');

        $written = $fecha
            ? $service->recomputeForDate(Carbon::parse($fecha))
            : $service->recomputeRecent();

        $this->info($fecha
            ? "Recalculado {$fecha}: {$written} visitas."
            : "Recalculado ayer y hoy: {$written} visitas.");

        return self::SUCCESS;
    }
}
