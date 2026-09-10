<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Tiempo de permanencia del camión en cada parada (petición del usuario): a partir de las
| posiciones GPS y una geocerca (radio configurable, 100 m por defecto), se derivan los tramos
| en los que el camión estuvo "en" la parada — desde que entra en el radio hasta que sale.
|
| Es una tabla de DATOS DERIVADOS: `App\Services\StopDwellService` la reconstruye entera por
| día (borra + reinserta), así que es idempotente y aguanta lotes de GPS desordenados o de
| recuperación tras estar el móvil sin cobertura. No se audita (mismo criterio que
| `gps_positions` / `error_logs`).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stop_visits', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('route_stop_id')->constrained('route_stops')->cascadeOnDelete();
            $table->foreignId('route_id')->nullable()->constrained('route_days')->nullOnDelete();
            $table->timestamp('entered_at');
            $table->timestamp('left_at')->nullable(); // null = seguía dentro en la última posición conocida
            $table->unsignedInteger('seconds')->nullable(); // null mientras la visita está abierta
            $table->timestamps();

            $table->index('route_id');
            $table->index('route_stop_id');
            $table->index(['route_id', 'entered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stop_visits');
    }
};
