<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fila única de ajustes generales editables desde /mantenimiento/ajustes (petición del
| usuario, 2026-09-13), para poder cambiarlos sin tocar el .env ni desplegar:
| - Ubicación de la base (empresa) — pruebas iniciales fijándola en un domicilio
|   particular, o si la empresa se muda de verdad.
| - Tiempo mínimo para que una parada fuera de ruta cuente como "no programada".
| Si una columna es null, `AppServiceProvider::boot()` deja tal cual el valor de
| config/env (BASE_LATITUDE/BASE_LONGITUDE, DWELL_UNPLANNED_MIN_SECONDS).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('base_latitude', 10, 7)->nullable();
            $table->decimal('base_longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('unplanned_stop_minutes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
