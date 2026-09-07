<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién reprogramó la parada (el chofer, desde su móvil). Sirve para que esa parada
 * le aparezca a ese chofer el día al que la movió aunque aún no esté en una ruta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->foreignId('rescheduled_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rescheduled_by');
        });
    }
};
