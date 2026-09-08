<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Bloque 10: una posición GPS puede no tener camión (dispositivo sin chofer asignado, o chofer
| sin ruta ese día). El chofer/camión/ruta se resuelven best-effort en GpsIngestService.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gps_positions', function (Blueprint $table) {
            $table->foreignId('truck_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('gps_positions', function (Blueprint $table) {
            $table->foreignId('truck_id')->nullable(false)->change();
        });
    }
};
