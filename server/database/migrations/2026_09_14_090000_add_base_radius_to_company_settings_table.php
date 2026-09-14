<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Radio (metros) alrededor de la base dentro del cual el sistema NO cuenta una parada como "no
| programada" (petición del usuario, 2026-09-14: "el sistema tiene que ser inteligente para
| detectar si está en la base"). Ya existía como env fijo (DWELL_EXCLUDE_BASE_RADIUS_M,
| StopDwellService::filterPositions()) — se añade aquí para poder ajustarlo desde
| /mantenimiento/ajustes sin desplegar, igual que la ubicación de la base y el umbral de minutos.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('base_radius_meters')->nullable()->after('base_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('base_radius_meters');
        });
    }
};
