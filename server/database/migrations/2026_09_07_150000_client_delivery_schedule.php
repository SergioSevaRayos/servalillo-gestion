<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendario de reparto por días de la semana + rango de fechas (Bloque 9, ampliación).
 * Un cliente puede tener: bajo demanda / días concretos (L·X·V…) con rango opcional /
 * cada N días (lo anterior, `frequency_days`). Los clientes con días concretos generan
 * su parada automáticamente en el tablero (`route_stops.scheduled_for` marca el día).
 * El producto siempre es agua → se elimina `clients.default_delivery_type_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->jsonb('delivery_weekdays')->nullable();   // ISO 1=lunes … 7=domingo
            $table->date('schedule_starts_on')->nullable();
            $table->date('schedule_ends_on')->nullable();
            $table->dropConstrainedForeignId('default_delivery_type_id');
        });

        Schema::table('route_stops', function (Blueprint $table) {
            $table->date('scheduled_for')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->dropColumn('scheduled_for');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['delivery_weekdays', 'schedule_starts_on', 'schedule_ends_on']);
            $table->foreignId('default_delivery_type_id')->nullable()->constrained('delivery_types')->nullOnDelete();
        });
    }
};
