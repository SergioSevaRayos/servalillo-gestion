<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Última posición de cada dispositivo" (mapa de flota, avisos) sin recorrer toda la tabla:
     * hasta ahora solo había índices por (truck_id, recorded_at) y por recorded_at, así que
     * buscar la última de un dispositivo parado hace días obligaba a leer hacia atrás por fecha.
     */
    public function up(): void
    {
        Schema::table('gps_positions', function (Blueprint $table) {
            $table->index(['device_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('gps_positions', function (Blueprint $table) {
            $table->dropIndex(['device_id', 'recorded_at']);
        });
    }
};
