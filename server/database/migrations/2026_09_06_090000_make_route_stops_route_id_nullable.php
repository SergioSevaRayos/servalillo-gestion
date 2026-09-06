<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El tablero Kanban tiene una columna fija "Sin asignar" para paradas que todavía no
 * pertenecen a ninguna ruta/camión/chofer (ver docs/03-ux-panel-rutas-kanban.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->dropForeign(['route_id']);
        });

        Schema::table('route_stops', function (Blueprint $table) {
            $table->foreignId('route_id')->nullable()->change();
        });

        Schema::table('route_stops', function (Blueprint $table) {
            $table->foreign('route_id')->references('id')->on('routes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->dropForeign(['route_id']);
        });

        Schema::table('route_stops', function (Blueprint $table) {
            $table->foreignId('route_id')->nullable(false)->change();
        });

        Schema::table('route_stops', function (Blueprint $table) {
            $table->foreign('route_id')->references('id')->on('routes')->cascadeOnDelete();
        });
    }
};
