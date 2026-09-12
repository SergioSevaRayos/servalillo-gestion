<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 18 (fichaje): un tramo de jornada por persona y día. `out_at` vacío = jornada
 * abierta. Índice único (user_id, date): como mucho una entrada y una salida al día
 * (decisión de alcance ya acordada, ver docs/05-fichaje.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->timestamp('in_at')->nullable();
            $table->timestamp('out_at')->nullable();
            $table->decimal('in_latitude', 10, 7)->nullable();
            $table->decimal('in_longitude', 10, 7)->nullable();
            $table->decimal('out_latitude', 10, 7)->nullable();
            $table->decimal('out_longitude', 10, 7)->nullable();
            $table->boolean('in_out_of_bounds')->default(false);
            $table->boolean('out_out_of_bounds')->default(false);
            $table->unsignedInteger('total_seconds')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
