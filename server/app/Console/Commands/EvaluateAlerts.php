<?php

namespace App\Console\Commands;

use App\Services\AlertEvaluator;
use Illuminate\Console\Command;

class EvaluateAlerts extends Command
{
    protected $signature = 'avisos:evaluar';

    protected $description = 'Evalúa los avisos de flota (sin señal GPS, batería, jornadas, velocidad) y notifica los nuevos.';

    public function handle(AlertEvaluator $evaluator): int
    {
        // Lock no bloqueante dentro de runGuarded(): si una pasada sigue en marcha, esta se salta.
        $summary = $evaluator->runGuarded() ?? [];

        foreach ($summary as $type => $counts) {
            if ($counts['opened'] || $counts['resolved']) {
                $this->line("{$type}: {$counts['opened']} nuevos, {$counts['resolved']} resueltos");
            }
        }

        return self::SUCCESS;
    }
}
