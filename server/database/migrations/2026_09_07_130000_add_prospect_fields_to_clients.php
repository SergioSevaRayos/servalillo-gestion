<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-clientes ("Pendiente valoración") + datos de suministro (Bloque 9, ampliación).
 * Los prospectos viven en `clients` con `status = 'prospect'`; al aprobarlos pasan a
 * `'customer'`. Todas las filas actuales quedan como `'customer'` por el default.
 * `quantity_unit` recuerda la unidad citada por el cliente; `typical_quantity` siempre en litros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('status', 20)->default('customer')->index();
            $table->string('water_type', 20)->nullable();
            $table->string('quantity_unit', 4)->nullable(); // 'L' | 'm3'
            $table->unsignedInteger('tank_distance_m')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['status', 'water_type', 'quantity_unit', 'tank_distance_m']);
        });
    }
};
