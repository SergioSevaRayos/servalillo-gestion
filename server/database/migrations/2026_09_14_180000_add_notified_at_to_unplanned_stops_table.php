<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Notificar a administración cuando se detecta una parada no programada (petición del
| usuario, 2026-09-14). `unplanned_stops` es una tabla de datos derivados que
| StopDwellService::run() reconstruye entera por día (borra + reinserta) — sin esta marca,
| cada recálculo (cron nocturno, poll del tablero/chofer/historial) volvería a "descubrir"
| la misma parada y la notificaría una y otra vez. `notified_at` se conserva entre
| recálculos emparejando por `entered_at` (identidad estable de una parada no programada
| concreta) — ver StopDwellService::run().
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unplanned_stops', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('seconds');
        });
    }

    public function down(): void
    {
        Schema::table('unplanned_stops', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });
    }
};
