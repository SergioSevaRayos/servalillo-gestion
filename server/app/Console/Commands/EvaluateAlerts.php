<?php

namespace App\Console\Commands;

use App\Services\AlertEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class EvaluateAlerts extends Command
{
    protected $signature = 'avisos:evaluar';

    protected $description = 'Evalúa los avisos de flota (sin señal GPS, batería, jornadas, velocidad) y notifica los nuevos.';

    public function handle(AlertEvaluator $evaluator): int
    {
        // Lock no bloqueante: si una pasada anterior sigue en marcha, esta se salta sin más.
        $lock = Cache::lock('alerts:evaluate', 55);

        if (! $lock->get()) {
            return self::SUCCESS;
        }

        try {
            $summary = $evaluator->run();
        } finally {
            $lock->release();
        }

        foreach ($summary as $type => $counts) {
            if ($counts['opened'] || $counts['resolved']) {
                $this->line("{$type}: {$counts['opened']} nuevos, {$counts['resolved']} resueltos");
            }
        }

        return self::SUCCESS;
    }
}
