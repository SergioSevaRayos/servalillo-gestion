<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Bloque 10: el dispositivo (APK tracker) se enrola "en blanco" y el servicio técnico lo asigna
| a un CHOFER desde el panel. El camión y la ruta de cada posición se deducen de la ruta de ese
| chofer. Antes el device estaba atado 1:1 a un camión.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['truck_id']);
            $table->dropUnique(['truck_id']);
            $table->dropColumn('truck_id');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('driver_id')->nullable()->unique()->after('id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['driver_id']);
            $table->dropUnique(['driver_id']);
            $table->dropColumn('driver_id');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('truck_id')->nullable()->unique()->after('id')
                ->constrained()->cascadeOnDelete();
        });
    }
};
