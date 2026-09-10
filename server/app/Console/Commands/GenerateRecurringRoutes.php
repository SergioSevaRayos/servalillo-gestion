<?php

namespace App\Console\Commands;

use App\Services\RecurringRouteService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateRecurringRoutes extends Command
{
    protected $signature = 'rutas:generar-rutas
        {fecha? : Día concreto (YYYY-MM-DD). Sin argumento genera de hoy a +14 días.}';

    protected $description = 'Crea la ruta del día para cada asignación camión↔chofer vigente.';

    public function handle(RecurringRouteService $service): int
    {
        if ($fecha = $this->argument('fecha')) {
            $created = $service->generateForDate(Carbon::parse($fecha));
            $this->info("Fecha {$fecha}: {$created} rutas creadas.");

            return self::SUCCESS;
        }

        $created = $service->generateHorizon(14);
        $this->info("Hoy → +14 días: {$created} rutas creadas.");

        return self::SUCCESS;
    }
}
