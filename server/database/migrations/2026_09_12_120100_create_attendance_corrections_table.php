<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 18 (fichaje): ledger de correcciones administrativas, de solo-inserción
 * (sin updated_at — ver App\Models\AttendanceCorrection) y con `reason` obligatorio.
 * Es la pieza que resuelve "controlado, motivado y anotado" (ver docs/05-fichaje.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('corrected_by')->constrained('users');
            $table->json('old_values');
            $table->json('new_values');
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
    }
};
