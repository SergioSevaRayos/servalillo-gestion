<?php

namespace App\Console\Commands;

use App\Models\GpsPosition;
use Illuminate\Console\Command;

class PurgeGpsPositions extends Command
{
    protected $signature = 'gps:purgar
        {--dias= : Días de retención (por defecto config servalillo.gps_retention_days).}';

    protected $description = 'Borra las posiciones GPS más antiguas que la ventana de retención.';

    public function handle(): int
    {
        $days = (int) ($this->option('dias') ?: config('servalillo.gps_retention_days'));
        $cutoff = now()->subDays($days);

        $total = 0;

        do {
            $deleted = GpsPosition::where('recorded_at', '<', $cutoff)->limit(5000)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        $this->info("Borradas {$total} posiciones GPS anteriores a {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
