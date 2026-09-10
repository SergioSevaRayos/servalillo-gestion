<?php

namespace App\Console\Commands;

use App\Models\LoginLog;
use Illuminate\Console\Command;

class PurgeLoginLogs extends Command
{
    protected $signature = 'accesos:purgar
        {--dias= : Días de retención (por defecto config servalillo.login_log_retention_days).}';

    protected $description = 'Borra el registro de accesos (login_logs) más antiguo que la ventana de retención.';

    public function handle(): int
    {
        $days = (int) ($this->option('dias') ?: config('servalillo.login_log_retention_days'));
        $cutoff = now()->subDays($days);

        $deleted = LoginLog::where('logged_in_at', '<', $cutoff)->delete();

        $this->info("Borrados {$deleted} accesos anteriores a {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
