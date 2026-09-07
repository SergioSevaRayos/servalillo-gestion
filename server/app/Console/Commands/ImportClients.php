<?php

namespace App\Console\Commands;

use App\Services\ClientImporter;
use Illuminate\Console\Command;

class ImportClients extends Command
{
    protected $signature = 'clientes:importar {archivo : Ruta del CSV (relativa a la raíz del proyecto o absoluta)} {--dry-run : No escribe nada, solo informa}';

    protected $description = 'Importa clientes desde un CSV exportado del Access (upsert por código externo)';

    public function handle(ClientImporter $importer): int
    {
        $path = $this->argument('archivo');

        if (! is_file($path)) {
            $path = base_path($path);
        }

        if (! is_file($path)) {
            $this->error("No se encuentra el archivo: {$this->argument('archivo')}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Modo simulación: no se guardará nada.');
        }

        $result = $importer->import($path, $dryRun);

        $this->newLine();
        $this->info(($dryRun ? 'Se crearían ' : 'Creados: ').$result['created']);
        $this->info(($dryRun ? 'Se actualizarían ' : 'Actualizados: ').$result['updated']);

        if ($result['skipped'] > 0) {
            $this->warn("Omitidos: {$result['skipped']}");
        }

        foreach ($result['errors'] as $error) {
            $this->line("  <fg=yellow>·</> {$error}");
        }

        return self::SUCCESS;
    }
}
