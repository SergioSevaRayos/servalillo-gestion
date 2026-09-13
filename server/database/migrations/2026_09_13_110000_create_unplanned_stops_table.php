<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Paradas NO programadas (petición del usuario, 2026-09-13): tramos de al menos 5 min en
| los que el camión estuvo parado en un punto que no es ni una parada de la ruta ni la
| base — repostar por libre, un desvío, una avería... Mismo criterio que `stop_visits`:
| tabla de DATOS DERIVADOS, `App\Services\StopDwellService` la reconstruye entera por día
| (borra + reinserta), no se audita. Visible SOLO para administración/mantenimiento
| (`App\Services\RouteGeometry::payloadFor()` la omite salvo que se pida explícitamente).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unplanned_stops', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('route_id')->constrained('route_days')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamp('entered_at');
            $table->timestamp('left_at')->nullable(); // null = seguía ahí en la última posición conocida
            $table->unsignedInteger('seconds')->nullable(); // null mientras sigue abierta
            $table->timestamps();

            $table->index(['route_id', 'entered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unplanned_stops');
    }
};
