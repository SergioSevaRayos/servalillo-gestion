<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Sello de la última vez que se recalculó el tiempo de permanencia en las paradas de este día
| (`StopDwellService`). Lo comparten el pase nocturno (`paradas:calcular-permanencia`) y el
| recálculo perezoso al abrir/refrescar el tablero, para no rehacer el trabajo en cada poll.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_days', function (Blueprint $table) {
            $table->timestamp('dwell_recalculated_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('route_days', function (Blueprint $table) {
            $table->dropColumn('dwell_recalculated_at');
        });
    }
};
