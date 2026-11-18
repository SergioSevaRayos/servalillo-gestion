<?php

namespace App\Console\Commands;

use App\Models\AlertEvent;
use Illuminate\Console\Command;

class PurgeAlertEvents extends Command
{
    protected $signature = 'avisos:purgar
        {--dias= : Días de retención (por defecto config servalillo.alerts_retention_days).}';

    protected $description = 'Borra los avisos ya resueltos más antiguos que la ventana de retención (los activos nunca).';

    public function handle(): int
    {
        $days = (int) ($this->option('dias') ?: config('servalillo.alerts_retention_days'));
        $cutoff = now()->subDays($days);

        // Solo resueltos: un aviso abierto sigue vigente aunque lleve mucho tiempo así.
        $deleted = AlertEvent::query()->whereNotNull('resolved_at')->where('resolved_at', '<', $cutoff)->delete();

        $this->info("Borrados {$deleted} avisos resueltos antes de {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
