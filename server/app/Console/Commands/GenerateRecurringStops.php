<?php

namespace App\Console\Commands;

use App\Services\RecurringStopService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateRecurringStops extends Command
{
    protected $signature = 'rutas:generar-recurrentes
        {fecha? : Día concreto (YYYY-MM-DD). Sin argumento genera de hoy a +14 días.}';

    protected $description = 'Crea las paradas de los clientes con calendario de reparto por días de la semana.';

    public function handle(RecurringStopService $service): int
    {
        if ($fecha = $this->argument('fecha')) {
            $created = $service->generateForDate(Carbon::parse($fecha));
            $this->info("Fecha {$fecha}: {$created} paradas creadas.");

            return self::SUCCESS;
        }

        $created = $service->generateHorizon(14);
        $this->info("Hoy → +14 días: {$created} paradas creadas.");

        return self::SUCCESS;
    }
}
