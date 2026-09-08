<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla estándar de notificaciones de Laravel (Bloque 12). Alimenta la campana del nav:
 * cambios del chofer en su ruta -> administradores; actividad en un ticket de soporte ->
 * el otro lado. Canal `database` únicamente (sin mail, sin broadcast).
 *
 * PK `uuid` + `notifiable` polimórfico: obligatorio para `Illuminate\Notifications\
 * DatabaseNotification` (id no incremental, keyType string). Única desviación del
 * esquema por defecto: `data` es `jsonb` (Postgres) en vez de `text` — el modelo lo
 * castea a array igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->jsonb('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
