<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Protección del terminal del chofer (docs/24): la APK tracker es "Device Owner" de Android y
| aplica estas restricciones en todo el móvil. Aquí se guarda lo que se PIDE desde la web
| (block_*, release_requested, clave) y lo que el terminal REPORTA tener aplicado (reported_*).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('block_screen_capture')->default(false);
            $table->boolean('block_uninstall')->default(false);
            $table->boolean('block_factory_reset')->default(false);
            $table->boolean('block_safe_boot')->default(false);
            $table->boolean('release_requested')->default(false);

            // Clave para abrir la APK / desbloquear en el móvil. Solo el hash PBKDF2 + sal:
            // la APK la verifica sin conexión y nunca recibe la clave en claro.
            $table->string('unlock_pin_hash')->nullable();
            $table->string('unlock_pin_salt')->nullable();

            $table->boolean('reported_device_owner')->nullable();
            $table->jsonb('reported_policies')->nullable();
            $table->timestamp('policies_reported_at')->nullable();
            $table->timestamp('released_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'block_screen_capture', 'block_uninstall', 'block_factory_reset', 'block_safe_boot',
                'release_requested', 'unlock_pin_hash', 'unlock_pin_salt',
                'reported_device_owner', 'reported_policies', 'policies_reported_at', 'released_at',
            ]);
        });
    }
};
