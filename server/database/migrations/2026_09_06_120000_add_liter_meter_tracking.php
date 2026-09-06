<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contador de litros dispensados: como el cuentakilómetros, pero de litros que salen
 * por el equipo de reparto. Es independiente de la carga de la cisterna (el camión
 * puede rellenar o no durante el día).
 *
 * - `trucks.liter_meter`   — última lectura conocida del contador.
 * - `routes.liter_meter_*` — lectura al empezar y al terminar la jornada.
 * - `routes.liter_discrepancy_note` — motivo si (fin − inicio) no cuadra con lo repartido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trucks', function (Blueprint $table) {
            $table->unsignedInteger('liter_meter')->default(0)->after('odometer');
        });

        Schema::table('routes', function (Blueprint $table) {
            $table->unsignedInteger('liter_meter_start')->nullable()->after('completed_at');
            $table->unsignedInteger('liter_meter_end')->nullable()->after('liter_meter_start');
            $table->text('liter_discrepancy_note')->nullable()->after('liter_meter_end');
        });
    }

    public function down(): void
    {
        Schema::table('trucks', fn (Blueprint $table) => $table->dropColumn('liter_meter'));
        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn(['liter_meter_start', 'liter_meter_end', 'liter_discrepancy_note']);
        });
    }
};
