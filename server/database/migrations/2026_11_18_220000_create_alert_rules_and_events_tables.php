<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configuración de cada aviso (una fila por tipo): interruptor + umbral u hora.
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('threshold')->nullable();
            $table->string('time_of_day', 5)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Estado de cada aviso concreto (dato derivado, no auditado): abierto mientras se da la
        // condición. Permite avisar UNA vez al abrirse y otra al resolverse, no en cada pasada.
        Schema::create('alert_events', function (Blueprint $table) {
            $table->id();
            $table->string('rule_type');
            $table->string('subject_type', 20); // device | route_day
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label');
            $table->string('message');
            $table->string('url')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['rule_type', 'subject_type', 'subject_id', 'resolved_at'], 'alert_events_subject_idx');
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_events');
        Schema::dropIfExists('alert_rules');
    }
};
