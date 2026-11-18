<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // null = el aviso vale para TODOS los dispositivos (comportamiento de siempre);
        // un array de ids = solo para esos dispositivos.
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->jsonb('device_ids')->nullable()->after('time_of_day');
        });
    }

    public function down(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropColumn('device_ids');
        });
    }
};
