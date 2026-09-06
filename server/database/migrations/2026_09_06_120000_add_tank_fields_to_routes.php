<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control de la cisterna por jornada (Bloque 7+): litros cargados al empezar, litros que
 * quedan al terminar, y el motivo si no cuadra con lo entregado a los clientes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->unsignedInteger('tank_loaded_liters')->nullable()->after('completed_at');
            $table->unsignedInteger('tank_remaining_liters')->nullable()->after('tank_loaded_liters');
            $table->text('tank_reconciliation_note')->nullable()->after('tank_remaining_liters');
        });
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn(['tank_loaded_liters', 'tank_remaining_liters', 'tank_reconciliation_note']);
        });
    }
};
