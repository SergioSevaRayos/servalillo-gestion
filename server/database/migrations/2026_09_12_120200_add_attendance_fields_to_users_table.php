<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 18 (fichaje): dónde puede fichar cada persona (base = la nave, remote = un
 * punto propio, p. ej. donde el chofer deja el camión aparcado) + su DNI, necesario
 * para la exportación en formato legal. Vive en `users`, no en `drivers`, porque
 * administrador también ficha y no tiene fila en `drivers` (ver docs/05-fichaje.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('attendance_mode')->default('base')->after('theme_preference');
            $table->decimal('attendance_latitude', 10, 7)->nullable()->after('attendance_mode');
            $table->decimal('attendance_longitude', 10, 7)->nullable()->after('attendance_latitude');
            $table->unsignedInteger('attendance_radius_meters')->nullable()->after('attendance_longitude');
            $table->string('dni')->nullable()->after('attendance_radius_meters');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['attendance_mode', 'attendance_latitude', 'attendance_longitude', 'attendance_radius_meters', 'dni']);
        });
    }
};
