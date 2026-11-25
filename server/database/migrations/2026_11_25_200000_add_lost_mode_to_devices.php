<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Modo perdido (docs/24): si avisan de robo o pérdida, administración o mantenimiento bloquean el
| móvil desde /dispositivos. La APK (Device Owner) bloquea la pantalla y muestra `lost_message` en
| la pantalla de bloqueo; lo aplicado de verdad llega en `reported_policies.lost_mode`.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('lost_mode')->default(false);
            $table->string('lost_message', 200)->nullable();
            $table->timestamp('lost_mode_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['lost_mode', 'lost_message', 'lost_mode_at']);
        });
    }
};
