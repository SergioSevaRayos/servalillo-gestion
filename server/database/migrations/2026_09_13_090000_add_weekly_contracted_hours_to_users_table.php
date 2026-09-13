<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 18 (fichaje): horas semanales contratadas de cada persona, necesarias para
 * desagregar horas ordinarias/extraordinarias en la exportación legal (Proyecto de Ley
 * de reducción de jornada, art. 34 bis ET — ver docs/05-fichaje.md, sección "Auditoría
 * del formato de datos frente a la ley"). Nula = usa el umbral general de la empresa
 * (`config('servalillo.attendance.default_weekly_hours')`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('weekly_contracted_hours', 4, 1)->nullable()->after('dni');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('weekly_contracted_hours');
        });
    }
};
