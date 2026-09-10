<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diario de incidencias del chofer (Bloque 14): notas de oficina sobre lo que hace cada chofer,
 * bueno o malo. `updated_by` solo se rellena al editar (queda `null` en la creación) para poder
 * distinguir "nunca tocada" de "editada por su propio autor".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->date('occurred_on');
            $table->string('category', 20)->default('neutral');
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['driver_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_logs');
    }
};
